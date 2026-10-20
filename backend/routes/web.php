<?php

use App\Models\Equipment;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated: printed QR labels encode this URL
// (Equipment::$qr_code via EquipmentObserver), so it must keep resolving
// for the life of the label. It only ever redirects to the configured
// frontend origin (never a client-supplied URL), so it is not an open
// redirect; the SPA enforces login and equipment.view from there.
Route::get('/equipment/{equipment}/edit', fn (Equipment $equipment) => redirect()->away(
    rtrim((string) config('app.frontend_url'), '/')."/equipment/{$equipment->id}/edit",
))->whereNumber('equipment')->name('equipment.edit');
