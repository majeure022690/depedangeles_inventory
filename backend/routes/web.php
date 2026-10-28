<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Printed QR labels encode this URL. No model binding, so it doesn't reveal which ids exist.
Route::get('/equipment/{id}/edit', [SpaController::class, 'equipmentLabel'])
    ->whereNumber('id')
    ->withoutMiddleware('web')
    ->name('equipment.edit');

Route::any('/', SpaController::class)->withoutMiddleware('web');

Route::any('{fallbackPlaceholder}', SpaController::class)
    ->where('fallbackPlaceholder', '(?!(?:api|sanctum|up)(?:/|$)).*')
    ->fallback()
    ->withoutMiddleware('web');
