<?php

use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegistrationController;
use App\Http\Controllers\Api\BloodBank\BloodBankController;
use App\Http\Controllers\Api\Donor\DonorController;
use App\Http\Controllers\Api\Seeker\SeekerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/login', [LoginController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LogoutController::class, 'logout']);

    // Current user with role, permissions, and profile
    Route::get('/user', function (Request $request) {
        $user = $request->user()->load(['donor', 'bloodBank', 'seeker']);
        return response()->json([
            'user' => $user,
            'role' => $user->getRoleNames()->first(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    });

    // ==================== BLOOD BANK ROUTES ====================
    Route::middleware(['role:Blood Bank'])->prefix('blood-bank')->group(function () {
        Route::get('/dashboard', [BloodBankController::class, 'dashboard']);
        Route::get('/profile', [BloodBankController::class, 'profile']);
        Route::put('/profile', [BloodBankController::class, 'updateProfile']);
        Route::get('/stock', [BloodBankController::class, 'stock']);
        Route::put('/stock/{stock}', [BloodBankController::class, 'updateStock']);
        Route::get('/requests', [BloodBankController::class, 'requests']);
        Route::put('/requests/{blood_request}/status', [BloodBankController::class, 'updateRequestStatus']);
        Route::get('/notifications', [BloodBankController::class, 'notifications']);
        Route::put('/notifications/{notification}/read', [BloodBankController::class, 'markNotificationRead']);
        Route::get('/reports', [BloodBankController::class, 'reports']);
    });

    // ==================== DONOR ROUTES ====================
    Route::middleware(['role:Donor'])->prefix('donor')->group(function () {
        Route::get('/dashboard', [DonorController::class, 'dashboard']);
        Route::get('/profile', [DonorController::class, 'profile']);
        Route::put('/profile', [DonorController::class, 'updateProfile']);
        Route::get('/banks', [DonorController::class, 'searchBanks']);
        Route::get('/donations', [DonorController::class, 'donations']);
        Route::post('/donations', [DonorController::class, 'createDonation']);
        Route::get('/medical-reports', [DonorController::class, 'medicalReports']);
        Route::post('/medical-reports', [DonorController::class, 'uploadMedicalReport']);
        Route::get('/notifications', [DonorController::class, 'notifications']);
        Route::put('/notifications/{notification}/read', [DonorController::class, 'markNotificationRead']);
    });

    // ==================== SEEKER ROUTES ====================
    Route::middleware(['role:Seeker'])->prefix('seeker')->group(function () {
        Route::get('/dashboard', [SeekerController::class, 'dashboard']);
        Route::get('/profile', [SeekerController::class, 'profile']);
        Route::put('/profile', [SeekerController::class, 'updateProfile']);
        Route::get('/donors', [SeekerController::class, 'searchDonors']);
        Route::get('/banks', [SeekerController::class, 'searchBanks']);
        Route::get('/requests', [SeekerController::class, 'requests']);
        Route::post('/requests', [SeekerController::class, 'createRequest']);
        Route::get('/notifications', [SeekerController::class, 'notifications']);
        Route::put('/notifications/{notification}/read', [SeekerController::class, 'markNotificationRead']);
    });

    // ==================== ADMIN ROUTES ====================
    Route::middleware(['role:Admin'])->prefix('admin')->group(function () {
        Route::get('/counts', [UserManagementController::class, 'counts']);
        Route::post('/add-donor', [UserManagementController::class, 'addDonor']);
        Route::post('/add-blood-bank', [UserManagementController::class, 'addBloodBank']);
        Route::post('/add-seeker', [UserManagementController::class, 'addSeeker']);
    });
});
