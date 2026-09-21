<?php

namespace Tests\Feature;

use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ResetsAuthGuards;

/**
 * STEP 4 - unified user blood request flow.
 *
 * A single "User" role owns every blood request: creation, listing and the
 * dashboard stats are all scoped to the authenticated user, while blood
 * banks and admins retain their manage/approve flows. No Seeker role or
 * client-side ownership exists.
 */
class UnifiedBloodRequestFlowTest extends TestCase
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

    private function bank(): BloodBank
    {
        return BloodBank::where('bank_name', 'City Blood Bank')->first();
    }

    private function secondBank(): BloodBank
    {
        $user = User::create([
            'name' => 'Second Blood Bank',
            'username' => 'secondbank',
            'email' => 'secondbank@example.com',
            'password' => bcrypt('Ainaa*220'),
            'is_active' => true,
        ]);

        return BloodBank::create([
            'user_id' => $user->id,
            'bank_name' => 'Second Blood Bank',
            'phone' => '03000000000',
            'address' => 'Somewhere Else',
            'registration_number' => 'BB-0002',
            'status' => 'active',
        ]);
    }

    public function test_request_is_always_owned_by_the_authenticated_user(): void
    {
        $token = $this->loginAs('user@example.com');
        $admin = $this->admin();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'user_id' => $admin->id, // spoof attempt - must be ignored
                'bank_id' => $this->bank()->id,
                'blood_group' => 'O+',
                'quantity' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('request.user_id', $this->user()->id)
            ->assertJsonPath('request.status', 'pending');

        $this->assertDatabaseHas('blood_requests', [
            'user_id' => $this->user()->id,
            'blood_group' => 'O+',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('blood_requests', ['user_id' => $admin->id]);
    }

    public function test_requests_are_scoped_to_the_authenticated_user(): void
    {
        $user = $this->user();

        BloodRequest::create([
            'user_id' => $this->admin()->id,
            'bank_id' => $this->bank()->id,
            'blood_group' => 'A+',
            'quantity' => 1,
            'status' => 'pending',
            'requested_date' => now(),
        ]);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/requests')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user_id', $user->id);

        // Dashboard request stats only reflect the authenticated user.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/dashboard')
            ->assertOk()
            ->assertJsonPath('total_requests', 1)
            ->assertJsonPath('pending_requests', 1)
            ->assertJsonPath('recent_requests.0.blood_group', 'O+');
    }

    public function test_user_cannot_read_or_delete_another_users_notification(): void
    {
        $notification = Notification::create([
            'user_type' => 'user',
            'user_id' => $this->admin()->id,
            'title' => 'Private',
            'message' => 'Belongs to the admin user',
        ]);

        $token = $this->loginAs('user@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/user/notifications/' . $notification->id . '/read')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/user/notifications/' . $notification->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'is_read' => false]);
    }

    public function test_user_cannot_use_blood_bank_or_admin_request_routes(): void
    {
        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request');

        // The user cannot manage the request via the blood bank endpoint.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(403);

        // Nor via the admin endpoints.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/requests')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_blood_bank_cannot_modify_another_banks_request(): void
    {
        $otherBank = $this->secondBank();

        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $otherBank->id,
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_blood_bank_sees_own_and_unassigned_pending_requests_only(): void
    {
        $otherBank = $this->secondBank();

        $token = $this->loginAs('user@example.com');

        $otherRequest = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $otherBank->id,
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request.id');

        $unassigned = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request.id');

        $bankToken = $this->loginAs('bank@example.com');

        // Second bank's assigned request must not leak into this bank's pool.
        $ids = array_column($this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->getJson('/api/blood-bank/requests')
            ->assertOk()
            ->json(), 'id');
        $this->assertNotContains($otherRequest, $ids);
        $this->assertContains($unassigned, $ids);
    }

    public function test_blood_bank_approve_and_fulfill_consumes_stock_and_notifies_user(): void
    {
        $token = $this->loginAs('user@example.com');
        $bank = $this->bank();
        $stockBefore = (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 2,
                'urgency' => 'emergency',
                'reason' => 'Surgery',
            ])
            ->assertStatus(201)
            ->assertJsonPath('request.status', 'pending')
            ->json('request');

        $this->assertNotNull(BloodRequest::find($request['id'])->requested_date);

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(
            $stockBefore - 2,
            (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'fulfilled'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request['id'],
            'status' => 'fulfilled',
        ]);
        $this->assertDatabaseMissing('blood_requests', [
            'id' => $request['id'],
            'fulfilled_date' => null,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Completed',
        ]);
    }

    public function test_blood_bank_rejects_when_stock_is_insufficient(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 1]);

        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 5,
            ])
            ->assertStatus(201)
            ->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_admin_can_view_and_manage_all_requests(): void
    {
        $token = $this->loginAs('user@example.com');
        $bank = $this->bank();
        $stockBefore = (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'reason' => 'Thalassemia',
            ])
            ->assertStatus(201)
            ->json('request');

        $adminToken = $this->loginAs('test@example.com');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/requests')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user_name', 'Ayesha Khan')
            ->assertJsonPath('0.bank_name', 'City Blood Bank')
            ->assertJsonPath('0.reason', 'Thalassemia');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(
            $stockBefore - 1,
            (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    // ==================== STEP 9 - ADMIN APPROVAL STOCK ENFORCEMENT ====================
    // An admin can only approve a request when the assigned bank has enough
    // stock for the requested blood group and quantity.

    private function newPendingRequest(int $quantity, string $bloodGroup = 'O+', ?int $bankId = null): array
    {
        $payload = ['blood_group' => $bloodGroup, 'quantity' => $quantity];
        if ($bankId !== null) {
            $payload['bank_id'] = $bankId;
        }

        return $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('user@example.com'))
            ->postJson('/api/user/requests', $payload)
            ->assertStatus(201)
            ->json('request');
    }

    public function test_admin_cannot_approve_with_insufficient_stock(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 2]);

        $request = $this->newPendingRequest(5, 'O+', $this->bank()->id);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock! Available: 2 units, Required: 5 units');

        // Request stays unapproved, stock stays unchanged, no approval notification.
        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
        $this->assertSame(2, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
        $this->assertDatabaseMissing('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    public function test_admin_can_approve_with_exact_stock(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 5]);

        $request = $this->newPendingRequest(5, 'O+', $this->bank()->id);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(0, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    public function test_admin_can_approve_with_more_than_required_stock(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 10]);

        $request = $this->newPendingRequest(5, 'O+', $this->bank()->id);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(5, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_admin_stock_from_wrong_blood_group_cannot_satisfy_request(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 0]);
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'A+')->update(['quantity' => 20]);

        $request = $this->newPendingRequest(5, 'O+', $this->bank()->id);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock! Available: 0 units, Required: 5 units');

        // Neither group's stock is touched and the request stays pending.
        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
        $this->assertSame(0, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
        $this->assertSame(20, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'A+')->value('quantity'));
        $this->assertDatabaseMissing('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    public function test_admin_cannot_approve_an_unassigned_request(): void
    {
        $request = $this->newPendingRequest(2, 'O+', null);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_admin_cannot_re_approve_an_already_approved_request(): void
    {
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 10]);

        $request = $this->newPendingRequest(2, 'O+', $this->bank()->id);

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();

        // Approving again is rejected and must not deduct stock a second time.
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending requests can be approved.');

        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_admin_cannot_be_bypassed_by_client_supplied_values(): void
    {
        $bank = $this->bank();
        $otherBank = $this->secondBank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 2]);
        BloodStock::updateOrCreate(
            ['bank_id' => $otherBank->id, 'blood_group' => 'O+'],
            ['quantity' => 100]
        );

        $request = $this->newPendingRequest(5, 'O+', $this->bank()->id);

        // Status payload smuggling - authoritative values come from the stored request.
        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', [
                'status' => 'approved',
                'bank_id' => $otherBank->id,
                'blood_group' => 'A+',
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock! Available: 2 units, Required: 5 units');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
        $this->assertSame(100, (int)BloodStock::where('bank_id', $otherBank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_normal_user_cannot_approve_via_admin_endpoint(): void
    {
        $request = $this->newPendingRequest(1);

        $userToken = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_blood_bank_cannot_approve_via_admin_endpoint(): void
    {
        $request = $this->newPendingRequest(1);

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_admin_approval_preserves_successful_workflow(): void
    {
        $token = $this->loginAs('user@example.com');
        $bank = $this->bank();
        BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->update(['quantity' => 10]);

        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 2,
                'reason' => 'Thalassemia',
            ])->assertStatus(201)->json('request');

        $adminToken = $this->loginAs('test@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('message', 'Request status updated to approved');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    // ==================== STEP 12 - STATE-AWARE STATUS TRANSITIONS ====================
    // BloodRequest::canTransitionTo() is the single whitelist. Normal business
    // transitions are pending -> approved/rejected and approved -> fulfilled.
    // No transition back into pending exists because approving a request
    // consumes stock atomically (STEP 9); reopening an approved/fulfilled
    // request is not a product feature and would corrupt inventory without a
    // restoration path.

    private function adminPutStatus(int $requestId, string $status): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->loginAs('test@example.com'))
            ->putJson('/api/admin/requests/' . $requestId . '/status', ['status' => $status]);
    }

    private function setStock(int $quantity): void
    {
        BloodStock::where('bank_id', $this->bank()->id)->where('blood_group', 'O+')->update(['quantity' => $quantity]);
    }

    public function test_admin_pending_to_approved_deducts_stock_exactly_once(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(3, 'O+', $bank->id);

        $this->adminPutStatus($request['id'], 'approved')->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(7, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_admin_pending_to_approved_insufficient_stock_is_rejected(): void
    {
        $bank = $this->bank();
        $this->setStock(2);

        $request = $this->newPendingRequest(5, 'O+', $bank->id);

        $this->adminPutStatus($request['id'], 'approved')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock! Available: 2 units, Required: 5 units');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
        $this->assertSame(2, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_admin_pending_to_rejected(): void
    {
        $bank = $this->bank();
        $this->setStock(10);
        $quantityBefore = (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity');

        $request = $this->newPendingRequest(2, 'O+', $bank->id);

        $this->adminPutStatus($request['id'], 'rejected')
            ->assertOk()
            ->assertJsonPath('message', 'Request status updated to rejected');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'rejected']);
        $this->assertSame(
            $quantityBefore,
            (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity')
        );
    }

    public function test_admin_approved_to_fulfilled(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(2, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'approved')->assertOk();

        $this->adminPutStatus($request['id'], 'fulfilled')
            ->assertOk()
            ->assertJsonPath('message', 'Request status updated to fulfilled');

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request['id'],
            'status' => 'fulfilled',
        ]);
        $this->assertDatabaseMissing('blood_requests', [
            'id' => $request['id'],
            'fulfilled_date' => null,
        ]);
    }

    public function test_duplicate_approval_does_not_deduct_stock_twice(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(3, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'approved')->assertOk();
        $this->assertSame(7, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));

        // approved -> approved is refused before any stock logic runs.
        $this->adminPutStatus($request['id'], 'approved')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending requests can be approved.');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'approved']);
        $this->assertSame(7, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_fulfilled_to_approved_is_rejected(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(2, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'approved')->assertOk();
        $this->adminPutStatus($request['id'], 'fulfilled')->assertOk();

        $this->adminPutStatus($request['id'], 'approved')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending requests can be approved.');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'fulfilled']);
        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_fulfilled_request_cannot_reopen_to_pending(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(2, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'approved')->assertOk();
        $this->adminPutStatus($request['id'], 'fulfilled')->assertOk();

        $this->adminPutStatus($request['id'], 'pending')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Request cannot be moved from fulfilled to pending.');

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request['id'],
            'status' => 'fulfilled',
        ]);
        $this->assertDatabaseMissing('blood_requests', [
            'id' => $request['id'],
            'fulfilled_date' => null,
        ]);
        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_rejected_request_cannot_be_approved_later(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        $request = $this->newPendingRequest(2, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'rejected')->assertOk();

        $this->adminPutStatus($request['id'], 'approved')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only pending requests can be approved.');

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'rejected']);
        $this->assertSame(10, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));
    }

    public function test_invalid_transition_sends_no_notification(): void
    {
        $bank = $this->bank();
        $this->setStock(10);

        // A rejected request followed by a refused approval must never raise a
        // misleading 'Blood Request Approved' notification.
        $request = $this->newPendingRequest(2, 'O+', $bank->id);
        $this->adminPutStatus($request['id'], 'rejected')->assertOk();

        $this->adminPutStatus($request['id'], 'approved')->assertStatus(422);

        $this->assertDatabaseMissing('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Approved',
        ]);
    }

    public function test_admin_endpoint_is_admin_role_only(): void
    {
        $request = $this->newPendingRequest(1);

        $userToken = $this->loginAs('user@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'rejected'])
            ->assertStatus(403);

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/admin/requests/' . $request['id'] . '/status', ['status' => 'rejected'])
            ->assertStatus(403);

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'pending']);
    }

    public function test_blood_bank_workflow_remains_intact(): void
    {
        $bank = $this->bank();
        $this->setStock(10);
        $token = $this->loginAs('user@example.com');

        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 2,
            ])->assertStatus(201)->json('request');

        $bankToken = $this->loginAs('bank@example.com');

        // pending -> fulfilled directly is refused (bypasses approval/stock).
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'fulfilled'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Request cannot be moved from pending to fulfilled.');
        $this->assertSame(10, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));

        // pending -> approved reserves stock exactly once.
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertOk();
        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));

        // approved -> approved must not deduct again.
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Request is already approved');
        $this->assertSame(8, (int)BloodStock::where('bank_id', $bank->id)->where('blood_group', 'O+')->value('quantity'));

        // approved -> fulfilled completes the lifecycle.
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'fulfilled'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', [
            'id' => $request['id'],
            'status' => 'fulfilled',
        ]);
        $this->assertDatabaseMissing('blood_requests', [
            'id' => $request['id'],
            'fulfilled_date' => null,
        ]);
    }

    public function test_user_request_creation_remains_intact(): void
    {
        $token = $this->loginAs('user@example.com');
        $bank = $this->bank();

        $assigned = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $bank->id,
                'blood_group' => 'O+',
                'quantity' => 1,
                'urgency' => 'emergency',
            ])->assertStatus(201);

        $unassigned = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'blood_group' => 'A+',
                'quantity' => 1,
            ])->assertStatus(201);

        $this->assertDatabaseHas('blood_requests', [
            'id' => $assigned->json('request.id'),
            'user_id' => $this->user()->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('blood_requests', [
            'id' => $unassigned->json('request.id'),
            'user_id' => $this->user()->id,
            'status' => 'pending',
        ]);
    }

    // ==================== STEP 13 - REJECTED TERMINOLOGY ====================
    // The blood request lifecycle stores 'rejected' (never 'cancelled') and the
    // notification sent to the user on blood-bank rejection says "rejected",
    // keeping the user-facing wording consistent with the stored status.

    public function test_blood_bank_rejection_stores_rejected_status(): void
    {
        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $this->bank()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'rejected'])
            ->assertOk();

        $this->assertDatabaseHas('blood_requests', ['id' => $request['id'], 'status' => 'rejected']);
        $this->assertDatabaseMissing('blood_requests', ['id' => $request['id'], 'status' => 'cancelled']);
    }

    public function test_blood_bank_rejection_notifies_user_with_rejected_wording(): void
    {
        $token = $this->loginAs('user@example.com');
        $request = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/requests', [
                'bank_id' => $this->bank()->id,
                'blood_group' => 'O+',
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->json('request');

        $bankToken = $this->loginAs('bank@example.com');
        $this->withHeader('Authorization', 'Bearer ' . $bankToken)
            ->putJson('/api/blood-bank/requests/' . $request['id'] . '/status', ['status' => 'rejected'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_type' => 'user',
            'user_id' => $this->user()->id,
            'title' => 'Blood Request Rejected',
            'message' => 'Your blood request has been rejected by the blood bank. Please try another bank.',
        ]);
    }
}