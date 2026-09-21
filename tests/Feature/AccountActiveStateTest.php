<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 17 - accounts are enforced through users.is_active.
 *
 * Inactive accounts (User, Blood Bank and Admin) must not authenticate or use
 * protected APIs, including with an already-issued Sanctum token. The check is
 * central (middleware on every auth:sanctum route + a guard in the login
 * controller) and returns the same generic failure as bad credentials so the
 * endpoint does not reveal whether an account merely exists. Admin management
 * of an inactive account (viewing/reactivating) stays available.
 */
class AccountActiveStateTest extends TestCase
{
    use RefreshDatabase, ResetsAuthGuards;

    private const LOGIN_LIMIT = 5;

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

    public function test_active_user_can_log_in(): void
    {
        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'Ainaa*220'])
            ->assertOk()
            ->assertJsonPath('role', 'User')
            ->assertJsonStructure(['token']);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->user('user@example.com')->update(['is_active' => false]);

        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'Ainaa*220'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_active_blood_bank_can_log_in(): void
    {
        $this->postJson('/api/login', ['email' => 'bank@example.com', 'password' => 'Ainaa*220'])
            ->assertOk()
            ->assertJsonPath('role', 'Blood Bank');
    }

    public function test_inactive_blood_bank_cannot_log_in(): void
    {
        $this->user('bank@example.com')->update(['is_active' => false]);

        $this->postJson('/api/login', ['email' => 'bank@example.com', 'password' => 'Ainaa*220'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_active_admin_can_log_in(): void
    {
        $this->postJson('/api/login', ['email' => 'test@example.com', 'password' => 'Ainaa*220'])
            ->assertOk()
            ->assertJsonPath('role', 'Admin');
    }

    public function test_inactive_admin_cannot_log_in(): void
    {
        $this->user('test@example.com')->update(['is_active' => false]);

        $this->postJson('/api/login', ['email' => 'test@example.com', 'password' => 'Ainaa*220'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_deactivated_token_cannot_access_protected_endpoint(): void
    {
        $token = $this->loginAs('user@example.com');
        $this->user('user@example.com')->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertStatus(403);
    }

    public function test_deactivated_token_cannot_perform_a_mutation(): void
    {
        $token = $this->loginAs('user@example.com');
        $this->user('user@example.com')->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'urgency' => 'normal',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_admin_can_still_view_an_inactive_account(): void
    {
        $this->user('user@example.com')->update(['is_active' => false]);

        $adminToken = $this->loginAs('test@example.com');

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/users')
            ->assertOk();

        $mapped = collect($response->json('users'))->firstWhere('email', 'user@example.com');

        $this->assertNotNull($mapped);
        $this->assertFalse($mapped['is_active']);
    }

    public function test_admin_can_reactivate_an_inactive_account(): void
    {
        $this->user('user@example.com')->update(['is_active' => false]);

        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/users/' . $this->user('user@example.com')->id, ['is_active' => true])
            ->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'user@example.com', 'is_active' => 1]);
    }

    public function test_reactivated_account_can_log_in_and_access_apis(): void
    {
        $user = $this->user('user@example.com');
        $user->update(['is_active' => false]);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/users/' . $user->id, ['is_active' => true])
            ->assertOk();

        $token = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertOk();
    }

    public function test_login_rate_limiting_still_applies(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }
}