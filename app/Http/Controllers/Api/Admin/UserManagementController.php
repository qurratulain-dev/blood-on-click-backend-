<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\User;
use App\Models\MedicalAssessment;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function counts(): JsonResponse
    {
        return response()->json([
            'users' => User::role('User')->count(),
            'blood_banks' => BloodBank::count(),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $totalUsers = User::role('User')->count();
        $totalBanks = BloodBank::count();
        $totalRequests = BloodRequest::count();
        $pendingRequests = BloodRequest::where('status', 'pending')->count();

        // Blood stock summary grouped by blood group
        $stockSummary = BloodStock::selectRaw('blood_group, SUM(quantity) as total')
            ->groupBy('blood_group')
            ->orderBy('blood_group')
            ->get();

        // Recent 5 donations with user & bank info
        $recentDonations = DonationHistory::with(['user', 'bank'])
            ->orderBy('donation_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'user_name' => $d->user->full_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'quantity' => $d->quantity,
                'status' => $d->status,
            ]);

        return response()->json([
            'users' => $totalUsers,
            'blood_banks' => $totalBanks,
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'stock_summary' => $stockSummary,
            'recent_donations' => $recentDonations,
        ]);
    }

    public function addUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'required|string|max:20',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'city' => 'nullable|string|max:255',
            'address' => 'required|string',
            'age' => 'required|integer|min:18|max:100',
            'weight' => 'required|numeric|min:30',
            'gender' => ['required', Rule::in(['Male', 'Female', 'Other'])],
            'status' => ['sometimes', Rule::in(['available', 'not_available'])],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['full_name'],
                'username' => $this->generateUsername($validated['full_name']),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'blood_group' => $validated['blood_group'],
                'city' => $validated['city'] ?? null,
                'address' => $validated['address'],
                'age' => $validated['age'],
                'weight' => $validated['weight'],
                'gender' => $validated['gender'],
                'status' => $validated['status'] ?? 'available',
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);
            $user->assignRole('User');

            Notification::create([
                'user_type' => 'user',
                'user_id' => $user->id,
                'title' => 'Welcome to Blood on Click',
                'message' => 'Your account has been created by admin. You can now login, donate blood, and request blood.',
            ]);

            return response()->json([
                'message' => 'User added successfully',
                'user' => $user,
            ], 201);
        });
    }

    public function addBloodBank(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'registration_number' => 'required|string|max:255',
            'license_number' => 'nullable|string|max:255',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['bank_name'],
                'username' => $this->generateUsername($validated['bank_name']),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->assignRole('Blood Bank');

            $bank = BloodBank::create([
                'user_id' => $user->id,
                'bank_name' => $validated['bank_name'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'registration_number' => $validated['registration_number'],
                'license_number' => $validated['license_number'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);

            $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
            foreach ($groups as $group) {
                BloodStock::create([
                    'bank_id' => $bank->id,
                    'blood_group' => $group,
                    'quantity' => 0,
                ]);
            }

            Notification::create([
                'user_type' => 'blood_bank',
                'user_id' => $bank->id,
                'title' => 'Welcome to Blood on Click',
                'message' => 'Your blood bank account has been created by admin. You can now login and manage your inventory.',
            ]);

            return response()->json([
                'message' => 'Blood Bank added successfully',
                'blood_bank' => $bank,
            ], 201);
        });
    }

    public function donationDetails(int $id): JsonResponse
    {
        $donation = DonationHistory::with(['user', 'bank'])
            ->findOrFail($id);

        $latestAssessment = MedicalAssessment::where('user_id', $donation->user_id)
            ->orderBy('assessment_date', 'desc')
            ->first();

        return response()->json([
            'id' => $donation->id,
            'user_id' => $donation->user_id,
            'donor_name' => $donation->user->full_name ?? 'N/A',
            'donor_email' => $donation->user->email ?? 'N/A',
            'donor_phone' => $donation->user->phone ?? 'N/A',
            'blood_group' => $donation->blood_group,
            'age' => $donation->user->age,
            'weight' => $donation->user->weight,
            'gender' => $donation->user->gender,
            'donor_address' => $donation->user->address,
            'bank_name' => $donation->bank->bank_name ?? 'N/A',
            'bank_address' => $donation->bank->address ?? 'N/A',
            'bank_phone' => $donation->bank->phone ?? 'N/A',
            'quantity' => $donation->quantity,
            'donation_date' => $donation->donation_date?->format('Y-m-d'),
            'status' => $donation->status,
            'medical_report_path' => $donation->medical_report_path,
            'medical_report_url' => $donation->medical_report_path ? asset('storage/' . $donation->medical_report_path) : null,
            'has_medical_report' => !is_null($donation->medical_report_path),
            'latest_assessment' => $latestAssessment ? [
                'blood_pressure' => $latestAssessment->blood_pressure,
                'hemoglobin' => $latestAssessment->hemoglobin,
                'is_eligible' => $latestAssessment->is_eligible,
            ] : null,
        ]);
    }

    public function generateMedicalReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'donation_id' => 'required|exists:donation_history,id',
        ]);

        $donation = DonationHistory::with(['user', 'bank'])
            ->findOrFail($validated['donation_id']);

        $latestAssessment = MedicalAssessment::where('user_id', $donation->user_id)
            ->orderBy('assessment_date', 'desc')
            ->first();

        $donationDate = $donation->donation_date?->format('d/m/Y') ?? 'N/A';
        $reportDate = now()->format('d/m/Y h:i A');
        $reportNo = 'MR-' . now()->year . '-' . str_pad((string)$donation->id, 6, '0', STR_PAD_LEFT);

        $hemoglobin = $latestAssessment?->hemoglobin ? $latestAssessment->hemoglobin . ' g/dL' : 'Not recorded';
        $bloodPressure = $latestAssessment?->blood_pressure ?? 'Not recorded';
        $eligibility = $latestAssessment ? ($latestAssessment->is_eligible ? 'Eligible' : 'Not Eligible') : 'Eligible';
        $weight = $latestAssessment?->weight ?? $donation->user->weight ?? 'N/A';
        $gender = $donation->user->gender ?? 'N/A';

        $nextEligible = $donation->donation_date?->copy()->addDays(90)->format('d/m/Y') ?? 'N/A';

        $html = view('medical_report_template', [
            'reportNo' => $reportNo,
            'reportDate' => $reportDate,
            'donation' => $donation,
            'donationDate' => $donationDate,
            'hemoglobin' => $hemoglobin,
            'bloodPressure' => $bloodPressure,
            'eligibility' => $eligibility,
            'weight' => $weight,
            'gender' => $gender,
            'nextEligible' => $nextEligible,
        ])->render();

        $filename = 'medical_report_' . $donation->id . '_' . now()->format('Ymd_His') . '.html';
        $relativePath = 'medical-reports/' . $filename;
        $fullPath = storage_path('app/public/' . $relativePath);

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($fullPath, $html);

        $donation->update(['medical_report_path' => $relativePath]);

        Notification::create([
            'user_type' => 'user',
            'user_id' => $donation->user_id,
            'title' => 'Medical Report Generated',
            'message' => "Your medical report for donation on {$donationDate} has been generated. You can view it in your donation history.",
        ]);

        return response()->json([
            'message' => 'Medical report generated successfully!',
            'medical_report_url' => asset('storage/' . $relativePath),
        ]);
    }

    public function donations(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = DonationHistory::with(['user', 'bank']);

        if ($status && in_array($status, ['pending', 'completed', 'rejected'])) {
            $query->where('status', $status);
        }

        $donations = $query->orderBy('donation_date', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'donor_name' => $d->user->full_name ?? 'N/A',
                'donor_email' => $d->user->email ?? 'N/A',
                'donor_phone' => $d->user->phone ?? 'N/A',
                'bank_id' => $d->bank_id,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'quantity' => $d->quantity,
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'status' => $d->status,
                'admin_message' => $d->admin_message,
                'has_medical_report' => !is_null($d->medical_report_path),
                'medical_report_url' => $d->medical_report_path ? asset('storage/' . $d->medical_report_path) : null,
                'created_at' => $d->created_at?->format('Y-m-d h:i A'),
            ]);

        $pendingCount = DonationHistory::where('status', 'pending')->count();
        $completedCount = DonationHistory::where('status', 'completed')->count();
        $rejectedCount = DonationHistory::where('status', 'rejected')->count();
        $totalUnits = DonationHistory::where('status', 'completed')->sum('quantity');

        return response()->json([
            'donations' => $donations,
            'counts' => [
                'pending' => $pendingCount,
                'completed' => $completedCount,
                'rejected' => $rejectedCount,
                'total_units' => $totalUnits,
            ],
        ]);
    }

    public function verifyDonation(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $donation = DonationHistory::with('user')->findOrFail($id);

            if ($donation->status !== 'pending') {
                return response()->json(['message' => 'Only pending donations can be verified'], 422);
            }

            $adminMessage = $request->input('admin_message');
            $donation->update([
                'status' => 'completed',
                'admin_message' => $adminMessage,
                'donation_date' => now(),
            ]);

            $stock = BloodStock::where('bank_id', $donation->bank_id)
                ->where('blood_group', $donation->blood_group)
                ->first();

            if ($stock) {
                $stock->increment('quantity', $donation->quantity);
            } else {
                BloodStock::create([
                    'bank_id' => $donation->bank_id,
                    'blood_group' => $donation->blood_group,
                    'quantity' => $donation->quantity,
                ]);
            }

            $donation->user->update(['status' => 'available']);

            $message = "Thank you for your donation! Your donation of {$donation->quantity} unit(s) of {$donation->blood_group} blood has been verified.";
            Notification::create([
                'user_type' => 'user',
                'user_id' => $donation->user_id,
                'title' => 'Donation Verified',
                'message' => $message,
            ]);

            return response()->json(['message' => 'Donation verified successfully! Stock updated.']);
        });
    }

    public function rejectDonation(Request $request, int $id): JsonResponse
    {
        $donation = DonationHistory::findOrFail($id);

        // A donation can only be rejected while it is pending; completed or
        // already-rejected records must not be moved back to rejected.
        if ($donation->status !== 'pending') {
            return response()->json(['message' => 'Only pending donations can be rejected'], 422);
        }

        $adminMessage = $request->input('admin_message');
        $donation->update([
            'status' => 'rejected',
            'admin_message' => $adminMessage,
        ]);

        Notification::create([
            'user_type' => 'user',
            'user_id' => $donation->user_id,
            'title' => 'Donation Rejected',
            'message' => 'Your donation record has been rejected. Please contact admin.',
        ]);

        return response()->json(['message' => 'Donation rejected successfully!']);
    }

    public function moveToPending(int $id): JsonResponse
    {
        $donation = DonationHistory::findOrFail($id);

        // Only rejected donations may be reopened for review. A completed
        // donation has already bumped blood stock and started the donor's
        // waiting period, so moving it back to pending and re-verifying would
        // double-count stock and corrupt the donor's eligibility.
        if ($donation->status !== 'rejected') {
            return response()->json([
                'message' => 'Only rejected donations can be moved back to pending.',
            ], 422);
        }

        $donation->update(['status' => 'pending']);

        return response()->json(['message' => 'Donation moved to pending!']);
    }

    public function uploadMedicalReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'donation_id' => 'required|exists:donation_history,id',
            'medical_report' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $donation = DonationHistory::findOrFail($validated['donation_id']);

        $path = $request->file('medical_report')->store('medical-reports', 'public');

        $donation->update(['medical_report_path' => $path]);

        Notification::create([
            'user_type' => 'user',
            'user_id' => $donation->user_id,
            'title' => 'Medical Report Uploaded',
            'message' => 'Your medical report has been uploaded by admin.',
        ]);

        return response()->json([
            'message' => 'Medical report uploaded successfully!',
            'medical_report_url' => asset('storage/' . $path),
        ]);
    }

    // ==================== RECOMMEND BLOOD BANK ====================

    public function sendRecommendation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'bank_id' => 'required|exists:blood_banks,id',
        ]);

        $bank = BloodBank::findOrFail($validated['bank_id']);

        Notification::create([
            'user_type' => 'user',
            'user_id' => $validated['user_id'],
            'title' => 'Blood Bank Recommendation',
            'message' => "We recommend you to visit {$bank->bank_name} for your blood needs.",
        ]);

        return response()->json(['message' => 'Recommendation sent successfully!']);
    }

    public function recommendationData(): JsonResponse
    {
        $users = User::role('User')
            ->select('id', 'full_name', 'blood_group', 'address', 'city')
            ->orderBy('full_name')
            ->get();
        $banks = BloodBank::where('status', 'active')
            ->select('id', 'bank_name', 'address')
            ->orderBy('bank_name')
            ->get();

        return response()->json([
            'users' => $users,
            'banks' => $banks,
        ]);
    }

    // ==================== ASSESSMENT HISTORY ====================

    public function userAssessmentHistory(int $userId): JsonResponse
    {
        $assessments = MedicalAssessment::with('assessedBy')
            ->where('user_id', $userId)
            ->orderBy('assessment_date', 'desc')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'assessment_date' => $a->assessment_date?->format('Y-m-d'),
                'blood_pressure' => $a->blood_pressure,
                'hemoglobin' => $a->hemoglobin,
                'temperature' => $a->temperature,
                'pulse' => $a->pulse,
                'weight' => $a->weight,
                'is_eligible' => $a->is_eligible,
                'assessed_by' => $a->assessedBy?->name ?? 'N/A',
            ]);

        return response()->json($assessments);
    }

    // ==================== MANAGE USERS ====================

    public function users(): JsonResponse
    {
        $users = User::role('User')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'full_name' => $u->full_name ?? $u->name,
                'email' => $u->email ?? 'N/A',
                'phone' => $u->phone,
                'blood_group' => $u->blood_group,
                'age' => $u->age,
                'weight' => $u->weight,
                'status' => $u->status,
                'is_active' => (bool) $u->is_active,
                'user_type' => 'user',
            ]);

        $banks = BloodBank::with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'full_name' => $b->bank_name,
                'email' => $b->user?->email ?? 'N/A',
                'phone' => $b->phone,
                'registration_number' => $b->registration_number,
                'status' => $b->status,
                'is_active' => (bool) ($b->user?->is_active ?? false),
                'user_type' => 'bank',
            ]);

        return response()->json([
            'users' => $users,
            'blood_banks' => $banks,
            'counts' => [
                'users' => $users->count(),
                'banks' => $banks->count(),
            ],
        ]);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'city' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
            'age' => 'sometimes|integer|min:18|max:100',
            'weight' => 'sometimes|numeric|min:30',
            'gender' => ['sometimes', Rule::in(['Male', 'Female', 'Other'])],
            'status' => ['sometimes', Rule::in(['available', 'not_available'])],
            'is_active' => ['sometimes', 'boolean'],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $user->update($validated);

        Notification::create([
            'user_type' => 'user',
            'user_id' => $user->id,
            'title' => 'Profile Updated',
            'message' => 'Your profile has been updated by admin.',
        ]);

        return response()->json(['message' => 'User updated successfully!', 'user' => $user]);
    }

    public function updateBloodBank(Request $request, int $id): JsonResponse
    {
        $bank = BloodBank::findOrFail($id);

        $validated = $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'registration_number' => 'sometimes|string|max:255',
            'license_number' => 'nullable|string|max:255',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'is_active' => ['sometimes', 'boolean'],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $bank->update($validated);

        if (array_key_exists('is_active', $validated)) {
            $bank->user?->update(['is_active' => $validated['is_active']]);
        }

        Notification::create([
            'user_type' => 'blood_bank',
            'user_id' => $bank->id,
            'title' => 'Profile Updated',
            'message' => 'Your blood bank profile has been updated by admin.',
        ]);

        return response()->json(['message' => 'Blood Bank updated successfully!', 'bank' => $bank]);
    }

    public function deleteUser(string $type, int $id): JsonResponse
    {
        return DB::transaction(function () use ($type, $id) {
            if ($type === 'user') {
                $record = User::findOrFail($id);
                $record->delete();
            } elseif ($type === 'bank') {
                $record = BloodBank::findOrFail($id);
                $record->user?->delete();
                $record->delete();
            } else {
                return response()->json(['message' => 'Invalid type'], 422);
            }

            return response()->json(['message' => ucfirst($type) . ' deleted successfully!']);
        });
    }

    // ==================== ADMIN NOTIFICATIONS ====================
    // Admin-targeted notifications (e.g. "Medical Assessment Requested",
    // request approved by a bank) need a surface or they are invisible to the
    // admin and the whole assessment/approval workflow stalls.

    public function notifications(): JsonResponse
    {
        $notifications = Notification::where('user_type', 'admin')
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead(Notification $notification): JsonResponse
    {
        if ($notification->user_type !== 'admin' || $notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllNotificationsRead(): JsonResponse
    {
        Notification::where('user_type', 'admin')
            ->where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    // ==================== MANAGE REQUESTS ====================

    public function allRequests(): JsonResponse
    {
        $requests = BloodRequest::with(['user', 'bank'])
            ->orderBy('requested_date', 'desc')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'user_name' => $r->user->full_name ?? 'N/A',
                'bank_id' => $r->bank_id,
                'bank_name' => $r->bank->bank_name ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'reason' => $r->reason,
                'urgency' => $r->urgency === 'emergency' ? 'Emergency' : 'Normal',
                'status' => $r->status,
                'requested_date' => $r->requested_date?->format('Y-m-d'),
            ]);

        return response()->json($requests);
    }

    public function updateRequestStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'fulfilled'])],
        ]);

        $bloodRequest = BloodRequest::findOrFail($id);

        if ($validated['status'] === 'approved') {
            // Only a pending request that is already assigned to a bank can be
            // approved - otherwise there is no stock to verify/reserve.
            if ($bloodRequest->status !== 'pending') {
                return response()->json(['message' => 'Only pending requests can be approved.'], 422);
            }

            if (! $bloodRequest->bank_id) {
                return response()->json(['message' => 'Cannot approve an unassigned request. Assign a blood bank first.'], 422);
            }

            // Verify stock, deduct it, mark the request approved and notify
            // within one transaction so none of these can become inconsistent.
            $result = DB::transaction(function () use ($bloodRequest) {
                $reserved = BloodStock::reserveForRequest(
                    $bloodRequest->bank_id,
                    $bloodRequest->blood_group,
                    $bloodRequest->quantity
                );

                if (! $reserved['reserved']) {
                    return $reserved;
                }

                $bloodRequest->update(['status' => 'approved']);

                Notification::create([
                    'user_type' => 'user',
                    'user_id' => $bloodRequest->user_id,
                    'title' => 'Blood Request Approved',
                    'message' => "Your blood request for {$bloodRequest->quantity} unit(s) of {$bloodRequest->blood_group} has been approved.",
                ]);

                return $reserved;
            });

            if (! $result['reserved']) {
                return response()->json([
                    'message' => "Insufficient stock! Available: {$result['available']} units, Required: {$bloodRequest->quantity} units",
                ], 422);
            }

            return response()->json(['message' => 'Request status updated to approved']);
        }

        // Reject no-op requests and transitions outside the explicit whitelist.
        // Invalid transitions change nothing: the request keeps its status,
        // stock is untouched and no status notification is sent.
        if ($bloodRequest->status === $validated['status']) {
            return response()->json(['message' => "Request is already {$validated['status']}"], 422);
        }

        if (! $bloodRequest->canTransitionTo($validated['status'])) {
            return response()->json([
                'message' => "Request cannot be moved from {$bloodRequest->status} to {$validated['status']}.",
            ], 422);
        }

        $bloodRequest->update(['status' => $validated['status']]);

        if ($validated['status'] === 'fulfilled') {
            $bloodRequest->update(['fulfilled_date' => now()]);
        }

        return response()->json(['message' => 'Request status updated to ' . $validated['status']]);
    }

    private function generateUsername(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }
}