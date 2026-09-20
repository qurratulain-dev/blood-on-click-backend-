<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

class UnifiedUserFlowTest extends TestCase
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

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
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
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'User');

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'full_name' => 'New User',
            'blood_group' => 'A+',
            'status' => 'available',
        ]);
    }

    public function test_user_dashboard_combines_donor_and_request_stats(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/donations', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'donation_date' => now()->toDateString(),
            ])->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => BloodBank::first()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'urgency' => 'normal',
            ])->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertOk()
            ->assertJsonPath('total_donations', 1)
            ->assertJsonPath('total_requests', 1)
            ->assertJsonPath('profile.blood_group', 'O+');
    }

    public function test_user_can_search_donors_and_banks(): void
    {
        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/donors?blood_group=O%2B')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('donors.0.full_name', 'Ayesha Khan');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/banks?name=City')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_blood_bank_can_manage_requests(): void
    {
        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'blood_group' => 'O+',
                'quantity' => 1,
                'urgency' => 'normal',
            ])->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/blood-bank/requests')
            ->assertOk()
            ->assertJsonPath('0.id', $request['id'])
            ->assertJsonPath('0.user_name', 'Ayesha Khan');

        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => User::where('email', 'user@example.com')->first()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    public function test_admin_dashboard_and_users(): void
    {
        $token = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('users', 1)
            ->assertJsonPath('blood_banks', 1);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.full_name', 'Ayesha Khan');
    }
}