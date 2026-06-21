<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodStock;
use App\Models\Donor;
use App\Models\Seeker;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $rules = [
            'role_type' => ['required', Rule::in(['donor', 'blood_bank', 'seeker'])],
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ];

        $roleRules = match ($request->role_type) {
            'donor' => [
                'full_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
                'address' => 'required|string',
                'age' => 'required|integer|min:18|max:100',
                'weight' => 'required|numeric|min:30|max:300',
                'gender' => ['required', Rule::in(['Male', 'Female', 'Other'])],
            ],
            'blood_bank' => [
                'bank_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'address' => 'required|string',
                'registration_number' => 'required|string|max:255',
            ],
            'seeker' => [
                'full_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'address' => 'required|string',
            ],
            default => [],
        };

        $validated = $request->validate(array_merge($rules, $roleRules));

        $result = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $role = match ($validated['role_type']) {
                'donor' => 'Donor',
                'blood_bank' => 'Blood Bank',
                'seeker' => 'Seeker',
                default => 'User',
            };

            $user->assignRole($role);

            match ($validated['role_type']) {
                'donor' => $this->createDonorProfile($user, $validated),
                'blood_bank' => $this->createBloodBankProfile($user, $validated),
                'seeker' => $this->createSeekerProfile($user, $validated),
                default => null,
            };

            $role = $user->getRoleNames()->first();
            $permissions = $user->getAllPermissions()->pluck('name');

            return [
                'user' => $user->load(match ($validated['role_type']) {
                    'blood_bank' => 'bloodBank',
                    default => $validated['role_type'],
                }),
                'role' => $role,
                'permissions' => $permissions,
            ];
        });

        return response()->json([
            'message' => 'Registration successful',
            ...$result,
        ], 201);
    }

    private function createDonorProfile(User $user, array $data): void
    {
        Donor::create([
            'user_id' => $user->id,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'blood_group' => $data['blood_group'],
            'address' => $data['address'],
            'age' => $data['age'],
            'weight' => $data['weight'],
            'gender' => $data['gender'],
        ]);
    }

    private function createBloodBankProfile(User $user, array $data): void
    {
        $bank = BloodBank::create([
            'user_id' => $user->id,
            'bank_name' => $data['bank_name'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'registration_number' => $data['registration_number'],
        ]);

        $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        foreach ($groups as $group) {
            BloodStock::create([
                'bank_id' => $bank->id,
                'blood_group' => $group,
                'quantity' => 0,
            ]);
        }
    }

    private function createSeekerProfile(User $user, array $data): void
    {
        Seeker::create([
            'user_id' => $user->id,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'address' => $data['address'],
        ]);
    }
}
