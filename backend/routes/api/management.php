<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\Management\SecuritySettingController;
use App\Http\Controllers\Api\Management\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function () {
    // Administration: account management (users.manage; UserPolicy re-checks).
    Route::middleware('permission:'.Permission::UsersManage->value)->group(function () {
        Route::post('/users/{user}/2fa/reset', [UserController::class, 'resetTwoFactor'])->whereNumber('user');
    });

    // Administration: sign-in and session security settings.
    Route::middleware('permission:'.Permission::SecuritySettingsManage->value)->group(function () {
        Route::get('/settings/security', [SecuritySettingController::class, 'index']);
        Route::put('/settings/security', [SecuritySettingController::class, 'update']);
    });
});
