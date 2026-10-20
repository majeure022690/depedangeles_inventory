<?php

use Illuminate\Support\Facades\Route;

// Public, unauthenticated: printed QR labels encode this URL
// (Equipment::$qr_code via EquipmentObserver), so it must keep resolving
// for the life of the label. It only ever redirects to the configured
// frontend origin (never a client-supplied URL), so it is not an open
// redirect. No model binding on purpose: every numeric id gets the same
// 302, so the route doesn't reveal which ids exist; the SPA enforces
// login and equipment.view from there.
Route::get('/equipment/{id}/edit', fn (string $id) => redirect()->away(
    rtrim((string) config('app.frontend_url'), '/')."/equipment/{$id}/edit",
))->whereNumber('id')->name('equipment.edit');
