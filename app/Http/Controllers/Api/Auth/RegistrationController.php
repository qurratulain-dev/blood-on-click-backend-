<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\BloodBank;
use App\Models\BloodStock;
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
            'role_type' => ['required', Rule::in(['user', 'blood_bank'])],
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ];

        $roleRules = match ($request->role_type) {
            'user' => [
                'full_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'blood_group' => ['required', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
                'city' => 'nullable|string|max:255',
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
            default => [],
        };

        $validated = $request->validate(array_merge($rules, $roleRules));

        $result = DB::transaction(function () use ($validated) {
            $role = match ($validated['role_type']) {
                'blood_bank' => 'Blood Bank',
                default => 'User',
            };

            $userData = [
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ];

            if ($validated['role_type'] === 'blood_bank') {
                $user = User::create($userData);
                $user->assignRole($role);
                $this->createBloodBankProfile($user, $validated);
            } else {
                $userData = array_merge($userData, [
                    'full_name' => $validated['full_name'],
                    'phone' => $validated['phone'],
                    'blood_group' => $validated['blood_group'],
                    'city' => $validated['city'] ?? null,
                    'address' => $validated['address'],
                    'age' => $validated['age'],
                    'weight' => $validated['weight'],
                    'gender' => $validated['gender'],
                    'status' => 'available',
                ]);

                $user = User::create($userData);
                $user->assignRole($role);
            }

            $role = $user->getRoleNames()->first();
            $permissions = $user->getAllPermissions()->pluck('name');

            $userData = array_merge(
                ['role' => $role, 'permissions' => $permissions],
                $validated['role_type'] === 'blood_bank' ? ['user' => $user->load('bloodBank')] : ['user' => $user]
            );

            return $userData;
        });

        return response()->json([
            'message' => 'Registration successful',
            ...$result,
        ], 201);
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
}