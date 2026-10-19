<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreOfficeRequest;
use App\Http\Requests\Management\UpdateOfficeRequest;
use App\Http\Resources\Management\OfficeResource;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Audit: create/update/delete rows come from Office's LogsActivity.
 */
class OfficeController extends Controller
{
    use ResolvesListParameters;

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'office_name' => 'office_name',
        'office_type' => 'office_type',
        'school_id' => 'school_id',
        'district' => 'district',
        'is_active' => 'is_active',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Office::class);

        $query = Office::query()->search($request->string('search')->toString() ?: null);

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderBy('office_name');
        }

        return OfficeResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function store(StoreOfficeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] ??= true;

        $office = Office::create($data);

        return new OfficeResource($office)
            ->additional(['message' => 'Office created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Office $office): JsonResponse
    {
        $this->authorize('view', $office);

        return new OfficeResource($office)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdateOfficeRequest $request, Office $office): JsonResponse
    {
        $this->authorize('update', $office);

        $office->update($request->validated());

        return new OfficeResource($office)
            ->additional(['message' => 'Office updated.'])
            ->response();
    }

    /**
     * Users, stakeholder profiles and connectivity surveys reference an
     * office through restrictOnDelete FKs, so the delete is attempted and
     * an FK violation becomes a 409 (the FK is atomic; a pre-check would
     * race). The transaction also rolls back the `deleted` activity row.
     */
    public function destroy(Office $office): Response|JsonResponse
    {
        $this->authorize('delete', $office);

        try {
            DB::transaction(fn () => $office->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => __('This office is still in use and cannot be deleted — deactivate it instead.'),
                ], 409);
            }

            throw $e;
        }

        return response()->noContent();
    }
}
