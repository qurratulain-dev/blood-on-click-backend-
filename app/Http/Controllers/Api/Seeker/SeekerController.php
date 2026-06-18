<?php

namespace App\Http\Controllers\Api\Seeker;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\Notification;
use App\Models\Seeker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeekerController extends Controller
{
    private function getSeeker(Request $request): Seeker
    {
        return $request->user()->seeker;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);

        return response()->json([
            'total_requests' => BloodRequest::where('seeker_id', $seeker->id)->count(),
            'pending_requests' => BloodRequest::where('seeker_id', $seeker->id)->where('status', 'pending')->count(),
            'fulfilled_requests' => BloodRequest::where('seeker_id', $seeker->id)->where('status', 'fulfilled')->count(),
            'unread_notifications' => Notification::where('user_type', 'seeker')
                ->where('user_id', $seeker->id)->where('is_read', false)->count(),
            'profile' => $seeker,
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);
        $seeker->load('user');
        return response()->json($seeker);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $seeker->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'seeker' => $seeker,
        ]);
    }

    public function searchDonors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blood_group' => ['sometimes', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'city' => 'nullable|string|max:255',
        ]);

        $query = Donor::where('status', 'available');

        if ($validated['blood_group'] ?? null) {
            $query->where('blood_group', $validated['blood_group']);
        }

        if ($validated['city'] ?? null) {
            $query->where('address', 'like', '%' . $validated['city'] . '%');
        }

        return response()->json($query->with('user')->get());
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

    public function requests(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);
        $requests = BloodRequest::where('seeker_id', $seeker->id)
            ->with('bank')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    public function createRequest(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);

        $validated = $request->validate([
            'bank_id' => 'nullable|exists:blood_banks,id',
            'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string',
        ]);

        $bloodRequest = BloodRequest::create([
            'seeker_id' => $seeker->id,
            'bank_id' => $validated['bank_id'] ?? null,
            'blood_group' => $validated['blood_group'],
            'quantity' => $validated['quantity'],
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
            'requested_date' => now(),
        ]);

        if ($validated['bank_id'] ?? null) {
            Notification::create([
                'user_type' => 'blood_bank',
                'user_id' => $validated['bank_id'],
                'title' => 'New Blood Request',
                'message' => "Seeker {$seeker->full_name} needs {$validated['quantity']} unit(s) of {$validated['blood_group']} blood.",
            ]);
        }

        return response()->json([
            'message' => 'Blood request submitted',
            'request' => $bloodRequest,
        ], 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        $seeker = $this->getSeeker($request);
        $notifications = Notification::where('user_type', 'seeker')
            ->where('user_id', $seeker->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        $seeker = $this->getSeeker($request);

        if ($notification->user_type !== 'seeker' || $notification->user_id !== $seeker->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read']);
    }
}
