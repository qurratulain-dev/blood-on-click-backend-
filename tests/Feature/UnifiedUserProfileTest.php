<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 5 - unified user profile & account information.
 *
 * There is exactly ONE User profile: identity + blood information lives on
 * the users table. Ownership is always the authenticated user; the server
 * resolves the target profile from auth, never from a client-supplied id.
 * No donor or seeker profile is created.
 */
class UnifiedUserProfileTest extends TestCase
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

    public function test_user_can_view_own_profile(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/profile')
            ->assertOk()
            ->assertJsonPath('id', $this->user()->id)
            ->assertJsonPath('full_name', 'Ayesha Khan')
            ->assertJsonPath('email', 'user@example.com')
            ->assertJsonPath('blood_group', 'O+')
            ->assertJsonPath('status', 'available')
            ->assertJsonMissing(['password']);
    }

    public function test_user_can_update_own_profile(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'full_name' => 'Ali Raza',
                'phone' => '03001110000',
                'city' => 'Lahore',
                'blood_group' => 'A+',
            ])
            ->assertOk()
            ->assertJsonPath('user.full_name', 'Ali Raza')
            ->assertJsonPath('user.city', 'Lahore')
            ->assertJsonPath('user.blood_group', 'A+');

        $this->assertDatabaseHas('users', [
            'id' => $this->user()->id,
            'full_name' => 'Ali Raza',
            'city' => 'Lahore',
            'blood_group' => 'A+',
        ]);
    }

    public function test_user_cannot_update_another_users_profile(): void
    {
        $token = $this->loginAs('user@example.com');
        $admin = $this->admin();

        // The API has no profile id route; a spoofed user_id in the payload
        // must be ignored, never used to target another account. The update
        // only ever applies to the authenticated user themselves.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'user_id' => $admin->id,
                'full_name' => 'Hacked Admin',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->user()->id,
            'full_name' => 'Hacked Admin',
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $admin->id,
            'full_name' => 'Hacked Admin',
        ]);
        $this->assertSame('Test Admin', $admin->fresh()->name);
    }

    public function test_email_is_immutable_via_profile_api(): void
    {
        $token = $this->loginAs('user@example.com');

        // email cannot be re-pointed to its own address nor to an existing one.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'email' => 'newemail@example.com',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->user()->id,
            'email' => 'user@example.com',
        ]);

        // Attempting to take the admin's email leaves both accounts unchanged.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'email' => 'test@example.com',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->user()->id,
            'email' => 'user@example.com',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $this->admin()->id,
            'email' => 'test@example.com',
        ]);
    }

    public function test_invalid_blood_group_is_rejected(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'blood_group' => 'Z+',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_group');

        $this->assertDatabaseHas('users', [
            'id' => $this->user()->id,
            'blood_group' => 'O+',
        ]);
    }

    public function test_profile_changes_appear_in_dashboard(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'full_name' => 'Ali Raza',
                'city' => 'Lahore',
                'blood_group' => 'AB-',
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertOk()
            ->assertJsonPath('profile.full_name', 'Ali Raza')
            ->assertJsonPath('profile.city', 'Lahore')
            ->assertJsonPath('profile.blood_group', 'AB-');
    }

    public function test_updated_blood_group_is_reflected_in_donor_search(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/profile', [
                'blood_group' => 'A+',
            ])
            ->assertOk();

        // The same single user now appears in the A+ donor search...
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=A%2B')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Ayesha Khan');

        // ...and no longer in the O+ search.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_no_donor_or_seeker_profile_is_created(): void
    {
        // No separate profile tables exist in the schema.
        foreach (['donor_profiles', 'seeker_profiles', 'donor_accounts', 'seeker_accounts'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table: {$table}");
        }

        // No Donor/Seeker roles exist.
        $this->assertFalse(Role::whereIn('name', ['Donor', 'Seeker'])->exists());

        // Registering a user creates exactly one users row with role User.
        $this->postJson('/api/register', [
            'role_type' => 'user',
            'name' => 'New User',
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'full_name' => 'New User',
            'phone' => '03001112222',
            'blood_group' => 'A+',
            'address' => 'Lahore',
            'age' => 25,
            'weight' => 60,
            'gender' => 'Male',
        ])->assertStatus(201);

        $newUser = User::where('email', 'newuser@example.com')->first();
        $this->assertTrue($newUser->hasRole('User'));
        $this->assertSame(1, $newUser->roles()->count());
    }

    public function test_blood_bank_cannot_use_user_profile_routes(): void
    {
        $bankToken = $this->loginAs('bank@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/user/profile')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/user/profile', ['full_name' => 'Sneaky Bank'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['full_name' => 'Sneaky Bank']);
    }
}