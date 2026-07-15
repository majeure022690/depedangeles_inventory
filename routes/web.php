<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentTransactionController;
use App\Http\Controllers\InternetConnectivitySurveyController;
use App\Http\Controllers\IspAccountController;
use App\Http\Controllers\IspSpeedTestController;
use App\Http\Controllers\IspSubscriptionCostController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StakeholderProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('personnel', PersonnelController::class)->except('show');

    Route::resource('equipment', EquipmentController::class)->except('show');
    // Append-only audit log — store only, no update/destroy routes.
    Route::post('equipment/{equipment}/transactions', [EquipmentTransactionController::class, 'store'])
        ->name('equipment.transactions.store');
    // Renders the QR image for printing/scanning — gated on equipment.view
    // (the same permission as viewing the record itself; see
    // EquipmentController::qrCode's doc-comment).
    Route::get('equipment/{equipment}/qr-code', [EquipmentController::class, 'qrCode'])
        ->name('equipment.qr-code');

    // Parameter explicitly mapped to camelCase 'ispAccount' (rather than
    // the default snake_case 'isp_account' ResourceRegistrar would derive
    // from the hyphenated 'isp-accounts' resource name) so the same
    // {ispAccount} wildcard name is usable verbatim in the nested routes
    // below and in IspAccountUpdateRequest/IspSpeedTestStoreRequest/
    // IspSubscriptionCostStoreRequest's $this->route('ispAccount')
    // lookups — no snake/camel mismatch to remember at each call site.
    Route::resource('isp-accounts', IspAccountController::class)
        ->parameters(['isp-accounts' => 'ispAccount'])
        ->except('show');
    // Append-only logs — store only, no update/destroy routes. Both
    // authorize against `update` on the parent IspAccount (see
    // IspSpeedTestStoreRequest/IspSubscriptionCostStoreRequest).
    Route::post('isp-accounts/{ispAccount}/speed-tests', [IspSpeedTestController::class, 'store'])
        ->name('isp-accounts.speed-tests.store');
    Route::post('isp-accounts/{ispAccount}/subscription-costs', [IspSubscriptionCostController::class, 'store'])
        ->name('isp-accounts.subscription-costs.store');

    // users.manage admin screen: full CRUD over accounts (create/update/
    // delete), plus role assignment folded into update — see
    // UserController's doc-comment. No 'show' (the index list is the only
    // read view) and no 'edit' page (Index.vue edits a user via an inline
    // dialog, not a dedicated page, since the fields involved are few).
    Route::resource('users', UserController::class)->except(['show', 'edit']);

    // roles.manage admin screen: full CRUD over role definitions — see
    // RoleController's doc-comment. Ordinary resource shape (unlike
    // users.manage above) because a role genuinely is create/edit/delete-
    // able data, not a single fixed action against an existing record.
    Route::resource('roles', RoleController::class)->except('show');

    // Full CRUD over schools/division offices — a dedicated resource
    // (not folded into reference-data.manage below) per the architect's
    // review: Office's column shape doesn't fit the generic Tier 1/Tier 2
    // reference-data pattern. `Route::resource` derives the {office}
    // wildcard here, which does NOT collide with stakeholder-profiles/
    // {office} or internet-connectivity-surveys/{office} below — same
    // parameter name, but distinct route names (offices.* vs
    // stakeholder-profiles.*/internet-connectivity-surveys.*) and distinct
    // URI prefixes (offices/... vs stakeholder-profiles/.../internet-
    // connectivity-surveys/...), so no Wayfinder or route-table collision.
    Route::resource('offices', OfficeController::class)->except('show');

    // reference-data.manage admin screen: covers the 13 tables from the
    // lookup-normalization ADR (docs/architecture-decisions/
    // lookup-normalization.md). Successor to the old lookups.manage
    // screen (LookupController/lookups.*), removed in the ADR's Step 4
    // cleanup. ONE generic controller, driven by config/reference-data.php,
    // rather than 13 near-identical route groups — {table} matches a
    // registry key (e.g. 'item-types', 'equipment-libraries'), not a raw
    // table name.
    Route::get('reference-data', [ReferenceDataController::class, 'index'])
        ->name('reference-data.index');
    Route::get('reference-data/{table}', [ReferenceDataController::class, 'show'])
        ->where('table', '[a-z-]+')
        ->name('reference-data.show');
    Route::post('reference-data/{table}', [ReferenceDataController::class, 'store'])
        ->where('table', '[a-z-]+')
        ->name('reference-data.store');
    Route::patch('reference-data/{table}/{id}', [ReferenceDataController::class, 'update'])
        ->where('table', '[a-z-]+')
        ->whereNumber('id')
        ->name('reference-data.update');
    Route::delete('reference-data/{table}/{id}', [ReferenceDataController::class, 'destroy'])
        ->where('table', '[a-z-]+')
        ->whereNumber('id')
        ->name('reference-data.destroy');

    // StakeholderProfile: one row PER OFFICE (see its doc-comment), not a
    // global singleton — edit()/update() are always scoped to a specific
    // {office}, and index() is the cross-office admin list
    // (stakeholder_profile.view_all only). Still no create()/store()/
    // destroy(): a profile is always firstOrCreate(['office_id' => ...])'d
    // lazily, never explicitly created or deleted.
    Route::get('stakeholder-profiles', [StakeholderProfileController::class, 'index'])
        ->name('stakeholder-profiles.index');
    Route::get('stakeholder-profiles/{office}', [StakeholderProfileController::class, 'edit'])
        ->name('stakeholder-profiles.edit');
    Route::put('stakeholder-profiles/{office}', [StakeholderProfileController::class, 'update'])
        ->name('stakeholder-profiles.update');

    // InternetConnectivitySurvey: one row PER OFFICE (see its doc-comment),
    // not a global singleton — edit()/update() are always scoped to a
    // specific {office}, and index() is the cross-office admin list
    // (internet_connectivity.view_all only). Still no create()/store()/
    // destroy(): a survey is always firstOrCreate(['office_id' => ...])'d
    // lazily, never explicitly created or deleted.
    Route::get('internet-connectivity-surveys', [InternetConnectivitySurveyController::class, 'index'])
        ->name('internet-connectivity-surveys.index');
    Route::get('internet-connectivity-surveys/{office}', [InternetConnectivitySurveyController::class, 'edit'])
        ->name('internet-connectivity-surveys.edit');
    Route::put('internet-connectivity-surveys/{office}', [InternetConnectivitySurveyController::class, 'update'])
        ->name('internet-connectivity-surveys.update');

    // audit_log.view admin screen: read-only browse of the append-only
    // audit_logs table (see AuditLogController's doc-comment). One route,
    // no resource — no create/store/edit/update/destroy exist for this
    // resource, and none ever should.
    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
});

require __DIR__.'/settings.php';
