<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\Donor;
use App\Models\MedicalAssessment;
use App\Models\Notification;
use App\Models\Seeker;
use App\Models\User;
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
            'donors' => Donor::count(),
            'blood_banks' => BloodBank::count(),
            'seekers' => Seeker::count(),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $totalDonors = Donor::count();
        $totalBanks = BloodBank::count();
        $totalSeekers = Seeker::count();
        $totalRequests = BloodRequest::count();
        $pendingRequests = BloodRequest::where('status', 'pending')->count();

        // Blood stock summary grouped by blood group
        $stockSummary = BloodStock::selectRaw('blood_group, SUM(quantity) as total')
            ->groupBy('blood_group')
            ->orderBy('blood_group')
            ->get();

        // Recent 5 donations with donor & bank info
        $recentDonations = DonationHistory::with(['donor', 'bank'])
            ->orderBy('donation_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'donor_name' => $d->donor->full_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'quantity' => $d->quantity,
                'status' => $d->status,
            ]);

        return response()->json([
            'donors' => $totalDonors,
            'blood_banks' => $totalBanks,
            'seekers' => $totalSeekers,
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'stock_summary' => $stockSummary,
            'recent_donations' => $recentDonations,
        ]);
    }

    public function addDonor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'required|string|max:20',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'address' => 'required|string',
            'age' => 'required|integer|min:18|max:65',
            'weight' => 'required|numeric|min:50',
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
            ]);
            $user->assignRole('Donor');

            $donor = Donor::create([
                'user_id' => $user->id,
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'blood_group' => $validated['blood_group'],
                'address' => $validated['address'],
                'age' => $validated['age'],
                'weight' => $validated['weight'],
                'gender' => $validated['gender'],
                'status' => $validated['status'] ?? 'available',
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);

            Notification::create([
                'user_type' => 'donor',
                'user_id' => $donor->id,
                'title' => 'Welcome to Blood on Click',
                'message' => 'Your account has been created by admin. You can now login and start donating blood.',
            ]);

            return response()->json([
                'message' => 'Donor added successfully',
                'donor' => $donor,
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

    public function addSeeker(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['full_name'],
                'username' => $this->generateUsername($validated['full_name']),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->assignRole('Seeker');

            $seeker = Seeker::create([
                'user_id' => $user->id,
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);

            Notification::create([
                'user_type' => 'seeker',
                'user_id' => $seeker->id,
                'title' => 'Welcome to Blood on Click',
                'message' => 'Your account has been created by admin. You can now login and request blood.',
            ]);

            return response()->json([
                'message' => 'Seeker added successfully',
                'seeker' => $seeker,
            ], 201);
        });
    }

    public function donationDetails(int $id): JsonResponse
    {
        $donation = DonationHistory::with(['donor', 'bank'])
            ->findOrFail($id);

        $latestAssessment = MedicalAssessment::where('donor_id', $donation->donor_id)
            ->orderBy('assessment_date', 'desc')
            ->first();

        return response()->json([
            'id' => $donation->id,
            'donor_id' => $donation->donor_id,
            'donor_name' => $donation->donor->full_name ?? 'N/A',
            'donor_email' => $donation->donor->user->email ?? 'N/A',
            'donor_phone' => $donation->donor->phone ?? 'N/A',
            'blood_group' => $donation->blood_group,
            'age' => $donation->donor->age,
            'weight' => $donation->donor->weight,
            'gender' => $donation->donor->gender,
            'donor_address' => $donation->donor->address,
            'bank_name' => $donation->bank->bank_name ?? 'N/A',
            'bank_address' => $donation->bank->address ?? 'N/A',
            'bank_phone' => $donation->bank->phone ?? 'N/A',
            'quantity' => $donation->quantity,
            'donation_date' => $donation->donation_date?->format('Y-m-d'),
            'status' => $donation->status,
            'medical_report_path' => $donation->medical_report_path,
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

        $donation = DonationHistory::with(['donor.user', 'bank'])
            ->findOrFail($validated['donation_id']);

        $latestAssessment = MedicalAssessment::where('donor_id', $donation->donor_id)
            ->orderBy('assessment_date', 'desc')
            ->first();

        $donationDate = $donation->donation_date?->format('d/m/Y') ?? 'N/A';
        $reportDate = now()->format('d/m/Y h:i A');
        $reportNo = 'MR-' . now()->year . '-' . str_pad((string)$donation->id, 6, '0', STR_PAD_LEFT);

        $hemoglobin = $latestAssessment?->hemoglobin ? $latestAssessment->hemoglobin . ' g/dL' : 'Not recorded';
        $bloodPressure = $latestAssessment?->blood_pressure ?? 'Not recorded';
        $eligibility = $latestAssessment ? ($latestAssessment->is_eligible ? 'Eligible' : 'Not Eligible') : 'Eligible';
        $weight = $latestAssessment?->donor->weight ?? $donation->donor->weight ?? 'N/A';
        $gender = $donation->donor->gender ?? 'N/A';

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
            'user_type' => 'donor',
            'user_id' => $donation->donor_id,
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

        $query = DonationHistory::with(['donor', 'bank']);

        if ($status && in_array($status, ['pending', 'completed', 'cancelled'])) {
            $query->where('status', $status);
        }

        $donations = $query->orderBy('donation_date', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'donor_id' => $d->donor_id,
                'donor_name' => $d->donor->full_name ?? 'N/A',
                'donor_email' => $d->donor->user->email ?? 'N/A',
                'donor_phone' => $d->donor->phone ?? 'N/A',
                'bank_id' => $d->bank_id,
                'bank_name' => $d->bank->bank_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'quantity' => $d->quantity,
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'status' => $d->status,
                'has_medical_report' => !is_null($d->medical_report_path),
                'medical_report_url' => $d->medical_report_path ? asset('storage/' . $d->medical_report_path) : null,
                'created_at' => $d->created_at?->format('Y-m-d h:i A'),
            ]);

        $pendingCount = DonationHistory::where('status', 'pending')->count();
        $completedCount = DonationHistory::where('status', 'completed')->count();
        $cancelledCount = DonationHistory::where('status', 'cancelled')->count();
        $totalUnits = DonationHistory::where('status', 'completed')->sum('quantity');

        return response()->json([
            'donations' => $donations,
            'counts' => [
                'pending' => $pendingCount,
                'completed' => $completedCount,
                'cancelled' => $cancelledCount,
                'total_units' => $totalUnits,
            ],
        ]);
    }

    public function verifyDonation(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $donation = DonationHistory::with('donor')->findOrFail($id);

            if ($donation->status !== 'pending') {
                return response()->json(['message' => 'Only pending donations can be verified'], 422);
            }

            $donation->update(['status' => 'completed']);

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

            $donation->donor->update(['status' => 'available']);

            $message = "Thank you for your donation! Your donation of {$donation->quantity} unit(s) of {$donation->blood_group} blood has been verified.";
            Notification::create([
                'user_type' => 'donor',
                'user_id' => $donation->donor_id,
                'title' => 'Donation Verified',
                'message' => $message,
            ]);

            return response()->json(['message' => 'Donation verified successfully! Stock updated.']);
        });
    }

    public function rejectDonation(int $id): JsonResponse
    {
        $donation = DonationHistory::findOrFail($id);
        $donation->update(['status' => 'cancelled']);

        Notification::create([
            'user_type' => 'donor',
            'user_id' => $donation->donor_id,
            'title' => 'Donation Rejected',
            'message' => 'Your donation record has been rejected. Please contact admin.',
        ]);

        return response()->json(['message' => 'Donation rejected successfully!']);
    }

    public function moveToPending(int $id): JsonResponse
    {
        $donation = DonationHistory::findOrFail($id);
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
            'user_type' => 'donor',
            'user_id' => $donation->donor_id,
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
            'user_type' => ['required', Rule::in(['donor', 'seeker'])],
            'user_id' => 'required|integer',
            'bank_id' => 'required|exists:blood_banks,id',
        ]);

        $bank = BloodBank::findOrFail($validated['bank_id']);

        Notification::create([
            'user_type' => $validated['user_type'],
            'user_id' => $validated['user_id'],
            'title' => 'Blood Bank Recommendation',
            'message' => "We recommend you to visit {$bank->bank_name} for your blood needs.",
        ]);

        return response()->json(['message' => 'Recommendation sent successfully!']);
    }

    public function recommendationData(): JsonResponse
    {
        $donors = Donor::select('id', 'full_name', 'blood_group', 'address')->orderBy('full_name')->get();
        $seekers = Seeker::select('id', 'full_name', 'address')->orderBy('full_name')->get();
        $banks = BloodBank::where('status', 'active')
            ->select('id', 'bank_name', 'address')
            ->orderBy('bank_name')
            ->get();

        return response()->json([
            'donors' => $donors,
            'seekers' => $seekers,
            'banks' => $banks,
        ]);
    }

    // ==================== DONOR ASSESSMENT HISTORY ====================

    public function donorAssessmentHistory(int $donorId): JsonResponse
    {
        $assessments = MedicalAssessment::with('assessedBy')
            ->where('donor_id', $donorId)
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
        $donors = Donor::with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'full_name' => $d->full_name,
                'email' => $d->user?->email ?? 'N/A',
                'phone' => $d->phone,
                'blood_group' => $d->blood_group,
                'age' => $d->age,
                'weight' => $d->weight,
                'status' => $d->status,
                'user_type' => 'donor',
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
                'user_type' => 'bank',
            ]);

        $seekers = Seeker::with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'email' => $s->user?->email ?? 'N/A',
                'phone' => $s->phone,
                'address' => $s->address,
                'user_type' => 'seeker',
            ]);

        return response()->json([
            'donors' => $donors,
            'blood_banks' => $banks,
            'seekers' => $seekers,
            'counts' => [
                'donors' => $donors->count(),
                'banks' => $banks->count(),
                'seekers' => $seekers->count(),
            ],
        ]);
    }

    public function updateDonor(Request $request, int $id): JsonResponse
    {
        $donor = Donor::findOrFail($id);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'address' => 'sometimes|string',
            'age' => 'sometimes|integer|min:18|max:65',
            'weight' => 'sometimes|numeric|min:50',
            'gender' => ['sometimes', Rule::in(['Male', 'Female', 'Other'])],
            'status' => ['sometimes', Rule::in(['available', 'not_available'])],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $donor->update($validated);

        Notification::create([
            'user_type' => 'donor',
            'user_id' => $donor->id,
            'title' => 'Profile Updated',
            'message' => 'Your profile has been updated by admin.',
        ]);

        return response()->json(['message' => 'Donor updated successfully!', 'donor' => $donor]);
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
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $bank->update($validated);

        Notification::create([
            'user_type' => 'blood_bank',
            'user_id' => $bank->id,
            'title' => 'Profile Updated',
            'message' => 'Your blood bank profile has been updated by admin.',
        ]);

        return response()->json(['message' => 'Blood Bank updated successfully!', 'bank' => $bank]);
    }

    public function updateSeeker(Request $request, int $id): JsonResponse
    {
        $seeker = Seeker::findOrFail($id);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $seeker->update($validated);

        Notification::create([
            'user_type' => 'seeker',
            'user_id' => $seeker->id,
            'title' => 'Profile Updated',
            'message' => 'Your profile has been updated by admin.',
        ]);

        return response()->json(['message' => 'Seeker updated successfully!', 'seeker' => $seeker]);
    }

    public function deleteUser(string $type, int $id): JsonResponse
    {
        return DB::transaction(function () use ($type, $id) {
            if ($type === 'donor') {
                $record = Donor::findOrFail($id);
                $record->user()?->delete();
                $record->delete();
            } elseif ($type === 'bank') {
                $record = BloodBank::findOrFail($id);
                $record->user()?->delete();
                $record->delete();
            } elseif ($type === 'seeker') {
                $record = Seeker::findOrFail($id);
                $record->user()?->delete();
                $record->delete();
            } else {
                return response()->json(['message' => 'Invalid type'], 422);
            }

            return response()->json(['message' => ucfirst($type) . ' deleted successfully!']);
        });
    }

    // ==================== MANAGE REQUESTS ====================

    public function allRequests(): JsonResponse
    {
        $requests = BloodRequest::with(['seeker', 'bank'])
            ->orderBy('requested_date', 'desc')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'seeker_name' => $r->seeker->full_name ?? 'N/A',
                'bank_name' => $r->bank->bank_name ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'reason' => $r->reason,
                'urgency' => $r->reason === 'emergency' ? 'Emergency' : 'Normal',
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
