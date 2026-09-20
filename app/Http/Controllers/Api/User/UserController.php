<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\DonationHistory;
use App\Models\MedicalReport;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function getUser(Request $request): User
    {
        return $request->user();
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        $userId = $user->id;

        // Donation stats
        $totalDonations = DonationHistory::where('user_id', $userId)->count();
        $completedDonations = DonationHistory::where('user_id', $userId)->where('status', 'completed')->count();
        $pendingDonations = DonationHistory::where('user_id', $userId)->where('status', 'pending')->count();
        $totalUnits = DonationHistory::where('user_id', $userId)->sum('quantity');

        $lastDonation = DonationHistory::where('user_id', $userId)
            ->where('status', 'completed')
            ->orderBy('donation_date', 'desc')
            ->first();

        $nextEligibleDate = null;
        if ($lastDonation?->donation_date) {
            $nextEligibleDate = $lastDonation->donation_date->copy()->addDays(90)->format('Y-m-d');
        }

        $recentDonations = DonationHistory::where('user_id', $userId)
            ->with('bank')
            ->orderBy('donation_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'quantity' => $d->quantity,
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'status' => $d->status,
            ]);

        $latestMedicalReport = MedicalReport::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        // Request stats
        $totalRequests = BloodRequest::where('user_id', $userId)->count();
        $pendingRequests = BloodRequest::where('user_id', $userId)->where('status', 'pending')->count();
        $approvedRequests = BloodRequest::where('user_id', $userId)->where('status', 'approved')->count();
        $fulfilledRequests = BloodRequest::where('user_id', $userId)->where('status', 'fulfilled')->count();
        $rejectedRequests = BloodRequest::where('user_id', $userId)->where('status', 'rejected')->count();

        $recentRequests = BloodRequest::where('user_id', $userId)
            ->with('bank')
            ->orderBy('requested_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'bank_name' => $r->bank->bank_name ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'reason' => $r->reason,
                'requested_date' => $r->requested_date?->format('Y-m-d'),
                'status' => $r->status,
            ]);

        return response()->json([
            'total_donations' => $totalDonations,
            'completed_donations' => $completedDonations,
            'pending_donations' => $pendingDonations,
            'total_units_donated' => $totalUnits,
            'next_eligible_date' => $nextEligibleDate,
            'recent_donations' => $recentDonations,
            'latest_medical_report' => $latestMedicalReport,
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'approved_requests' => $approvedRequests,
            'fulfilled_requests' => $fulfilledRequests,
            'rejected_requests' => $rejectedRequests,
            'recent_requests' => $recentRequests,
            'profile' => $user->makeHidden(['email_verified_at', 'remember_token']),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        return response()->json($user->makeHidden(['email_verified_at', 'remember_token']));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getUser($request);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string|max:255',
            'age' => 'sometimes|integer|min:18|max:100',
            'weight' => 'sometimes|numeric|min:30',
            'gender' => ['sometimes', Rule::in(['Male', 'Female', 'Other'])],
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'status' => ['sometimes', Rule::in(['available', 'not_available'])],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->makeHidden(['email_verified_at', 'remember_token']),
        ]);
    }

    // ==================== BLOOD DONATION (was donor) ====================

    public function searchBanks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'city' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
        ]);

        $query = BloodBank::with('bloodStocks')->where('status', 'active');

        if ($validated['blood_group'] ?? null) {
            $query->whereHas('bloodStocks', function ($q) use ($validated) {
                $q->where('blood_group', $validated['blood_group'])->where('quantity', '>', 0);
            });
        }

        if ($validated['city'] ?? null) {
            $query->where('address', 'like', '%' . $validated['city'] . '%');
        }

        if ($validated['name'] ?? null) {
            $term = $validated['name'];
            $query->where(function ($q) use ($term) {
                $q->where('bank_name', 'like', '%' . $term . '%')
                  ->orWhere('address', 'like', '%' . $term . '%');
            });
        }

        return response()->json($query->get());
    }

    public function donations(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        $donations = DonationHistory::where('user_id', $user->id)
            ->with('bank')
            ->orderBy('donation_date', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'bank_id' => $d->bank_id,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'bank_address' => $d->bank->address ?? 'N/A',
                'blood_group' => $d->blood_group,
                'quantity' => $d->quantity,
                'status' => $d->status,
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'donation_date_formatted' => $d->donation_date?->format('d M Y'),
                'medical_report_path' => $d->medical_report_path,
                'medical_report_url' => $d->medical_report_path ? url('storage/' . $d->medical_report_path) : null,
                'notes' => $d->notes,
                'admin_message' => $d->admin_message,
                'created_at' => $d->created_at?->format('Y-m-d h:i A'),
            ]);

        return response()->json($donations);
    }

    public function createDonation(Request $request): JsonResponse
    {
        $user = $this->getUser($request);

        $validated = $request->validate([
            'bank_id' => 'required|exists:blood_banks,id',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'quantity' => 'required|integer|min:1|max:2',
            'donation_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $donation = DonationHistory::create([
            'user_id' => $user->id,
            'bank_id' => $validated['bank_id'],
            'blood_group' => $validated['blood_group'],
            'quantity' => $validated['quantity'],
            'donation_date' => $validated['donation_date'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        Notification::create([
            'user_type' => 'blood_bank',
            'user_id' => $validated['bank_id'],
            'title' => 'New Donation Request',
            'message' => "{$user->full_name} wants to donate {$validated['quantity']} unit(s) of {$validated['blood_group']} blood.",
        ]);

        return response()->json([
            'message' => 'Donation request submitted successfully! Waiting for admin verification.',
            'donation' => $donation,
        ], 201);
    }

    public function medicalReports(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        $reports = MedicalReport::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports);
    }

    public function uploadMedicalReport(Request $request): JsonResponse
    {
        $user = $this->getUser($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'description' => 'nullable|string',
        ]);

        $path = $request->file('file')->store('medical-reports', 'public');

        $report = MedicalReport::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'file_path' => $path,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Medical report uploaded',
            'report' => $report,
        ], 201);
    }

    public function uploadDonationReport(Request $request, DonationHistory $donation): JsonResponse
    {
        $user = $this->getUser($request);

        if ($donation->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($donation->status !== 'completed') {
            return response()->json(['message' => 'Can only upload report for completed donations'], 422);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $path = $request->file('file')->store('donation-reports', 'public');

        $donation->update(['medical_report_path' => $path]);

        return response()->json([
            'message' => 'Medical report uploaded for donation',
            'donation_id' => $donation->id,
            'medical_report_url' => url('storage/' . $path),
        ], 201);
    }

    // ==================== BLOOD REQUEST (was seeker) ====================

    public function searchDonors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'city' => 'nullable|string|max:255',
        ]);

        $query = User::where('status', 'available')
            ->where('blood_group', $validated['blood_group']);

        if ($validated['city'] ?? null) {
            $query->where(function ($q) use ($validated) {
                $q->where('address', 'like', '%' . $validated['city'] . '%')
                  ->orWhere('city', 'like', '%' . $validated['city'] . '%');
            });
        }

        $donors = $query->get()->map(function ($user) {
            $lastDonation = DonationHistory::where('user_id', $user->id)
                ->where('status', 'completed')
                ->orderBy('donation_date', 'desc')
                ->first();
            return [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'phone' => $user->phone,
                'blood_group' => $user->blood_group,
                'address' => $user->address,
                'city' => $user->city,
                'age' => $user->age,
                'weight' => $user->weight,
                'gender' => $user->gender,
                'last_donation_date' => $lastDonation?->donation_date?->format('Y-m-d'),
                'last_donation_date_formatted' => $lastDonation?->donation_date?->format('d M Y'),
            ];
        })->sortBy('last_donation_date')->values();

        return response()->json([
            'donors' => $donors,
            'count' => $donors->count(),
            'blood_group' => $validated['blood_group'],
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        $requests = BloodRequest::where('user_id', $user->id)
            ->with('bank')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    public function createRequest(Request $request): JsonResponse
    {
        $user = $this->getUser($request);

        $validated = $request->validate([
            'bank_id' => 'nullable|exists:blood_banks,id',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'quantity' => 'required|integer|min:1',
            'patient_name' => 'nullable|string|max:255',
            'patient_age' => 'nullable|integer|min:0|max:150',
            'hospital_name' => 'nullable|string|max:255',
            'urgency' => ['nullable', Rule::in(['normal', 'emergency'])],
            'reason' => 'nullable|string',
        ]);

        $bloodRequest = BloodRequest::create([
            'user_id' => $user->id,
            'bank_id' => $validated['bank_id'] ?? null,
            'blood_group' => $validated['blood_group'],
            'quantity' => $validated['quantity'],
            'patient_name' => $validated['patient_name'] ?? null,
            'patient_age' => $validated['patient_age'] ?? null,
            'hospital_name' => $validated['hospital_name'] ?? null,
            'urgency' => $validated['urgency'] ?? 'normal',
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
            'requested_date' => now(),
        ]);

        if ($validated['bank_id'] ?? null) {
            Notification::create([
                'user_type' => 'blood_bank',
                'user_id' => $validated['bank_id'],
                'title' => 'New Blood Request',
                'message' => "{$user->full_name} needs {$validated['quantity']} unit(s) of {$validated['blood_group']} blood.",
            ]);
        }

        return response()->json([
            'message' => 'Blood request submitted',
            'request' => $bloodRequest,
        ], 201);
    }

    // ==================== NOTIFICATIONS ====================

    public function notifications(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        $notifications = Notification::where('user_type', 'user')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $this->getUser($request);

        if ($notification->user_type !== 'user' || $notification->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $user = $this->getUser($request);

        Notification::where('user_type', 'user')
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function deleteNotification(Request $request, Notification $notification): JsonResponse
    {
        $user = $this->getUser($request);

        if ($notification->user_type !== 'user' || $notification->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }
}