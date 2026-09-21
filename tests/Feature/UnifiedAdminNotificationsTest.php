<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\BloodStock;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

class UnifiedAdminNotificationsTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'test@example.com')->first();
    }

    private function createAdminNotification(int $adminId, bool $read = false): Notification
    {
        return Notification::create([
            'user_type' => 'admin',
            'user_id' => $adminId,
            'title' => 'Medical Assessment Requested',
            'message' => 'A user has requested a medical assessment.',
            'is_read' => $read,
        ]);
    }

    public function test_admin_can_list_admin_notifications(): void
    {
        $admin = $this->admin();
        $notif = $this->createAdminNotification($admin->id);

        $token = $this->loginAs('test@example.com');
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/notifications');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $notif->id, 'title' => 'Medical Assessment Requested']);
    }

    public function test_admin_notifications_are_ordered_newest_first(): void
    {
        $admin = $this->admin();

        $old = $this->createAdminNotification($admin->id);
        sleep(1);
        $new = $this->createAdminNotification($admin->id);

        $token = $this->loginAs('test@example.com');
        $ids = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/notifications')
            ->assertOk()
            ->json('*.id');

        $this->assertSame([$new->id, $old->id], $ids);
    }

    public function test_admin_can_mark_notification_read(): void
    {
        $admin = $this->admin();
        $notif = $this->createAdminNotification($admin->id);

        $this->assertDatabaseHas('notifications', ['id' => $notif->id, 'is_read' => false]);

        $token = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/notifications/' . $notif->id . '/read')
            ->assertOk()
            ->assertJsonPath('message', 'Notification marked as read');

        $this->assertDatabaseHas('notifications', ['id' => $notif->id, 'is_read' => true]);
    }

    public function test_admin_can_mark_all_notifications_read(): void
    {
        $admin = $this->admin();
        $this->createAdminNotification($admin->id);
        $this->createAdminNotification($admin->id);

        $token = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read');

        $this->assertDatabaseMissing('notifications', [
            'user_type' => 'admin',
            'user_id' => $admin->id,
            'is_read' => false,
        ]);
    }

    public function test_admin_cannot_mark_another_admins_notification_read(): void
    {
        $adminA = $this->admin();

        $adminBUser = User::create([
            'name' => 'Second Admin',
            'username' => 'secondadmin',
            'email' => 'admin2@example.com',
            'password' => bcrypt('Ainaa*220'),
            'is_active' => true,
        ]);
        $adminBUser->assignRole('Admin');

        $notif = $this->createAdminNotification($adminA->id);

        Auth::forgetGuards();
        $tokenB = $this->postJson('/api/login', [
            'email' => 'admin2@example.com',
            'password' => 'Ainaa*220',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->putJson('/api/admin/notifications/' . $notif->id . '/read')
            ->assertStatus(403);

        $this->assertDatabaseHas('notifications', ['id' => $notif->id, 'is_read' => false]);
    }

    public function test_non_admin_cannot_access_admin_notifications(): void
    {
        $token = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/notifications')
            ->assertStatus(403);

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/admin/notifications')
            ->assertStatus(403);
    }

    public function test_assessment_request_notifies_all_admins_via_notification_endpoint(): void
    {
        $adminCount = User::role('Admin')->count();
        $this->assertGreaterThanOrEqual(1, $adminCount);

        $token = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/assessment-requests')
            ->assertStatus(201);

        $adminToken = $this->loginAs('test@example.com');
        $notifications = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/notifications')
            ->assertOk()
            ->json();

        $assessmentNotifs = array_filter($notifications, fn($n) => $n['title'] === 'Medical Assessment Requested');
        $this->assertGreaterThanOrEqual(1, count($assessmentNotifs));
    }

    public function test_bank_approval_notifies_all_admins(): void
    {
        $bank = BloodBank::where('bank_name', 'City Blood Bank')->first();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 10]);

        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 2,
            ])
            ->assertStatus(201)
            ->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $adminToken = $this->loginAs('test@example.com');
        $notifications = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/notifications')
            ->assertOk()
            ->json();

        $approvalNotifs = array_filter($notifications, fn($n) => $n['title'] === 'Request Approved');
        $this->assertGreaterThanOrEqual(1, count($approvalNotifs));
    }
}
