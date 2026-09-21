<?php

namespace App\Http\Controllers\Api\BloodBank;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BloodBankController extends Controller
{
    private function getBank(Request $request): BloodBank
    {
        return $request->user()->bloodBank;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        $bankId = $bank->id;

        $totalUnits = $bank->bloodStocks()->sum('quantity');
        $totalDonations = DonationHistory::where('bank_id', $bankId)->count();
        $totalDonatedUnits = DonationHistory::where('bank_id', $bankId)->sum('quantity');
        $pendingRequests = BloodRequest::where('bank_id', $bankId)->where('status', 'pending')->count();

        $stockByGroup = $bank->bloodStocks()->orderBy('blood_group')->get()->map(fn ($s) => [
            'id' => $s->id,
            'blood_group' => $s->blood_group,
            'quantity' => $s->quantity,
            'status' => $s->quantity < 10 ? 'Critical' : ($s->quantity < 30 ? 'Low' : 'Adequate'),
        ]);

        $recentRequests = BloodRequest::where('bank_id', $bankId)
            ->with('user')
            ->orderBy('requested_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'user_name' => $r->user->full_name ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'urgency' => $r->urgency === 'emergency' ? 'Emergency' : 'Normal',
                'requested_date' => $r->requested_date?->format('Y-m-d'),
                'status' => $r->status,
            ]);

