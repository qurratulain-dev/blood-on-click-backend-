<?php

namespace App\Http\Controllers\Api\Donor;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\DonationHistory;
use App\Models\Donor;
use App\Models\MedicalReport;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DonorController extends Controller
{
    private function getDonor(Request $request): Donor
    {
        return $request->user()->donor;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);

        return response()->json([
            'total_donations' => DonationHistory::where('donor_id', $donor->id)->count(),
            'pending_donations' => DonationHistory::where('donor_id', $donor->id)->where('status', 'pending')->count(),
            'completed_donations' => DonationHistory::where('donor_id', $donor->id)->where('status', 'completed')->count(),
            'unread_notifications' => Notification::where('user_type', 'donor')
                ->where('user_id', $donor->id)->where('is_read', false)->count(),
            'profile' => $donor,
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);
        $donor->load('user');
        return response()->json($donor);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'age' => 'sometimes|integer|min:18|max:65',
            'weight' => 'sometimes|numeric|min:50',
            'gender' => ['sometimes', Rule::in(['Male', 'Female', 'Other'])],
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'status' => ['sometimes', Rule::in(['available', 'not_available'])],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $donor->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'donor' => $donor,
        ]);
    }

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
            $query->where('bank_name', 'like', '%' . $validated['name'] . '%');
        }

        return response()->json($query->get());
    }

    public function donations(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);
        $donations = DonationHistory::where('donor_id', $donor->id)
            ->with('bank')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($donations);
    }

    public function createDonation(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);

        $validated = $request->validate([
            'bank_id' => 'required|exists:blood_banks,id',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $donation = DonationHistory::create([
            'donor_id' => $donor->id,
            'bank_id' => $validated['bank_id'],
            'blood_group' => $validated['blood_group'],
            'quantity' => $validated['quantity'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        Notification::create([
            'user_type' => 'blood_bank',
            'user_id' => $validated['bank_id'],
            'title' => 'New Donation Request',
            'message' => "Donor {$donor->full_name} wants to donate {$validated['quantity']} unit(s) of {$validated['blood_group']} blood.",
        ]);

        return response()->json([
            'message' => 'Donation request submitted',
            'donation' => $donation,
        ], 201);
    }

    public function medicalReports(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);
        $reports = MedicalReport::where('donor_id', $donor->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports);
    }

    public function uploadMedicalReport(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'description' => 'nullable|string',
        ]);

        $path = $request->file('file')->store('medical-reports', 'public');

        $report = MedicalReport::create([
            'donor_id' => $donor->id,
            'title' => $validated['title'],
            'file_path' => $path,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Medical report uploaded',
            'report' => $report,
        ], 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        $donor = $this->getDonor($request);
        $notifications = Notification::where('user_type', 'donor')
            ->where('user_id', $donor->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        $donor = $this->getDonor($request);

        if ($notification->user_type !== 'donor' || $notification->user_id !== $donor->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read']);
    }
}
