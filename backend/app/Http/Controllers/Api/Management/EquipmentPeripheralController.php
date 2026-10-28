<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\LinkEquipmentPeripheralRequest;
use App\Http\Requests\Management\StoreEquipmentPeripheralBatchRequest;
use App\Http\Requests\Management\StoreEquipmentPeripheralRequest;
use App\Http\Resources\Management\EquipmentResource;
use App\Models\Equipment;
use App\Models\User;
use App\Services\EquipmentPeripheralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Peripherals of a desktop bundle. Adding or linking needs `update` and
 * `createTransaction` on the desktop; unlinking needs `update` on both
 * records. Audit: equipment.peripheral.{created,linked,unlinked} on both
 * records, plus Equipment's own LogsActivity rows.
 */
class EquipmentPeripheralController extends Controller
{
    private const RELATIONS = [...EquipmentController::LIST_RELATIONS, 'parent:id,property_no'];

    public function __construct(
        private readonly EquipmentPeripheralService $peripherals,
    ) {}

    public function store(StoreEquipmentPeripheralRequest $request, Equipment $equipment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $peripheral = $this->peripherals->create($equipment, $request->validated(), $user);

        return new EquipmentResource($peripheral->load(self::RELATIONS))
            ->additional(['message' => 'Peripheral added.'])
            ->response()
            ->setStatusCode(201);
    }

    public function storeMany(StoreEquipmentPeripheralBatchRequest $request, Equipment $equipment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $peripherals = $this->peripherals->createMany($equipment, $request->peripheralRows(), $user);

        return EquipmentResource::collection($peripherals->load(self::RELATIONS))
            ->additional(['message' => trans_choice('Peripheral added.|:count peripherals added.', $peripherals->count())])
            ->response()
            ->setStatusCode(201);
    }

    public function link(LinkEquipmentPeripheralRequest $request, Equipment $equipment): JsonResponse
    {
        $peripheral = $this->peripherals->link($equipment, $request->peripheral());

        return new EquipmentResource($peripheral->load(self::RELATIONS))
            ->additional(['message' => 'Peripheral linked.'])
            ->response();
    }

    public function destroy(Equipment $equipment, Equipment $peripheral): Response
    {
        $this->authorize('update', $equipment);

        abort_unless((int) $peripheral->parent_equipment_id === $equipment->id, 404);

        $this->authorize('update', $peripheral);

        $this->peripherals->unlink($equipment, $peripheral);

        return response()->noContent();
    }
}
