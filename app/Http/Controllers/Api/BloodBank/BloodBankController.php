<?php

namespace App\Http\Controllers\Api\BloodBank;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\DonationHistory;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with('seeker')
            ->orderBy('requested_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'seeker_name' => $r->seeker->full_name ?? 'N/A',
                'blood_group' => $r->blood_group,
                'quantity' => $r->quantity,
                'urgency' => $r->reason === 'emergency' ? 'Emergency' : 'Normal',
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
            'address' => 'sometimes|string',
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

        $stock->update($validated);

        return response()->json([
            'message' => 'Stock updated successfully',
            'stock' => $stock,
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $bank = $this->getBank($request);
        $requests = BloodRequest::where('bank_id', $bank->id)
            ->with('seeker')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    public function updateRequestStatus(Request $request, BloodRequest $bloodRequest): JsonResponse
    {
        $bank = $this->getBank($request);

        if ($bloodRequest->bank_id !== $bank->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'fulfilled'])],
        ]);

        $bloodRequest->update([
            'status' => $validated['status'],
            'fulfilled_date' => $validated['status'] === 'fulfilled' ? now() : null,
        ]);

        if ($validated['status'] === 'fulfilled') {
            $stock = BloodStock::where('bank_id', $bank->id)
                ->where('blood_group', $bloodRequest->blood_group)
                ->first();
            if ($stock) {
                $stock->decrement('quantity', $bloodRequest->quantity);
            }
        }

        return response()->json([
            'message' => 'Request status updated',
            'request' => $bloodRequest,
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

        $from = $validated['from'] ?? now()->startOfMonth();
        $to = $validated['to'] ?? now();

        $donations = DonationHistory::where('bank_id', $bank->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('donor')
            ->orderBy('created_at', 'desc')
            ->get();

        $requests = BloodRequest::where('bank_id', $bank->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('seeker')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'donations' => $donations,
            'requests' => $requests,
            'total_donations' => $donations->count(),
            'total_requests' => $requests->count(),
        ]);
    }
}
