<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TrustedDeviceController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Public — rate-limited only.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

Route::middleware('throttle:two-factor-verify')->group(function () {
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/setup/confirm', [TwoFactorController::class, 'confirmSetup']);
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify']);
});

// Authenticated.
Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/session-settings', [AuthController::class, 'sessionSettings']);
    Route::post('/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:password-confirm');
    Route::post('/2fa/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:password-confirm');
    Route::get('/trusted-devices', [TrustedDeviceController::class, 'index']);
    Route::delete('/trusted-devices', [TrustedDeviceController::class, 'destroyAll']);
    Route::delete('/trusted-devices/{device}', [TrustedDeviceController::class, 'destroy'])->whereNumber('device');
});
