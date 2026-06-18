<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodStock;
use App\Models\Donor;
use App\Models\Notification;
use App\Models\Seeker;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
