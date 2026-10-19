<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\UpdateStakeholderProfileRequest;
use App\Http\Resources\Management\StakeholderProfileOfficeResource;
use App\Http\Resources\Management\StakeholderProfileResource;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\PsgcBarangay;
use App\Models\PsgcMunicipality;
use App\Models\PsgcProvince;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ONE ROW PER OFFICE: index() is the cross-office admin list (view_all
 * only); show()/update() are scoped to one {office}. No store/destroy: a
 * profile is firstOrCreate()'d lazily. Both authorize a transient model
 * carrying the office_id BEFORE firstOrCreate(), so a wrong-office or
 * zero-permission request never creates that office's row.
 *
 * Audit: `updated` rows come from StakeholderProfile's LogsActivity.
 */
class StakeholderProfileController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StakeholderProfile::class);

        $search = $request->string('search')->toString() ?: null;

        $offices = Office::query()
            ->with('stakeholderProfile')
            ->when($search, fn ($query) => $query->where('office_name', 'like', "%{$search}%"))
            ->orderBy('office_name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return StakeholderProfileOfficeResource::collection($offices)
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Form options. The PSGC province/municipality/barangay lists are the
     * whole Region III tree, sent once and filtered client-side by
     * `parent_id` (municipality -> province, barangay -> municipality).
     * The four checkbox groups are id-valued; `transaction_type` is read
     * from Equipment's library on purpose.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->libraryOptions(StakeholderLibrary::class, ['governance_level' => StakeholderLibraryType::GovernanceLevel]),
                ...$this->libraryOptions(EquipmentLibrary::class, ['transaction_type' => EquipmentLibraryType::TransactionType]),
                'nearby_institution' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::NearbyInstitution),
                'type_of_access_road' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::TypeOfAccessRoad),
                'by_transportation' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::ByTransportation),
                'community_engagement' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::CommunityEngagement),
                'province' => PsgcProvince::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (PsgcProvince $province) => ['value' => $province->id, 'label' => $province->name])
                    ->all(),
                'municipality' => PsgcMunicipality::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'province_id'])
                    ->map(fn (PsgcMunicipality $municipality) => [
                        'value' => $municipality->id,
                        'label' => $municipality->name,
                        'parent_id' => $municipality->province_id,
                    ])
                    ->all(),
                'barangay' => PsgcBarangay::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'municipality_id'])
                    ->map(fn (PsgcBarangay $barangay) => [
                        'value' => $barangay->id,
                        'label' => $barangay->name,
                        'parent_id' => $barangay->municipality_id,
                    ])
                    ->all(),
            ],
            'message' => 'Success',
        ]);
    }

    public function show(Office $office): JsonResponse
    {
        $this->authorize('view', new StakeholderProfile(['office_id' => $office->id]));

        $profile = StakeholderProfile::firstOrCreate(['office_id' => $office->id]);

        return $this->respond($office, $profile, 'Success');
    }

    public function update(UpdateStakeholderProfileRequest $request, Office $office): JsonResponse
    {
        $this->authorize('update', new StakeholderProfile(['office_id' => $office->id]));

        $profile = StakeholderProfile::firstOrCreate(['office_id' => $office->id]);
        $profile->update($request->validated());

        return $this->respond($office, $profile, 'Stakeholder profile updated.');
    }

    private function respond(Office $office, StakeholderProfile $profile, string $message): JsonResponse
    {
        $profile->load('province', 'municipality', 'barangay');

        return response()->json([
            'data' => [
                'office' => [
                    'id' => $office->id,
                    'office_name' => $office->office_name,
                    'school_id' => $office->school_id,
                ],
                'profile' => new StakeholderProfileResource($profile),
            ],
            'message' => $message,
        ]);
    }
}
