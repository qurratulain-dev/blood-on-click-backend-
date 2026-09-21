<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 18 - Admin UI account activation.
 *
 * The admin API already manages users.is_active (and the Blood Bank owner
 * account through the bank endpoints). These tests lock in that behaviour so
 * the UI controls built on top of it stay correct: admin can deactivate and
 * reactivate User and Blood Bank accounts, the listing exposes is_active, and
 * only the admin role can perform these operations. Step 17 enforcement is
 * re-verified across a full deactivate/reactivate cycle.
 */
class AdminAccountActivationTest extends TestCase
{
    use RefreshDatabase, ResetsAuthGuards;

    private const ADMIN_EMAIL = 'test@example.com';

    private const USER_EMAIL = 'user@example.com';

    private const BANK_EMAIL = 'bank@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Cache::flush();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => 'Ainaa*220',
        ])->json('token');
    }

    private function adminGet(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())->getJson($uri);
    }

    private function adminPut(string $uri, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())->putJson($uri, $data);
    }

    public function test_admin_can_deactivate_a_user_account(): void
    {
        $user = $this->user(self::USER_EMAIL);
        $this->assertTrue((bool) $user->is_active);

        $this->adminPut('/api/admin/users/' . $user->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'User updated successfully!')
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => 0]);
    }

    public function test_admin_can_reactivate_a_user_account(): void
    {
        $user = $this->user(self::USER_EMAIL);
        $user->update(['is_active' => false]);

        $this->adminPut('/api/admin/users/' . $user->id, ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => 1]);
    }

    public function test_deactivating_a_user_only_changes_is_active(): void
    {
        $user = $this->user(self::USER_EMAIL);
        $before = [
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'blood_group' => $user->blood_group,
            'email' => $user->email,
        ];

        $this->adminPut('/api/admin/users/' . $user->id, ['is_active' => false])
            ->assertOk();

        $user->refresh();
        $this->assertFalse((bool) $user->is_active);
        $this->assertSame($before['full_name'], $user->full_name);
        $this->assertSame($before['phone'], $user->phone);
        $this->assertSame($before['blood_group'], $user->blood_group);
        $this->assertSame($before['email'], $user->email);
    }

    public function test_admin_can_deactivate_a_blood_bank_owner_account(): void
    {
        $bank = $this->user(self::BANK_EMAIL)->bloodBank->first();
        $this->assertNotNull($bank);
        $this->assertTrue((bool) $bank->user->is_active);

        $this->adminPut('/api/admin/blood-banks/' . $bank->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Blood Bank updated successfully!');

        $this->assertDatabaseHas('users', ['id' => $bank->user_id, 'is_active' => 0]);

        $bank->refresh();
        $this->assertSame('active', $bank->status);
    }

    public function test_admin_can_reactivate_a_blood_bank_owner_account(): void
    {
        $bank = $this->user(self::BANK_EMAIL)->bloodBank->first();
        $bank->user->update(['is_active' => false]);

        $this->adminPut('/api/admin/blood-banks/' . $bank->id, ['is_active' => true])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $bank->user_id, 'is_active' => 1]);
    }

    public function test_admin_listing_returns_is_active_for_users_and_blood_banks(): void
    {
        $response = $this->adminGet('/api/admin/users')->assertOk();

        $userEntry = collect($response->json('users'))->firstWhere('email', self::USER_EMAIL);
        $bankEntry = collect($response->json('blood_banks'))->firstWhere('email', self::BANK_EMAIL);

        $this->assertNotNull($userEntry);
        $this->assertNotNull($bankEntry);
        $this->assertTrue($userEntry['is_active']);
        $this->assertTrue($bankEntry['is_active']);

        $this->user(self::USER_EMAIL)->update(['is_active' => false]);
        $this->user(self::BANK_EMAIL)->update(['is_active' => false]);

        $response = $this->adminGet('/api/admin/users')->assertOk();
        $userEntry = collect($response->json('users'))->firstWhere('email', self::USER_EMAIL);
        $bankEntry = collect($response->json('blood_banks'))->firstWhere('email', self::BANK_EMAIL);
        $this->assertFalse($userEntry['is_active']);
        $this->assertFalse($bankEntry['is_active']);
    }

    public function test_regular_user_cannot_deactivate_any_account(): void
    {
        $token = $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => 'Ainaa*220',
        ])->json('token');

        $bank = $this->user(self::BANK_EMAIL)->bloodBank->first();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/users/' . $this->user(self::USER_EMAIL)->id, ['is_active' => false])
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/blood-banks/' . $bank->id, ['is_active' => false])
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_blood_bank_user_cannot_deactivate_any_account(): void
    {
        $token = $this->postJson('/api/login', [
            'email' => self::BANK_EMAIL,
            'password' => 'Ainaa*220',
        ])->json('token');

        $bank = $this->user(self::BANK_EMAIL)->bloodBank->first();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/users/' . $this->user(self::USER_EMAIL)->id, ['is_active' => false])
            ->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/blood-banks/' . $bank->id, ['is_active' => false])
            ->assertStatus(403);
    }

    public function test_deactivated_user_is_blocked_until_reactivated(): void
    {
        $user = $this->user(self::USER_EMAIL);
        $this->adminPut('/api/admin/users/' . $user->id, ['is_active' => false])->assertOk();

        $this->postJson('/api/login', ['email' => self::USER_EMAIL, 'password' => 'Ainaa*220'])
            ->assertStatus(422);

        $this->user(self::USER_EMAIL)->update(['is_active' => true]);

        $token = $this->postJson('/api/login', ['email' => self::USER_EMAIL, 'password' => 'Ainaa*220'])
            ->assertOk()
            ->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertOk();
    }

    public function test_deactivated_blood_bank_owner_is_blocked_until_reactivated(): void
    {
        $bank = $this->user(self::BANK_EMAIL)->bloodBank->first();
        $this->adminPut('/api/admin/blood-banks/' . $bank->id, ['is_active' => false])->assertOk();

        $this->postJson('/api/login', ['email' => self::BANK_EMAIL, 'password' => 'Ainaa*220'])
            ->assertStatus(422);

        $this->adminPut('/api/admin/blood-banks/' . $bank->id, ['is_active' => true])->assertOk();

        $token = $this->postJson('/api/login', ['email' => self::BANK_EMAIL, 'password' => 'Ainaa*220'])
            ->assertOk()
            ->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/blood-bank/dashboard')
            ->assertOk();
    }
}