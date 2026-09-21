<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 3 - unified user donation + eligibility flow.
 *
 * A single "User" role performs every action: requesting an assessment,
 * donating, viewing donations/reports. Ownership is always the authenticated
 * user and eligibility is derived from existing assessment + 90-day data.
 */
class UnifiedDonationEligibilityTest extends TestCase
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

    public function test_donation_is_always_owned_by_the_authenticated_user(): void
    {
        $token = $this->loginAs('user@example.com');
        $admin = $this->admin();
        $user = $this->user();

        // An eligible assessment must exist before a donation is accepted.
        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'user_id' => $admin->id, // spoof attempt - must be ignored
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('donation.user_id', $user->id);

        $this->assertDatabaseHas('donation_history', [
            'user_id' => $user->id,
            'blood_group' => 'O+',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('donation_history', ['user_id' => $admin->id]);
    }

    public function test_eligibility_reflects_assessment_and_waiting_period(): void
    {
        $token = $this->loginAs('user@example.com');
        $user = $this->user();

        // No assessment yet.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'not_assessed')
            ->assertJsonPath('can_donate', FALSE)
            ->assertJsonPath('latest_assessment', NULL);

        // Admin clears the user as eligible.
        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('can_donate', TRUE)
            ->assertJsonPath('latest_assessment.is_eligible', TRUE);

        // A recent completed donation starts the 90-day waiting period.
        $donationDate = now()->subDays(10);
        DonationHistory::create([
            'user_id' => $user->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => $donationDate,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'waiting_period')
            ->assertJsonPath('can_donate', FALSE)
            ->assertJsonPath('next_eligible_date', $donationDate->copy()->addDays(90)->toDateString());

        // Once the 90 days have elapsed the user is eligible again.
        DonationHistory::where('user_id', $user->id)->update([
            'donation_date' => now()->subDays(100),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('can_donate', TRUE);

        // An ineligible assessment always wins over the interval.
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->addDay()->toDateString(),
                'is_eligible' => FALSE,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'not_eligible')
            ->assertJsonPath('can_donate', FALSE);
    }

    public function test_assessment_request_notifies_admin_once(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertJsonPath('assessment_requested', FALSE);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/assessment-requests')
            ->assertStatus(201)
            ->assertJsonPath('requested', TRUE);

        $this->assertDatabaseHas('notifications', [
            'user_type' => 'admin',
            'title' => 'Medical Assessment Requested',
        ]);

        $countAfterFirstRequest = Notification::where('title', 'Medical Assessment Requested')->count();
        $this->assertGreaterThan(0, $countAfterFirstRequest);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertJsonPath('assessment_requested', TRUE);

        // Second request is de-duplicated - no new notifications are created.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/assessment-requests')
            ->assertOk()
            ->assertJsonPath('requested', TRUE);

        $this->assertSame(
            $countAfterFirstRequest,
            Notification::where('title', 'Medical Assessment Requested')->count()
        );
    }

    public function test_eligibility_only_returns_the_authenticated_users_data(): void
    {
        $user = $this->user();
        $other = $this->admin();

        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/assessments', [
                'user_id' => $other->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => FALSE,
                'notes' => 'Admin only note',
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('user@example.com'))
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'not_assessed')
            ->assertJsonPath('latest_assessment', NULL);
    }

    public function test_admin_can_assess_and_verify_donation_for_a_plain_user(): void
    {
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');
        $user = $this->user();
        $bankId = BloodBank::first()->id;
        $stockBefore = (int)BloodStock::where('bank_id', $bankId)->where('blood_group', 'O+')->value('quantity');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $donationId = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => $bankId,
                'blood_group' => 'O+',
                'quantity' => 2,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201)->json('donation.id');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/donations/' . $donationId . '/verify', ['admin_message' => 'Verified'])
            ->assertOk();

        $this->assertDatabaseHas('donation_history', ['id' => $donationId, 'status' => 'completed']);
        $this->assertSame(
            $stockBefore + 2,
            (int)BloodStock::where('bank_id', $bankId)->where('blood_group', 'O+')->value('quantity')
        );
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $user->id,
            'title' => 'Donation Verified',
        ]);
    }

    public function test_user_donations_and_reports_are_scoped_to_self(): void
    {
        $user = $this->user();
        $other = $this->admin();

        DonationHistory::create([
            'user_id' => $other->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'A+',
            'quantity' => 1,
            'status' => 'pending',
            'donation_date' => now(),
        ]);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donations')
            ->assertOk()
            ->assertJsonCount(0);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/medical-reports')
            ->assertOk()
            ->assertJsonCount(0);

        // A user cannot upload a report against someone else's donation.
        $otherDonation = DonationHistory::where('user_id', $other->id)->first();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations/' . $otherDonation->id . '/upload-report')
            ->assertStatus(403);

        $this->assertFalse($user->id === $otherDonation->user_id);
    }

    public function test_blood_bank_donation_verification_flow_is_admin_only(): void
    {
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $this->user()->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $donationId = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201)->json('donation.id');

        // Blood bank cannot verify donations.
        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->postJson('/api/admin/donations/' . $donationId . '/verify')
            ->assertStatus(403);

        // A plain user cannot verify either.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/donations/' . $donationId . '/verify')
            ->assertStatus(403);

        $this->assertDatabaseHas('donation_history', ['id' => $donationId, 'status' => 'pending']);
    }

    // ==================== SERVER-SIDE ELIGIBILITY ENFORCEMENT ====================
    // STEP 8 - POST /api/user/donations must apply the same eligibility rules as
    // GET /api/user/eligibility and must never trust a client-supplied user_id.

    private function makeUser(string $email, string $bloodGroup = 'O+'): User
    {
        return User::create([
            'name' => 'Extra User',
            'username' => $email,
            'email' => $email,
            'password' => bcrypt('Ainaa*220'),
            'full_name' => 'Extra User',
            'blood_group' => $bloodGroup,
            'status' => 'available',
        ])->assignRole('User');
    }

    public function test_user_without_assessment_cannot_create_donation(): void
    {
        $token = $this->loginAs('user@example.com');
        $user = $this->user();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'not_assessed')
            ->assertJsonPath('eligibility.can_donate', FALSE);

        $this->assertDatabaseMissing('donation_history', ['user_id' => $user->id]);
    }

    public function test_user_with_pending_assessment_request_cannot_create_donation(): void
    {
        $token = $this->loginAs('user@example.com');
        $user = $this->user();

        // Request an assessment; until admin records one the user stays not_assessed.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/assessment-requests')
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('assessment_requested', TRUE)
            ->assertJsonPath('can_donate', FALSE);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'not_assessed');

        $this->assertDatabaseMissing('donation_history', ['user_id' => $user->id]);
    }

    public function test_user_with_ineligible_assessment_cannot_create_donation(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => FALSE,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'not_eligible')
            ->assertJsonPath('eligibility.can_donate', FALSE);

        $this->assertDatabaseMissing('donation_history', ['user_id' => $user->id]);
    }

    public function test_user_inside_waiting_period_cannot_create_donation(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $donationDate = now()->subDays(10);
        DonationHistory::create([
            'user_id' => $user->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => $donationDate,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'waiting_period')
            ->assertJsonPath('eligibility.can_donate', FALSE)
            ->assertJsonPath('eligibility.next_eligible_date', $donationDate->copy()->addDays(90)->toDateString());

        $this->assertSame(1, DonationHistory::where('user_id', $user->id)->count());
    }

    public function test_eligible_user_can_create_donation_as_pending(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('donation.user_id', $user->id)
            ->assertJsonPath('donation.status', 'pending');
    }

    public function test_client_cannot_bypass_eligibility_using_user_id(): void
    {
        $user = $this->user();
        $other = $this->makeUser('eligible@example.com');
        $adminToken = $this->loginAs('test@example.com');

        // The spoof target is fully eligible.
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $other->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        // The authenticated (unassessed) user cannot piggyback on that state.
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('user@example.com'))
            ->postJson('/api/user/donations', [
                'user_id' => $other->id,
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'not_assessed');

        $this->assertDatabaseMissing('donation_history', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('donation_history', ['user_id' => $other->id]);
    }

    public function test_user_cannot_create_donation_for_another_user(): void
    {
        $user = $this->user();
        $other = $this->makeUser('eligible@example.com');
        $adminToken = $this->loginAs('test@example.com');

        foreach ([$user, $other] as $u) {
            $this->withHeader('Authorization', 'Bearer ' . $adminToken)
                ->postJson('/api/admin/assessments', [
                    'user_id' => $u->id,
                    'assessment_date' => now()->toDateString(),
                    'is_eligible' => TRUE,
                ])
                ->assertStatus(201);
        }

        // Spoofing another user's id never credits them - ownership stays with
        // the authenticated user.
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('user@example.com'))
            ->postJson('/api/user/donations', [
                'user_id' => $other->id,
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('donation.user_id', $user->id);

        $this->assertDatabaseMissing('donation_history', ['user_id' => $other->id]);
    }

    public function test_rejected_donation_does_not_affect_eligibility(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        // A rejected record must not start the 90-day window.
        DonationHistory::create([
            'user_id' => $user->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'rejected',
            'donation_date' => now()->subDays(5),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('next_eligible_date', NULL);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201);
    }

    public function test_pending_donation_does_not_affect_the_90_day_rule(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $payload = [
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'donation_date' => now()->toDateString(),
        ];

        // The first donation sits in pending - it must not start the interval.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', $payload)
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('next_eligible_date', NULL);

        // A second donation is still accepted.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', $payload)
            ->assertStatus(201);

        $this->assertSame(2, DonationHistory::where('user_id', $user->id)->count());
    }

    public function test_completed_donation_affects_next_eligibility_date(): void
    {
        $user = $this->user();
        $token = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('test@example.com');
        $bankId = BloodBank::first()->id;

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/assessments', [
                'user_id' => $user->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);

        $donationId = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => $bankId,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201)->json('donation.id');

        // Once verified as completed the user enters the waiting period.
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/donations/' . $donationId . '/verify', ['admin_message' => 'Verified'])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'waiting_period')
            ->assertJsonPath('can_donate', FALSE)
            ->assertJsonPath('next_eligible_date', now()->copy()->addDays(90)->toDateString());

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => $bankId,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('eligibility.status', 'waiting_period');

        $this->assertSame(1, DonationHistory::where('user_id', $user->id)->count());

        // After the interval elapses the user is eligible again.
        DonationHistory::where('user_id', $user->id)->update([
            'donation_date' => now()->subDays(100),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => $bankId,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame(2, DonationHistory::where('user_id', $user->id)->count());
    }

    // ==================== ADMIN DONATION REJECTION ====================
    // STEP 11 - the reject endpoint must write the existing 'rejected' status
    // (the DonationHistory enum stores pending/approved/rejected/completed),
    // only from pending, without contacting stock, and keep notification +
    // reason behavior intact.

    private function createPendingDonation(int $quantity = 1, string $bloodGroup = 'O+'): DonationHistory
    {
        return DonationHistory::create([
            'user_id' => $this->user()->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => $bloodGroup,
            'quantity' => $quantity,
            'status' => 'pending',
            'donation_date' => now(),
        ]);
    }

    private function makeEligible(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/assessments', [
                'user_id' => $this->user()->id,
                'assessment_date' => now()->toDateString(),
                'is_eligible' => TRUE,
            ])
            ->assertStatus(201);
    }

    private function rejectAsAdmin(int $donationId, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/donations/' . $donationId . '/reject', $payload);
    }

    public function test_admin_can_reject_a_pending_donation(): void
    {
        $this->makeEligible();
        $token = $this->loginAs('user@example.com');
        $donationId = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201)->json('donation.id');

        $this->assertDatabaseHas('donation_history', ['id' => $donationId, 'status' => 'pending']);

        $this->rejectAsAdmin($donationId)
            ->assertOk()
            ->assertJsonPath('message', 'Donation rejected successfully!');

        $this->assertDatabaseHas('donation_history', ['id' => $donationId, 'status' => 'rejected']);
    }

    public function test_rejection_is_persisted_without_database_enum_error(): void
    {
        $donation = $this->createPendingDonation();

        // The whole request must complete - a stale 'cancelled' value would
        // fail at the DB enum level and never reach 'rejected'.
        $this->rejectAsAdmin($donation->id)->assertOk();

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'rejected']);
        $this->assertDatabaseMissing('donation_history', ['id' => $donation->id, 'status' => 'cancelled']);
    }

    public function test_rejection_reason_is_preserved(): void
    {
        $donation = $this->createPendingDonation();

        $this->rejectAsAdmin($donation->id, ['admin_message' => 'Missing health certificate'])
            ->assertOk();

        $this->assertDatabaseHas('donation_history', [
            'id' => $donation->id,
            'status' => 'rejected',
            'admin_message' => 'Missing health certificate',
        ]);
    }

    public function test_rejected_donation_sends_user_notification(): void
    {
        $donation = $this->createPendingDonation();

        $this->rejectAsAdmin($donation->id)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Donation Rejected',
        ]);
    }

    public function test_rejected_donation_does_not_increase_blood_stock(): void
    {
        $bankId = BloodBank::first()->id;
        $stockBefore = (int) BloodStock::where('bank_id', $bankId)->where('blood_group', 'O+')->value('quantity');

        $donation = $this->createPendingDonation(3);
        $this->rejectAsAdmin($donation->id)->assertOk();

        $this->assertSame(
            $stockBefore,
            (int) BloodStock::where('bank_id', $bankId)->where('blood_group', 'O+')->value('quantity')
        );
    }

    public function test_rejected_donation_does_not_start_waiting_period(): void
    {
        $this->makeEligible();
        $token = $this->loginAs('user@example.com');
        $donationId = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201)->json('donation.id');

        $this->rejectAsAdmin($donationId)->assertOk();

        // Eligibility only counts completed donations, so the user stays eligible.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/eligibility')
            ->assertOk()
            ->assertJsonPath('status', 'eligible')
            ->assertJsonPath('can_donate', TRUE)
            ->assertJsonPath('next_eligible_date', NULL);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])
            ->assertStatus(201);
    }

    public function test_only_pending_donations_can_be_rejected(): void
    {
        $completed = DonationHistory::create([
            'user_id' => $this->user()->id,
            'bank_id' => BloodBank::first()->id,
            'blood_group' => 'O+',
            'quantity' => 1,
            'status' => 'completed',
            'donation_date' => now(),
        ]);
        $rejected = $this->createPendingDonation();

        $this->rejectAsAdmin($rejected->id)->assertOk();

        // completed -> rejected is refused and completed is preserved.
        $this->rejectAsAdmin($completed->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending donations can be rejected');
        $this->assertDatabaseHas('donation_history', ['id' => $completed->id, 'status' => 'completed']);

        // rejected -> rejected is refused and stays rejected.
        $this->rejectAsAdmin($rejected->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending donations can be rejected');
        $this->assertDatabaseHas('donation_history', ['id' => $rejected->id, 'status' => 'rejected']);
    }

    public function test_donation_rejection_is_admin_only(): void
    {
        $donation = $this->createPendingDonation();

        $userToken = $this->loginAs('user@example.com');
        $bankToken = $this->loginAs('bank@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->postJson('/api/admin/donations/' . $donation->id . '/reject')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->postJson('/api/admin/donations/' . $donation->id . '/reject')
            ->assertStatus(403);

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'pending']);
    }

    public function test_pending_to_completed_lifecycle_still_works_after_rejection_guard(): void
    {
        $donation = $this->createPendingDonation(2);
        $bank = BloodBank::first();
        $stockBefore = (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        // Pending -> completed verification still works.
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/donations/' . $donation->id . '/verify', ['admin_message' => 'Verified'])
            ->assertOk();

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'completed']);
        $this->assertSame(
            $stockBefore + 2,
            (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );

        // A completed donation is no longer rejectable.
        $this->rejectAsAdmin($donation->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending donations can be rejected');
        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'completed']);
    }

    // ==================== MOVE BACK TO PENDING ====================
    // STEP 12 - reopen only rejected donations. Completed donations have
    // already added their units to stock, so moving one back to pending and
    // verifying again would double-count blood stock; that path is closed.

    private function pendingAsAdmin(int $donationId): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/donations/' . $donationId . '/pending');
    }

    public function test_rejected_donation_can_be_moved_back_to_pending_for_review(): void
    {
        $donation = $this->createPendingDonation();

        $this->rejectAsAdmin($donation->id)->assertOk();
        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'rejected']);

        $this->pendingAsAdmin($donation->id)
            ->assertOk()
            ->assertJsonPath('message', 'Donation moved to pending!');

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'pending']);
    }

    public function test_move_back_to_pending_is_admin_only(): void
    {
        $donation = $this->createPendingDonation();
        $this->rejectAsAdmin($donation->id)->assertOk();

        $userToken = $this->loginAs('user@example.com');
        $bankToken = $this->loginAs('bank@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->postJson('/api/admin/donations/' . $donation->id . '/pending')
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->postJson('/api/admin/donations/' . $donation->id . '/pending')
            ->assertStatus(403);

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'rejected']);
    }

    public function test_completed_donation_cannot_be_moved_back_to_pending(): void
    {
        $bank = BloodBank::first();
        $stockBefore = (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        $donation = $this->createPendingDonation(2);
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/donations/' . $donation->id . '/verify')
            ->assertOk();
        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'completed']);

        // The completed donation must not be reopened...
        $this->pendingAsAdmin($donation->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only rejected donations can be moved back to pending.');

        // ...so stock and status stay untouched and re-verification is impossible.
        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'completed']);
        $this->assertSame(
            $stockBefore + 2,
            (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );
    }

    public function test_pending_donation_cannot_be_moved_to_pending(): void
    {
        $donation = $this->createPendingDonation();

        $this->pendingAsAdmin($donation->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only rejected donations can be moved back to pending.');

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'pending']);
    }

    public function test_rejected_to_pending_to_verified_adds_stock_exactly_once(): void
    {
        $bank = BloodBank::first();
        $stockBefore = (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        $donation = $this->createPendingDonation(1);

        // reject -> pending -> verify: the exact churn that used to double-count.
        $this->rejectAsAdmin($donation->id)->assertOk();
        $this->pendingAsAdmin($donation->id)->assertOk();
        $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->postJson('/api/admin/donations/' . $donation->id . '/verify')
            ->assertOk();

        $this->assertDatabaseHas('donation_history', ['id' => $donation->id, 'status' => 'completed']);
        $this->assertSame(
            $stockBefore + 1,
            (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );

        // And it cannot be churned again afterwards.
        $this->pendingAsAdmin($donation->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only rejected donations can be moved back to pending.');
        $this->assertEquals(
            $stockBefore + 1,
            (int) BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );
    }
}