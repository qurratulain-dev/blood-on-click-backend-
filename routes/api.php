<?php

use App\Http\Controllers\Api\Admin\AssessmentController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegistrationController;
use App\Http\Controllers\Api\BloodBank\BloodBankController;
use App\Http\Controllers\Api\User\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [RegistrationController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::post('/logout', [LogoutController::class, 'logout']);

    // Current user with role, permissions, and profile
    Route::get('/user', function (Request $request) {
        $user = $request->user()->load('bloodBank');
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

    // ==================== USER ROUTES (single User account covers donating + requesting) ====================
    Route::middleware(['role:User'])->prefix('user')->group(function () {
        Route::get('/dashboard', [UserController::class, 'dashboard']);
        Route::get('/profile', [UserController::class, 'profile']);
        Route::put('/profile', [UserController::class, 'updateProfile']);
        Route::get('/donors', [UserController::class, 'searchDonors']);
        Route::get('/donors/{donor}/contact', [UserController::class, 'donorContact']);
        Route::get('/banks', [UserController::class, 'searchBanks']);
        Route::get('/eligibility', [UserController::class, 'eligibility']);
        Route::post('/assessment-requests', [UserController::class, 'requestAssessment']);
        Route::get('/donations', [UserController::class, 'donations']);
        Route::post('/donations', [UserController::class, 'createDonation']);
        Route::get('/medical-reports', [UserController::class, 'medicalReports']);
        Route::post('/medical-reports', [UserController::class, 'uploadMedicalReport']);
        Route::post('/donations/{donation}/upload-report', [UserController::class, 'uploadDonationReport']);
        Route::get('/requests', [UserController::class, 'requests']);
        Route::post('/requests', [UserController::class, 'createRequest']);
        Route::get('/notifications', [UserController::class, 'notifications']);
        Route::put('/notifications/read-all', [UserController::class, 'markAllNotificationsRead']);
        Route::put('/notifications/{notification}/read', [UserController::class, 'markNotificationRead']);
        Route::delete('/notifications/{notification}', [UserController::class, 'deleteNotification']);
    });

    // ==================== ADMIN ROUTES ====================
    Route::middleware(['role:Admin'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [UserManagementController::class, 'dashboard']);
        Route::get('/counts', [UserManagementController::class, 'counts']);
        Route::get('/assessments', [AssessmentController::class, 'index']);
        Route::post('/assessments', [AssessmentController::class, 'store']);
        Route::get('/assessments/user/{userId}', [UserManagementController::class, 'userAssessmentHistory']);
        Route::get('/assessments/{assessment}', [AssessmentController::class, 'show']);
        Route::get('/assessment-donors', [AssessmentController::class, 'donors']);
        Route::post('/recommend-bank', [UserManagementController::class, 'sendRecommendation']);
        Route::get('/recommend-bank/data', [UserManagementController::class, 'recommendationData']);
        Route::get('/donations', [UserManagementController::class, 'donations']);
        Route::get('/donations/{donation}', [UserManagementController::class, 'donationDetails']);
        Route::post('/donations/generate-report', [UserManagementController::class, 'generateMedicalReport']);
        Route::post('/donations/{donation}/verify', [UserManagementController::class, 'verifyDonation']);
        Route::post('/donations/{donation}/reject', [UserManagementController::class, 'rejectDonation']);
        Route::post('/donations/{donation}/pending', [UserManagementController::class, 'moveToPending']);
        Route::post('/donations/upload-report', [UserManagementController::class, 'uploadMedicalReport']);
        Route::get('/users', [UserManagementController::class, 'users']);
        Route::put('/users/{user}', [UserManagementController::class, 'updateUser']);
        Route::put('/blood-banks/{bloodBank}', [UserManagementController::class, 'updateBloodBank']);
        Route::delete('/users/{type}/{id}', [UserManagementController::class, 'deleteUser']);
        Route::get('/requests', [UserManagementController::class, 'allRequests']);
        Route::put('/requests/{bloodRequest}/status', [UserManagementController::class, 'updateRequestStatus']);
        Route::get('/notifications', [UserManagementController::class, 'notifications']);
        Route::put('/notifications/read-all', [UserManagementController::class, 'markAllNotificationsRead']);
        Route::put('/notifications/{notification}/read', [UserManagementController::class, 'markNotificationRead']);
        Route::post('/add-user', [UserManagementController::class, 'addUser']);
        Route::post('/add-blood-bank', [UserManagementController::class, 'addBloodBank']);
    });
});