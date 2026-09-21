<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 6 - unified blood matching & recommendation flow.
 *
 * Matching is entirely donor/bank discovery over the single users table: an
 * available User with the requested blood group is a "donor" in that context,
 * not a separate role or account. Donors are found via /user/donors, banks via
 * /user/banks, and an admin can recommend a bank to a User via /admin/recommend-bank.
 * Recommendations reference user_id/bank_id only and live behind role:Admin, so a
 * regular user cannot spoof a recommendation for another account.
 */
class UnifiedSearchRecommendationFlowTest extends TestCase
{
    use RefreshDatabase, ResetsAuthGuards;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed();
    }

    private function loginAs(string $email): string
    {
        Auth::forgetGuards();
        $response = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'Ainaa*220',
        ]);
        $response->assertOk();
        return $response->json('token');
    }

    private function user(): User
    {
        return User::where('email', 'user@example.com')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'test@example.com')->first();
    }

    private function cityBank(): BloodBank
    {
        return BloodBank::where('bank_name', 'City Blood Bank')->firstOrFail();
    }

    private function makeUser(array $attrs = []): User
    {
        $user = User::create([
            'name' => $attrs['full_name'] ?? 'Test User',
            'username' => 'user_' . Str::lower(Str::random(8)),
            'email' => $attrs['email'] ?? Str::lower(Str::random(10)) . '@example.com',
            'password' => 'Ainaa*220',
            'is_active' => true,
            'full_name' => $attrs['full_name'] ?? 'Test User',
            'phone' => $attrs['phone'] ?? '03' . random_int(10000000, 99999999),
            'blood_group' => $attrs['blood_group'] ?? 'O+',
            'city' => $attrs['city'] ?? 'Karachi',
            'address' => $attrs['address'] ?? 'Some Address, Karachi',
            'age' => $attrs['age'] ?? 26,
            'gender' => $attrs['gender'] ?? 'Male',
            'weight' => $attrs['weight'] ?? 65.0,
            'status' => $attrs['status'] ?? 'available',
        ]);
        $user->assignRole('User');
        return $user;
    }

    private function makeBank(array $attrs = []): BloodBank
    {
        $bankUser = User::create([
            'name' => 'Bank Owner',
            'username' => 'bank_' . Str::lower(Str::random(8)),
            'email' => Str::lower(Str::random(10)) . '@example.com',
            'password' => 'password123',
            'is_active' => true,
        ]);

        return BloodBank::create([
            'user_id' => $bankUser->id,
            'bank_name' => $attrs['bank_name'] ?? 'Extra Blood Bank',
            'phone' => '03' . random_int(10000000, 99999999),
            'address' => $attrs['address'] ?? 'Some Main Road, Karachi',
            'registration_number' => 'BB-' . Str::upper(Str::random(6)),
            'status' => $attrs['status'] ?? 'active',
        ]);
    }

    private function stock(BloodBank $bank, string $group, int $quantity): void
    {
        BloodStock::updateOrCreate(
            ['bank_id' => $bank->id, 'blood_group' => $group],
            ['quantity' => $quantity]
        );
    }

    public function test_donor_search_matches_available_users_with_exact_blood_group(): void
    {
        $this->makeUser(['full_name' => 'Bilal Ahmed', 'blood_group' => 'A+']);
        $this->makeUser(['full_name' => 'Zara Iqbal', 'blood_group' => 'O+', 'status' => 'not_available']);

        $token = $this->loginAs('user@example.com');

        // O+: only the seeded available user returns; A+ and not_available excluded.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Ayesha Khan')
            ->assertJsonPath('donors.0.blood_group', 'O+');

        // A+: the available A+ user returns; seeded O+ user excluded.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=A%2B')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Bilal Ahmed');
    }

    public function test_donor_search_filters_by_city_and_address(): void
    {
        $this->makeUser(['full_name' => 'Hamza Ali', 'city' => 'Lahore', 'address' => 'Gulberg III, Lahore']);

        $token = $this->loginAs('user@example.com');

        // Karachi search returns only the seeded Karachi user.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B&city=Karachi')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Ayesha Khan');

        // Lahore search returns only the Lahore user via the city column...
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B&city=Lahore')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Hamza Ali');

        // ...and a district term also matches through the address column.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B&city=Gulberg')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Hamza Ali');
    }

    public function test_donor_search_requires_a_valid_blood_group(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?city=Karachi')
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_group');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=Z%2B')
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_group');
    }

    public function test_donor_search_prioritizes_least_recent_donors_first(): void
    {
        $bank = $this->cityBank();

        $old = $this->makeUser(['full_name' => 'Old Donor']);
        DonationHistory::create([
            'user_id' => $old->id,
            'bank_id' => $bank->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => '2023-01-10',
        ]);

        $recent = $this->makeUser(['full_name' => 'Recent Donor']);
        DonationHistory::create([
            'user_id' => $recent->id,
            'bank_id' => $bank->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => '2025-06-01',
        ]);

        $this->makeUser(['full_name' => 'Never Donated']);

        $token = $this->loginAs('user@example.com');

        $names = collect($this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->json('donors'))
            ->pluck('full_name')
            ->all();

        $this->assertContains('Old Donor', $names);
        $this->assertContains('Recent Donor', $names);
        $this->assertLessThan(
            array_search('Recent Donor', $names),
            array_search('Old Donor', $names),
            'Donors who donated longest ago should be listed before more recent donors.'
        );
    }

    public function test_bank_search_returns_only_active_banks_with_stock_for_group(): void
    {
        $closed = $this->makeBank(['bank_name' => 'Closed Bank', 'status' => 'inactive']);
        $this->stock($closed, 'O+', 20);

        $noStock = $this->makeBank(['bank_name' => 'NoStock Bank']);
        $this->stock($noStock, 'B-', 20);

        $extra = $this->makeBank(['bank_name' => 'Extra Bank']);
        $this->stock($extra, 'O+', 20);

        $token = $this->loginAs('user@example.com');

        // O+ search: City + Extra have positive O+ stock; Closed is inactive, NoStock lacks O+.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?blood_group=O%2B')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJson([
                ['bank_name' => 'City Blood Bank'],
                ['bank_name' => 'Extra Bank'],
            ])
            ->assertJsonMissing([
                'bank_name' => 'Closed Bank',
            ])
            ->assertJsonMissing([
                'bank_name' => 'NoStock Bank',
            ]);

        // B- search: City + NoStock carry B- stock; Extra and Closed do not qualify.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?blood_group=B-')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJson([
                ['bank_name' => 'City Blood Bank'],
                ['bank_name' => 'NoStock Bank'],
            ]);
    }

    public function test_bank_search_filters_by_name(): void
    {
        $this->makeBank(['bank_name' => 'Another Health Bank']);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?name=City')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'City Blood Bank');

        // Address terms are matched as well (456 Hospital Road).
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?name=Hospital')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'City Blood Bank');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?name=NoSuchBank')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_user_can_request_blood_tied_to_a_bank_found_in_search(): void
    {
        $token = $this->loginAs('user@example.com');

        $bank = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?blood_group=O%2B')
            ->assertOk()
            ->json('0');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank['id'],
                'blood_group' => 'O+',
                'quantity' => 1,
                'urgency' => 'emergency',
                'hospital_name' => 'City Hospital',
            ])
            ->assertStatus(201)
            ->assertJsonPath('request.user_id', $this->user()->id)
            ->assertJsonPath('request.bank_id', $bank['id']);

        $this->assertDatabaseHas('blood_requests', [
            'user_id' => $this->user()->id,
            'bank_id' => $bank['id'],
            'blood_group' => 'O+',
            'urgency' => 'emergency',
            'status' => 'pending',
        ]);

        // The chosen bank is notified through its blood_bank inbox.
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'blood_bank',
            'user_id' => $bank['id'],
            'title' => 'New Blood Request',
        ]);
    }

    public function test_admin_can_recommend_a_bank_to_a_user(): void
    {
        $target = $this->makeUser(['full_name' => 'Sana Ahmed']);

        $token = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/recommend-bank', [
                'user_id' => $target->id,
                'bank_id' => $this->cityBank()->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Recommendation sent successfully!');

        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $target->id,
            'title' => 'Blood Bank Recommendation',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Bank Recommendation',
        ]);
    }

    public function test_recommendation_cannot_be_spoofed_by_regular_roles(): void
    {
        $userToken = $this->loginAs('user@example.com');
        $bankToken = $this->loginAs('bank@example.com');

        $payload = ['user_id' => $this->admin()->id, 'bank_id' => $this->cityBank()->id];

        // A regular user cannot push a recommendation to another (admin) account.
        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->postJson('/api/admin/recommend-bank', $payload)
            ->assertStatus(403);

        // A blood bank cannot either.
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->postJson('/api/admin/recommend-bank', $payload)
            ->assertStatus(403);

        // The recommendation dropdown data is admin-only too.
        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->getJson('/api/admin/recommend-bank/data')
            ->assertStatus(403);

        $this->assertDatabaseMissing('notifications', [
            'title' => 'Blood Bank Recommendation',
        ]);
    }

    public function test_recommendation_data_lists_user_role_accounts_and_active_banks(): void
    {
        $this->makeUser(['full_name' => 'Sana Ahmed']);
        $closed = $this->makeBank(['bank_name' => 'Closed Bank', 'status' => 'inactive']);

        $token = $this->loginAs('test@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/recommend-bank/data')
            ->assertOk();

        $this->assertContains('Ayesha Khan', collect($response->json('users'))->pluck('full_name')->all());
        // Admin and blood bank accounts are not regular Users, so they are not recommend targets.
        $this->assertNotContains('Test Admin', collect($response->json('users'))->pluck('full_name')->all());
        $this->assertNotContains('City Blood Bank', collect($response->json('users'))->pluck('full_name')->all());

        $this->assertContains('City Blood Bank', collect($response->json('banks'))->pluck('bank_name')->all());
        $this->assertNotContains('Closed Bank', collect($response->json('banks'))->pluck('bank_name')->all());
        $this->assertTrue($closed->fresh()->status === 'inactive');
    }

    public function test_matching_works_without_donor_or_seeker_roles(): void
    {
        // No separate donor/seeker roles, tables, or columns anywhere.
        foreach (['donor_profiles', 'seeker_profiles', 'donor_accounts', 'seeker_accounts'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table: {$table}");
        }
        $this->assertFalse(Role::whereIn('name', ['Donor', 'Seeker'])->exists());

        // A brand-new account with only the User role acts as a requester...
        // (donor search includes the caller themselves, since they are an available O+ user)
        $searching = $this->makeUser(['full_name' => 'New Requester']);
        $token = $this->loginAs($searching->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonFragment(['full_name' => 'Ayesha Khan'])
            ->assertJsonFragment(['full_name' => 'New Requester']);

        // ...and is itself discoverable as a donor by a peer, with no Donor role.
        $peerToken = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $peerToken)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonFragment(['full_name' => 'New Requester']);
    }

    /**
     * STEP 10 - donor search exposes the minimum personal data.
     *
     * Browsing donors reveals only identity, blood group, city and availability.
     * Phone number is a disclosure action and is moved behind an explicit
     * /user/donors/{id}/contact endpoint; age/weight/gender/address and the
     * last-donation date are removed from search entirely. Filtering, sorting,
     * auth and the unified user architecture are unchanged.
     */

    private const SENSITIVE_KEYS = [
        'phone', 'address', 'age', 'weight', 'gender',
        'last_donation_date', 'last_donation_date_formatted',
    ];

    public function test_search_returns_minimal_profile_fields(): void
    {
        $donor = $this->makeUser([
            'full_name' => 'Priya Sharma',
            'blood_group' => 'B+',
            'email' => 'priya@example.com',
        ]);

        $token = $this->loginAs('user@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=B%2B')
            ->assertOk();

        $result = collect($response->json('donors'))->firstWhere('id', $donor->id);

        $this->assertNotNull($result, 'Donor should appear in B+ search.');
        $this->assertSame(['id', 'full_name', 'blood_group', 'city', 'status'], array_keys($result));
        $this->assertSame('Priya Sharma', $result['full_name']);
        $this->assertSame('B+', $result['blood_group']);
        $this->assertSame('Karachi', $result['city']);
        $this->assertSame('available', $result['status']);
    }

    public function test_search_never_leaks_contact_or_biometric_fields(): void
    {
        $donor = $this->makeUser([
            'full_name' => 'Imran Hassan',
            'blood_group' => 'AB+',
            'phone' => '03001234567',
        ]);

        $token = $this->loginAs('user@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=AB%2B')
            ->assertOk();

        $result = collect($response->json('donors'))->firstWhere('id', $donor->id);

        foreach (self::SENSITIVE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $result, "Donor search must not expose '{$key}'.");
        }

        // The response body must not contain the phone number anywhere at all.
        $response->assertJsonMissing(['phone' => '03001234567']);
    }

    public function test_search_no_longer_exposes_last_donation_date(): void
    {
        $bank = $this->cityBank();
        $donor = $this->makeUser(['full_name' => 'Date Leak Donor', 'blood_group' => 'O-']);
        DonationHistory::create([
            'user_id' => $donor->id,
            'bank_id' => $bank->id,
            'blood_group' => 'O-',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => '2024-03-15',
        ]);

        $token = $this->loginAs('user@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O-')
            ->assertOk();

        $result = collect($response->json('donors'))->firstWhere('id', $donor->id);

        $this->assertArrayNotHasKey('last_donation_date', $result);
        $this->assertArrayNotHasKey('last_donation_date_formatted', $result);
    }

    public function test_search_group_filter_and_availability_are_unchanged(): void
    {
        $this->makeUser(['full_name' => 'Available AB+', 'blood_group' => 'AB+']);
        $this->makeUser(['full_name' => 'Unavailable AB+', 'blood_group' => 'AB+', 'status' => 'not_available']);
        $this->makeUser(['full_name' => 'Wrong Group', 'blood_group' => 'A-']);

        $token = $this->loginAs('user@example.com');

        $names = collect($this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=AB%2B')
            ->assertOk()
            ->json('donors'))
            ->pluck('full_name')
            ->all();

        $this->assertContains('Available AB+', $names);
        $this->assertNotContains('Unavailable AB+', $names);
        $this->assertNotContains('Wrong Group', $names);
    }

    public function test_search_city_and_address_filter_are_unchanged(): void
    {
        $this->makeUser(['full_name' => 'Lahore Donor', 'city' => 'Lahore', 'address' => 'Gulberg III, Lahore']);

        $token = $this->loginAs('user@example.com');

        // City column match.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B&city=Lahore')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Lahore Donor');

        // Address column match (even though address is no longer returned).
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B&city=Gulberg')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Lahore Donor');
    }

    public function test_search_ordering_is_preserved_without_exposing_dates(): void
    {
        $bank = $this->cityBank();

        $old = $this->makeUser(['full_name' => 'Long Ago Donor', 'blood_group' => 'B-']);
        DonationHistory::create([
            'user_id' => $old->id,
            'bank_id' => $bank->id,
            'blood_group' => 'B-',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => '2022-01-01',
        ]);

        $new = $this->makeUser(['full_name' => 'Just Now Donor', 'blood_group' => 'B-']);
        DonationHistory::create([
            'user_id' => $new->id,
            'bank_id' => $bank->id,
            'blood_group' => 'B-',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => '2025-09-15',
        ]);

        $token = $this->loginAs('user@example.com');

        $donors = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=B-')
            ->assertOk()
            ->json('donors');

        $names = collect($donors)->pluck('full_name')->all();

        $this->assertLessThan(
            array_search('Just Now Donor', $names),
            array_search('Long Ago Donor', $names),
            'Least-recent donors must still be listed first.'
        );

        foreach ($donors as $donor) {
            $this->assertArrayNotHasKey('last_donation_date', $donor);
            $this->assertArrayNotHasKey('last_donation_date_formatted', $donor);
        }
    }

    public function test_phone_is_revealed_only_via_explicit_contact_action(): void
    {
        $donor = $this->makeUser([
            'full_name' => 'Rida Ashraf',
            'blood_group' => 'O+',
            'phone' => '03019998877',
        ]);

        $token = $this->loginAs('user@example.com');

        // Search listing: no phone anywhere.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonMissing(['phone' => '03019998877']);

        // Explicit contact action discloses it for that specific donor.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors/' . $donor->id . '/contact')
            ->assertOk()
            ->assertExactJson([
                'id' => $donor->id,
                'full_name' => 'Rida Ashraf',
                'phone' => '03019998877',
            ]);
    }

    public function test_contact_action_denies_unreachable_donors(): void
    {
        $hidden = $this->makeUser(['full_name' => 'Hidden Donor', 'status' => 'not_available']);

        $token = $this->loginAs('user@example.com');

        // A donor who cannot appear in search results cannot be contacted, nor a nonexistent id.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors/' . $hidden->id . '/contact')
            ->assertStatus(404);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors/999999/contact')
            ->assertStatus(404);
    }

    public function test_search_and_contact_require_authentication(): void
    {
        $this->getJson('/api/user/donors?blood_group=O%2B')->assertStatus(401);
        $this->getJson('/api/user/donors/1/contact')->assertStatus(401);
    }

    public function test_search_and_contact_are_user_role_only(): void
    {
        $donor = $this->makeUser(['full_name' => 'Lahore Donor']);

        $adminToken = $this->loginAs('test@example.com');
        $bankToken = $this->loginAs('bank@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/user/donors/' . $donor->id . '/contact')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/user/donors/' . $donor->id . '/contact')
            ->assertStatus(403);
    }

    public function test_minimal_projection_keeps_the_unified_architecture(): void
    {
        // No donor/seeker role or table has been reintroduced to support privacy.
        $this->assertFalse(Role::whereIn('name', ['Donor', 'Seeker'])->exists());
        foreach (['donor_profiles', 'seeker_profiles'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        // A peer donor is still discoverable as before, only with fewer fields.
        $peer = $this->makeUser(['full_name' => 'Peer Donor', 'blood_group' => 'A-', 'email' => 'peer@example.com']);
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=A-')
            ->assertOk()
            ->assertJsonFragment(['id' => $peer->id])
            ->assertJsonFragment(['full_name' => 'Peer Donor']);

        // Phone stays reachable for the peer through the contact action.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors/' . $peer->id . '/contact')
            ->assertOk();
    }

    /**
     * STEP 15 - Blood Bank search must honour the city filter.
     *
     * blood_banks has no dedicated city column, so a bank's location lives in
     * its address (as it does for the donor search). These tests pin the
     * behaviour the emergency search relies on: city actually filters results
     * and combines (AND) with the existing blood_group, name and status/
     * stock filters without bypassing any of them.
     */

    public function test_bank_search_filters_by_city(): void
    {
        $lahore = $this->makeBank(['bank_name' => 'Lahore Bank', 'address' => '123 Ferozepur Road, Lahore']);
        $this->stock($lahore, 'O+', 5);
        $quetta = $this->makeBank(['bank_name' => 'Quetta Bank', 'address' => '45 Jinnah Road, Quetta']);
        $this->stock($quetta, 'O+', 5);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=Lahore')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Lahore Bank');
    }

    public function test_bank_search_city_is_case_insensitive_and_matches_address(): void
    {
        $bank = $this->makeBank(['bank_name' => 'Gulberg Bank', 'address' => 'Gulberg III, Lahore']);

        $token = $this->loginAs('user@example.com');

        // Partial, lowercase city term.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=lahore')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Gulberg Bank');

        // A district term inside the address matches too.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=Gulberg')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Gulberg Bank');
    }

    public function test_bank_search_city_and_blood_group_are_combined(): void
    {
        $lahoreO = $this->makeBank(['bank_name' => 'Lahore O Bank', 'address' => 'Gulberg III, Lahore']);
        $this->stock($lahoreO, 'O+', 4);
        $lahoreA = $this->makeBank(['bank_name' => 'Lahore A Bank', 'address' => 'Model Town, Lahore']);
        $this->stock($lahoreA, 'A-', 6);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=Lahore&blood_group=A-')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Lahore A Bank');
    }

    public function test_bank_search_combines_city_with_name(): void
    {
        $match = $this->makeBank(['bank_name' => 'Lahore Care Bank', 'address' => 'Lahore Road, Lahore']);
        $this->makeBank(['bank_name' => 'Quetta Care Bank', 'address' => 'Quetta Road, Quetta']);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=Lahore&name=Care')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Lahore Care Bank');
    }

    public function test_bank_search_city_does_not_bypass_active_or_stock_filters(): void
    {
        $inactive = $this->makeBank(['bank_name' => 'Closed Lahore Bank', 'address' => 'Lahore Road, Lahore', 'status' => 'inactive']);
        $this->stock($inactive, 'O+', 10);

        $noStock = $this->makeBank(['bank_name' => 'Empty Lahore Bank', 'address' => 'Lahore Road, Lahore']);
        $this->stock($noStock, 'B-', 10);

        $ready = $this->makeBank(['bank_name' => 'Ready Lahore Bank', 'address' => 'Lahore Road, Lahore']);
        $this->stock($ready, 'O+', 10);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?city=Lahore&blood_group=O%2B')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.bank_name', 'Ready Lahore Bank');
    }

    public function test_bank_search_requires_authentication_and_user_role(): void
    {
        $this->getJson('/api/user/banks?city=Lahore')->assertStatus(401);

        $adminToken = $this->loginAs('test@example.com');
        $bankToken = $this->loginAs('bank@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/user/banks?city=Lahore')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/user/banks?city=Lahore')
            ->assertStatus(403);
    }
}