        return response()->json([
            'total_units' => $totalUnits,
            'stock_by_group' => $stockByGroup,
            'total_donations' => $totalDonations,
            'total_donated_units' => $totalDonatedUnits,
            'pending_requests' => $pendingRequests,
            'recent_requests' => $recentRequests,
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        $bank->load('user');
        return response()->json($bank);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);

        $validated = $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:1000',
            'registration_number' => 'sometimes|string|max:255',
            'license_number' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $bank->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'bank' => $bank,
        ]);
    }

    public function stock(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        return response()->json($bank->bloodStocks()->orderBy('blood_group')->get());
    }

    public function updateStock(Request $request, BloodStock $stock): JsonResponse
    {
        $bank = $this->getBank($request);

        if ($stock->bank_id !== $bank->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $previousQty = $stock->quantity;
        $stock->update($validated);

        // Create notification when stock drops to critical level
        if ($validated['quantity'] < 10 && $previousQty >= 10) {
            Notification::create([
                'user_type' => 'blood_bank',
                'user_id' => $bank->id,
                'title' => 'Low Stock Alert',
                'message' => "Your {$stock->blood_group} blood stock is critically low ({$validated['quantity']} units). Please arrange for more donations.",
            ]);
        }

        return response()->json([
            'message' => 'Stock updated successfully',
            'stock' => $stock,
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        $requests = BloodRequest::where(function ($q) use ($bank) {
                $q->where('bank_id', $bank->id)
                  ->orWhere(function ($q2) {
                      $q2->whereNull('bank_id')->where('status', 'pending');
                  });
            })
            ->with('user')
            ->orderByRaw("CASE WHEN urgency = 'emergency' THEN 0 ELSE 1 END")
            ->orderBy('requested_date', 'asc')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'user_name' => $r->user->full_name ?? 'N/A',
                'user_phone' => $r->user->phone ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'patient_name' => $r->patient_name,
                'patient_age' => $r->patient_age,
                'hospital_name' => $r->hospital_name,
                'urgency' => $r->urgency ?? 'normal',
                'requested_date' => $r->requested_date?->format('Y-m-d h:i A'),
                'status' => $r->status,
                'bank_id' => $r->bank_id,
            ]);

        return response()->json($requests);
    }

    public function updateRequestStatus(Request $request, BloodRequest $bloodRequest): JsonResponse
    {
        $bank = $this->getBank($request);

        if ($bloodRequest->bank_id && $bloodRequest->bank_id !== $bank->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'fulfilled'])],
        ]);

        // State-aware guard: the bank workflow is pending -> approved/rejected,
        // approved -> fulfilled. No-ops and transitions outside that whitelist
        // change nothing and are rejected so approval can never consume stock
        // twice.
        if ($bloodRequest->status === $validated['status']) {
            return response()->json(['message' => "Request is already {$validated['status']}"], 422);
        }

        if (! $bloodRequest->canTransitionTo($validated['status'])) {
            return response()->json([
                'message' => "Request cannot be moved from {$bloodRequest->status} to {$validated['status']}.",
            ], 422);
        }

        if ($validated['status'] === 'approved') {
            $result = DB::transaction(function () use ($bloodRequest, $bank) {
                $reserved = BloodStock::reserveForRequest(
                    $bank->id,
                    $bloodRequest->blood_group,
                    $bloodRequest->quantity
                );

                if (! $reserved['reserved']) {
                    return $reserved;
                }

                $bloodRequest->update([
                    'status' => 'approved',
                    'bank_id' => $bank->id,
                ]);

                Notification::create([
                    'user_type' => 'user',
                    'user_id' => $bloodRequest->user_id,
                    'title' => 'Blood Request Approved',
                    'message' => "Your blood request for {$bloodRequest->quantity} unit(s) of {$bloodRequest->blood_group} has been approved. Please visit our blood bank.",
                ]);

                foreach (User::role('Admin')->get() as $admin) {
                    Notification::create([
                        'user_type' => 'admin',
                        'user_id' => $admin->id,
                        'title' => 'Request Approved',
                        'message' => "Blood request #{$bloodRequest->id} has been approved by {$bank->bank_name}.",
                    ]);
                }

                return $reserved;
            });

            if (! $result['reserved']) {
                return response()->json([
                    'message' => "Insufficient stock! Available: {$result['available']} units, Required: {$bloodRequest->quantity} units",
                ], 422);
            }
        } elseif ($validated['status'] === 'rejected') {
            $bloodRequest->update(['status' => 'rejected']);

            Notification::create([
                'user_type' => 'user',
                'user_id' => $bloodRequest->user_id,
                'title' => 'Blood Request Rejected',
                'message' => 'Your blood request has been rejected by the blood bank. Please try another bank.',
            ]);
        } elseif ($validated['status'] === 'fulfilled') {
            $bloodRequest->update([
                'status' => 'fulfilled',
                'fulfilled_date' => now(),
            ]);

            Notification::create([
                'user_type' => 'user',
                'user_id' => $bloodRequest->user_id,
                'title' => 'Blood Request Completed',
                'message' => 'Your blood request has been marked as completed. Thank you for using our service.',
            ]);
        }

        return response()->json([
            'message' => 'Request status updated successfully',
            'request' => $bloodRequest->fresh(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        $notifications = Notification::where('user_type', 'blood_bank')
            ->where('user_id', $bank->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        $bank = $this->getBank($request);

        if ($notification->user_type !== 'blood_bank' || $notification->user_id !== $bank->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function reports(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $validated['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to = $validated['to'] ?? now()->format('Y-m-d');

        $donations = DonationHistory::where('bank_id', $bank->id)
            ->whereBetween('donation_date', [$from, $to])
            ->with('user')
            ->orderBy('donation_date', 'desc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'donation_date' => $d->donation_date?->format('Y-m-d'),
                'donor_name' => $d->user->full_name ?? 'N/A',
                'blood_group' => $d->blood_group,
                'phone' => $d->user->phone ?? 'N/A',
                'quantity' => $d->quantity,
                'status' => $d->status,
            ]);

        $totalDonations = $donations->count();
        $totalUnits = $donations->sum('quantity');
        $uniqueDonors = DonationHistory::where('bank_id', $bank->id)
            ->whereBetween('donation_date', [$from, $to])
            ->distinct('user_id')
            ->count('user_id');
        $avgUnits = $totalDonations > 0 ? round($totalUnits / $totalDonations, 1) : 0;

        $groupDistribution = DonationHistory::where('bank_id', $bank->id)
            ->whereBetween('donation_date', [$from, $to])
            ->selectRaw('blood_group, COUNT(*) as count, SUM(quantity) as total_units')
            ->groupBy('blood_group')
            ->orderBy('blood_group')
            ->get();

        $monthlyTrends = DonationHistory::where('bank_id', $bank->id)
            ->whereBetween('donation_date', [$from, $to])
            ->selectRaw("DATE_FORMAT(donation_date, '%Y-%m') as month, SUM(quantity) as total_units")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'donations' => $donations,
            'total_donations' => $totalDonations,
            'total_units' => $totalUnits,
            'unique_donors' => $uniqueDonors,
            'avg_units' => $avgUnits,
            'group_distribution' => $groupDistribution,
            'monthly_trends' => $monthlyTrends,
        ]);
    }
}
