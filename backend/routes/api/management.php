<?php

use App\Enums\Permission as P;
use App\Http\Controllers\Api\Management\AuditLogController;
use App\Http\Controllers\Api\Management\DashboardController;
use App\Http\Controllers\Api\Management\EquipmentController;
use App\Http\Controllers\Api\Management\EquipmentTransactionController;
use App\Http\Controllers\Api\Management\InternetConnectivitySurveyController;
use App\Http\Controllers\Api\Management\IspAccountController;
use App\Http\Controllers\Api\Management\IspSpeedTestController;
use App\Http\Controllers\Api\Management\IspSubscriptionCostController;
use App\Http\Controllers\Api\Management\OfficeController;
use App\Http\Controllers\Api\Management\PermissionController;
use App\Http\Controllers\Api\Management\PersonnelController;
use App\Http\Controllers\Api\Management\ReferenceDataController;
use App\Http\Controllers\Api\Management\RoleController;
use App\Http\Controllers\Api\Management\SecuritySettingController;
use App\Http\Controllers\Api\Management\StakeholderProfileController;
use App\Http\Controllers\Api\Management\UserController;
use Illuminate\Support\Facades\Route;

/**
 * `permission:a|b` middleware: the route-level fast fail (any one of the
 * listed permissions passes). The Policy re-checked inside every action
 * stays the authoritative gate.
 */
$can = fn (P ...$permissions): string => 'permission:'.implode('|', array_map(fn (P $p) => $p->value, $permissions));

