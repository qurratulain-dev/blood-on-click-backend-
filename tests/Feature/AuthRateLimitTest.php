<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase, ResetsAuthGuards;

    private const LOGIN_LIMIT = 5;

    private const REGISTER_LIMIT = 10;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Cache::flush();
        $this->seed();
    }

    private function credentials(string $email = 'user@example.com', string $password = 'Ainaa*220'): array
    {
        return ['email' => $email, 'password' => $password];
    }

    private function registrationPayload(int $n): array
    {
        return [
            'role_type' => 'user',
            'name' => "Rate User {$n}",
            'username' => "rateuser{$n}",
            'email' => "rateuser{$n}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'full_name' => "Rate User {$n}",
            'phone' => '030000000' . $n,
            'blood_group' => 'A+',
            'address' => 'Lahore',
            'age' => 25,
            'weight' => 60,
            'gender' => 'Male',
        ];
    }

    public function test_valid_login_is_not_blocked(): void
    {
        $response = $this->postJson('/api/login', $this->credentials());

        $response->assertOk()
            ->assertJsonPath('role', 'User')
            ->assertJsonStructure(['message', 'user', 'role', 'permissions', 'token']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_invalid_credentials_still_return_the_existing_failure(): void
    {
        $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_login_is_rate_limited_after_the_configured_attempts(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'))
                ->assertStatus(422);
        }

        $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'))
            ->assertStatus(429);
    }

    public function test_throttled_login_response_exposes_retry_information(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'));
        }

        $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) self::LOGIN_LIMIT)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonPath('message', 'Too Many Attempts.');
    }

    public function test_login_limits_are_scoped_per_email_and_ip(): void
    {
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'));
        }

        $this->postJson('/api/login', $this->credentials('user@example.com', 'wrong-password'))
            ->assertStatus(429);

        // A different account (different email key) is unaffected.
        $this->postJson('/api/login', $this->credentials('test@example.com'))
            ->assertOk();

        // A different client IP for the exhausted account is also unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/login', $this->credentials('user@example.com'))
            ->assertOk();
    }

    public function test_registration_still_works_below_the_limit(): void
    {
        $this->postJson('/api/register', $this->registrationPayload(1))
            ->assertStatus(201)
            ->assertJsonPath('role', 'User');

        $this->assertDatabaseHas('users', ['email' => 'rateuser1@example.com']);
    }

    public function test_registration_is_rate_limited_after_the_configured_attempts(): void
    {
        for ($i = 1; $i <= self::REGISTER_LIMIT; $i++) {
            $this->postJson('/api/register', $this->registrationPayload($i))
                ->assertStatus(201);
        }

        $this->postJson('/api/register', $this->registrationPayload(self::REGISTER_LIMIT + 1))
            ->assertStatus(429);
    }

    public function test_blood_bank_registration_still_works_below_the_limit(): void
    {
        $this->postJson('/api/register', [
            'role_type' => 'blood_bank',
            'name' => 'Rate Blood Bank',
            'username' => 'ratebank',
            'email' => 'ratebank@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'bank_name' => 'Rate Blood Bank',
            'phone' => '03001112222',
            'address' => 'Lahore',
            'registration_number' => 'BB-RATE-1',
        ])
            ->assertStatus(201)
            ->assertJsonPath('role', 'Blood Bank');

        $bank = BloodBank::where('registration_number', 'BB-RATE-1')->first();

        $this->assertNotNull($bank);
        $this->assertSame(8, $bank->bloodStocks()->count());
        $this->assertDatabaseHas('blood_stock', [
            'bank_id' => $bank->id,
            'blood_group' => 'A+',
            'quantity' => 0,
        ]);
    }

    public function test_admin_user_and_blood_bank_logins_all_still_work(): void
    {
        $this->postJson('/api/login', $this->credentials('test@example.com'))
            ->assertOk()
            ->assertJsonPath('role', 'Admin');

        $this->postJson('/api/login', $this->credentials('user@example.com'))
            ->assertOk()
            ->assertJsonPath('role', 'User');

        $this->postJson('/api/login', $this->credentials('bank@example.com'))
            ->assertOk()
            ->assertJsonPath('role', 'Blood Bank');
    }
}
