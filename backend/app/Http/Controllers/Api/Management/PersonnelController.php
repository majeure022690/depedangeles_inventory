<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\PersonnelLibraryType;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StorePersonnelRequest;
use App\Http\Requests\Management\UpdatePersonnelRequest;
use App\Http\Resources\Management\PersonnelResource;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Audit: create/update/delete rows come from Personnel's LogsActivity.
 */
class PersonnelController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    /**
     * Tier 1 tables behind the form's id-valued selects, keyed by field.
     *
     * @var array<string, class-string>
     */
    private const TIER1_FORM_MODELS = [
        'position' => Position::class,
        'ro_office' => RoOffice::class,
        'sdo_office' => SdoOffice::class,
    ];

    /**
     * Tier 2 types behind the form's string-valued selects.
     *
     * @var array<string, PersonnelLibraryType>
     */
    private const TIER2_FORM_TYPES = [
        'name_suffix' => PersonnelLibraryType::NameSuffix,
        'cause_of_separation' => PersonnelLibraryType::CauseOfSeparation,
    ];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'employee_id' => 'employee_id',
        'full_name' => 'full_name',
        'last_name' => 'last_name',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Personnel::class);

        $status = $request->string('status')->toString();
        $status = in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active';

        $query = Personnel::query()
            ->search($request->string('search')->toString() ?: null)
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'inactive', fn ($query) => $query->where('inactive', true))
            ->with(['personnelPosition:id,name', 'roOffice:id,name', 'sdoOffice:id,name']);

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderBy('last_name')->orderBy('first_name');
        }

        return PersonnelResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Dropdown options for the create/edit form. `teachers_funding_source`
     * is id-valued (it backs the JSON-array `fund_source` field).
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->referenceOptions(self::TIER1_FORM_MODELS),
                ...$this->libraryOptions(PersonnelLibrary::class, self::TIER2_FORM_TYPES),
                'teachers_funding_source' => $this->libraryOptionsById(PersonnelLibrary::class, PersonnelLibraryType::TeachersFundingSource),
            ],
            'message' => 'Success',
        ]);
    }

    public function store(StorePersonnelRequest $request): JsonResponse
    {
        $personnel = Personnel::create($request->validated());

        return $this->resource($personnel)
            ->additional(['message' => 'Personnel record created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Personnel $personnel): JsonResponse
    {
        $this->authorize('view', $personnel);

        return $this->resource($personnel)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdatePersonnelRequest $request, Personnel $personnel): JsonResponse
    {
        $this->authorize('update', $personnel);

        $personnel->update($request->validated());

        return $this->resource($personnel)
            ->additional(['message' => 'Personnel record updated.'])
            ->response();
    }

    public function destroy(Personnel $personnel): Response
    {
        $this->authorize('delete', $personnel);

        $personnel->delete();

        return response()->noContent();
    }

    private function resource(Personnel $personnel): PersonnelResource
    {
        return new PersonnelResource($personnel->fresh(['personnelPosition:id,name', 'roOffice:id,name', 'sdoOffice:id,name']) ?? $personnel);
    }
}