Route::middleware(['auth:sanctum', 'throttle:authenticated-api'])->group(function () use ($can) {
    // Authenticated: each section is included only for holders of its .view permission.
    Route::get('/dashboard/summary', DashboardController::class);

    // Permission-protected: personnel.*.
    Route::get('/personnel/options', [PersonnelController::class, 'options'])->middleware($can(P::PersonnelView, P::PersonnelCreate, P::PersonnelEdit));
    Route::get('/personnel', [PersonnelController::class, 'index'])->middleware($can(P::PersonnelView));
    Route::get('/personnel/{personnel}', [PersonnelController::class, 'show'])->middleware($can(P::PersonnelView))->whereNumber('personnel');
    Route::post('/personnel', [PersonnelController::class, 'store'])->middleware($can(P::PersonnelCreate));
    Route::put('/personnel/{personnel}', [PersonnelController::class, 'update'])->middleware($can(P::PersonnelEdit))->whereNumber('personnel');
    Route::delete('/personnel/{personnel}', [PersonnelController::class, 'destroy'])->middleware($can(P::PersonnelDelete))->whereNumber('personnel');

    // Permission-protected: equipment.*. Transactions are append-only (store only).
    Route::get('/equipment/options', [EquipmentController::class, 'options'])->middleware($can(P::EquipmentView));
    Route::get('/equipment', [EquipmentController::class, 'index'])->middleware($can(P::EquipmentView));
    Route::get('/equipment/{equipment}', [EquipmentController::class, 'show'])->middleware($can(P::EquipmentView))->whereNumber('equipment');
    Route::get('/equipment/{equipment}/qr-code', [EquipmentController::class, 'qrCode'])->middleware($can(P::EquipmentView))->whereNumber('equipment');
    Route::post('/equipment', [EquipmentController::class, 'store'])->middleware($can(P::EquipmentCreate));
    Route::put('/equipment/{equipment}', [EquipmentController::class, 'update'])->middleware($can(P::EquipmentEdit))->whereNumber('equipment');
    Route::delete('/equipment/{equipment}', [EquipmentController::class, 'destroy'])->middleware($can(P::EquipmentDelete))->whereNumber('equipment');
    Route::post('/equipment/{equipment}/transactions', [EquipmentTransactionController::class, 'store'])->middleware($can(P::EquipmentTransactionsCreate))->whereNumber('equipment');

    // Permission-protected: isp_accounts.*. Speed tests and subscription
    // costs are append-only logs authorized against the parent's `update`.
    Route::get('/isp-accounts/options', [IspAccountController::class, 'options'])->middleware($can(P::IspAccountsView));
    Route::get('/isp-accounts', [IspAccountController::class, 'index'])->middleware($can(P::IspAccountsView));
    Route::get('/isp-accounts/{ispAccount}', [IspAccountController::class, 'show'])->middleware($can(P::IspAccountsView))->whereNumber('ispAccount');
    Route::post('/isp-accounts', [IspAccountController::class, 'store'])->middleware($can(P::IspAccountsCreate));
    Route::put('/isp-accounts/{ispAccount}', [IspAccountController::class, 'update'])->middleware($can(P::IspAccountsEdit))->whereNumber('ispAccount');
    Route::delete('/isp-accounts/{ispAccount}', [IspAccountController::class, 'destroy'])->middleware($can(P::IspAccountsDelete))->whereNumber('ispAccount');
    Route::post('/isp-accounts/{ispAccount}/speed-tests', [IspSpeedTestController::class, 'store'])->middleware($can(P::IspAccountsEdit))->whereNumber('ispAccount');
    Route::post('/isp-accounts/{ispAccount}/subscription-costs', [IspSubscriptionCostController::class, 'store'])->middleware($can(P::IspAccountsEdit))->whereNumber('ispAccount');

    // Permission-protected: office.*. Deleting an office still in use answers 409.
    Route::get('/offices', [OfficeController::class, 'index'])->middleware($can(P::OfficeView));
    Route::get('/offices/{office}', [OfficeController::class, 'show'])->middleware($can(P::OfficeView))->whereNumber('office');
    Route::post('/offices', [OfficeController::class, 'store'])->middleware($can(P::OfficeCreate));
    Route::put('/offices/{office}', [OfficeController::class, 'update'])->middleware($can(P::OfficeEdit))->whereNumber('office');
    Route::delete('/offices/{office}', [OfficeController::class, 'destroy'])->middleware($can(P::OfficeDelete))->whereNumber('office');

    // Permission-protected, office-scoped: one profile per office. The
    // Policy limits .view/.edit to the user's own office; .view_all is
    // every office plus the cross-office list.
    Route::get('/stakeholder-profiles/options', [StakeholderProfileController::class, 'options'])->middleware($can(P::StakeholderProfileView, P::StakeholderProfileViewAll));
    Route::get('/stakeholder-profiles', [StakeholderProfileController::class, 'index'])->middleware($can(P::StakeholderProfileViewAll));
    Route::get('/stakeholder-profiles/{office}', [StakeholderProfileController::class, 'show'])->middleware($can(P::StakeholderProfileView, P::StakeholderProfileViewAll))->whereNumber('office');
    Route::put('/stakeholder-profiles/{office}', [StakeholderProfileController::class, 'update'])->middleware($can(P::StakeholderProfileEdit, P::StakeholderProfileViewAll))->whereNumber('office');

    // Permission-protected, office-scoped: one survey per office (same rules as above).
    Route::get('/internet-connectivity-surveys/options', [InternetConnectivitySurveyController::class, 'options'])->middleware($can(P::InternetConnectivityView, P::InternetConnectivityViewAll));
    Route::get('/internet-connectivity-surveys', [InternetConnectivitySurveyController::class, 'index'])->middleware($can(P::InternetConnectivityViewAll));
    Route::get('/internet-connectivity-surveys/{office}', [InternetConnectivitySurveyController::class, 'show'])->middleware($can(P::InternetConnectivityView, P::InternetConnectivityViewAll))->whereNumber('office');
    Route::put('/internet-connectivity-surveys/{office}', [InternetConnectivitySurveyController::class, 'update'])->middleware($can(P::InternetConnectivityEdit, P::InternetConnectivityViewAll))->whereNumber('office');

    // Permission-protected: reference-data.manage ({table} is a registry key).
    Route::middleware($can(P::ReferenceDataManage))->group(function () {
        Route::get('/reference-data', [ReferenceDataController::class, 'index']);
        Route::get('/reference-data/{table}', [ReferenceDataController::class, 'show'])->where('table', '[a-z-]+');
        Route::post('/reference-data/{table}', [ReferenceDataController::class, 'store'])->where('table', '[a-z-]+');
        Route::patch('/reference-data/{table}/{id}', [ReferenceDataController::class, 'update'])->where('table', '[a-z-]+')->whereNumber('id');
        Route::delete('/reference-data/{table}/{id}', [ReferenceDataController::class, 'destroy'])->where('table', '[a-z-]+')->whereNumber('id');
    });

    // Administration: account management (users.manage; UserPolicy and UserRoleService re-check).
    Route::middleware($can(P::UsersManage))->group(function () {
        Route::get('/users/options', [UserController::class, 'options']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->whereNumber('user');
        Route::post('/users/{user}/2fa/reset', [UserController::class, 'resetTwoFactor'])->whereNumber('user');
    });

    // Administration: reading roles and the permission catalog (roles.manage or users.manage).
    Route::middleware($can(P::RolesManage, P::UsersManage))->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show'])->whereNumber('role');
    });

    // Administration: defining roles (roles.manage; RoleService enforces the lockout guards).
    Route::middleware($can(P::RolesManage))->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
    });

    // Administration: read-only audit trail.
    Route::middleware($can(P::AuditLogView))->group(function () {
        Route::get('/audit-log/options', [AuditLogController::class, 'options']);
        Route::get('/audit-log', [AuditLogController::class, 'index']);
    });

    // Administration: sign-in and session security settings.
    Route::middleware($can(P::SecuritySettingsManage))->group(function () {
        Route::get('/settings/security', [SecuritySettingController::class, 'index']);
        Route::put('/settings/security', [SecuritySettingController::class, 'update']);
    });
});
