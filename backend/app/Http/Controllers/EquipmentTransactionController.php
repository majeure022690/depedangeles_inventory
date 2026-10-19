<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EquipmentTransactionStoreRequest;
use App\Models\Equipment;
use App\Services\EquipmentAccountabilityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Append-only: equipment_transactions has no update/destroy routes by
 * design (see the model's doc-comment). This is the sole mutating action.
 */
class EquipmentTransactionController extends Controller
{
    public function __construct(
        private readonly EquipmentAccountabilityService $accountability,
    ) {}

    public function store(EquipmentTransactionStoreRequest $request, Equipment $equipment): RedirectResponse
    {
        $this->accountability->recordTransaction($equipment, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction recorded.')]);

        return to_route('equipment.edit', $equipment);
    }
}
