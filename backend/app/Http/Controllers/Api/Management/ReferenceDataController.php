<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreReferenceDataRequest;
use App\Http\Requests\Management\UpdateReferenceDataRequest;
use App\Http\Resources\Management\ReferenceDataRowResource;
use App\Models\Office;
use App\Services\ReferenceDataResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ONE generic controller for all 13 reference-data tables, driven by
 * config/reference-data.php ({table} is a registry key such as
 * `item-types`, never a raw table name). ReferenceDataPolicy is bound to
 * each of the 13 models (AppServiceProvider), so the dynamic-model
 * authorize() calls below resolve to it.
 *
 * Tier 1 `name` is editable (consumers hold a real FK and read the name
 * through the relation); Tier 2 `type`/`value` are not (consumers store
 * a copy of `value`). See UpdateReferenceDataRequest.
 *
 * Audit: rows come from LogsReferenceDataActivity (`reference_data.*`,
 * with the registry key in properties.table).
 */
class ReferenceDataController extends Controller
{
    use ResolvesListParameters;

    /**
     * Overview of all 13 tables. Office is not one of them (it has its own
     * CRUD), but the page links to it, so its row count rides along.
     */
    public function index(): JsonResponse
    {
        $this->authorize(Permission::ReferenceDataManage->value);

        /** @var array<string, array{label: string, model: class-string<Model>, tier: int}> $registry */
        $registry = config('reference-data');

        $tables = [];

        foreach ($registry as $key => $entry) {
            $tables[] = [
                'key' => $key,
                'label' => $entry['label'],
                'tier' => $entry['tier'],
                'row_count' => $entry['model']::query()->count(),
            ];
        }

        return response()->json([
            'data' => [
                'tables' => $tables,
                'office_count' => Office::query()->count(),
            ],
            'message' => 'Success',
        ]);
    }

    public function show(Request $request, string $table): JsonResponse
    {
        $entry = ReferenceDataResolver::entry($table);
        $modelClass = $entry['model'];

        $this->authorize('viewAny', $modelClass);

        $isTier1 = $entry['tier'] === 1;
        $search = $request->string('search')->toString() ?: null;
        $typeFilter = $isTier1 ? null : ($request->string('type')->toString() ?: null);

        $rows = $modelClass::query()
            ->when($search, fn ($query) => $query->where(
                fn ($q) => $q
                    ->where($isTier1 ? 'name' : 'value', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"),
            ))
            ->when($typeFilter, fn ($query) => $query->where('type', $typeFilter))
            ->when(! $isTier1, fn ($query) => $query->orderBy('type'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ReferenceDataRowResource::collection($rows)
            ->additional([
                'meta' => [
                    'table' => [
                        'key' => $table,
                        'label' => $entry['label'],
                        'tier' => $entry['tier'],
                        'types' => $isTier1 ? null : array_map(
                            fn ($case) => ['value' => $case->value, 'label' => $case->label()],
                            $entry['type_enum']::cases(),
                        ),
                    ],
                ],
                'message' => 'Success',
            ])
            ->response();
    }

    /**
     * Authorized by StoreReferenceDataRequest (ReferenceDataPolicy::create
     * on the registry model class).
     */
    public function store(StoreReferenceDataRequest $request, string $table): JsonResponse
    {
        $entry = ReferenceDataResolver::entry($table);
        $modelClass = $entry['model'];

        $data = $request->validated();
        $data['sort_order'] ??= 0;
        $data['is_active'] ??= true;

        try {
            $row = $modelClass::create($data);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    ($entry['tier'] === 1 ? 'name' : 'value') => __('This value already exists.'),
                ]);
            }

            throw $e;
        }

        return new ReferenceDataRowResource($row)
            ->additional(['message' => 'Reference data created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateReferenceDataRequest $request, string $table, int $id): JsonResponse
    {
        $row = ReferenceDataResolver::resolveRow($table, $id);

        $this->authorize('update', $row);

        $row->update($request->validated());

        return new ReferenceDataRowResource($row)
            ->additional(['message' => 'Reference data updated.'])
            ->response();
    }

    /**
     * Two-layer delete guard: ReferenceDataResolver::guardDeletable()
     * pre-checks the references no FK enforces (Tier 2 string/JSON
     * columns, isp_providers inside the survey JSON columns); Tier 1 FKs
     * are restrictOnDelete, so the delete is attempted and a violation
     * caught. Either way an in-use row answers 409.
     */
    public function destroy(string $table, int $id): Response|JsonResponse
    {
        $row = ReferenceDataResolver::resolveRow($table, $id);

        $this->authorize('delete', $row);

        $inUse = response()->json([
            'message' => __('This value is still in use and cannot be deleted — deactivate it instead.'),
        ], 409);

        try {
            ReferenceDataResolver::guardDeletable($table, $row);
        } catch (ValidationException) {
            return $inUse;
        }

        try {
            DB::transaction(fn () => $row->delete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return $inUse;
            }

            throw $e;
        }

        return response()->noContent();
    }
}
