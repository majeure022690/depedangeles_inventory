<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreGspIctProfileRequest;
use App\Http\Requests\Management\UpdateGspIctProfileRequest;
use App\Http\Resources\Management\GspIctProfileResource;
use App\Models\GspIctProfile;
use App\Models\IspAccount;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use App\Services\GspIctReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Audit: create/update/delete rows come from GspIctProfile's LogsActivity;
 * workbook downloads are logged as `report.generated`.
 */
class GspIctProfileController extends Controller
{
    use ResolvesListParameters;

    private const RELATIONS = [
        'office:id,office_name,school_id',
        'schoolHead:id,full_name,position_id', 'schoolHead.personnelPosition:id,name',
        'ito:id,full_name,position_id', 'ito.personnelPosition:id,name',
        'planningOfficer:id,full_name,position_id', 'planningOfficer.personnelPosition:id,name',
    ];

    /**
     * @var array<string, string>
     */
    private const SORTABLE = ['school_year' => 'school_year', 'as_of' => 'as_of', 'updated_at' => 'updated_at'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GspIctProfile::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'school_year' => ['nullable', 'string', 'max:9']]);

        $search = $request->string('search')->toString();
        $query = GspIctProfile::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->when($search !== '', fn (Builder $query) => $query->whereHas('office', fn (Builder $query) => $query
                ->where('office_name', 'like', "%{$search}%")
                ->orWhere('school_id', 'like', "%{$search}%")))
            ->when($request->filled('school_year'), fn (Builder $query) => $query->where('school_year', $request->string('school_year')->toString()));

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderByDesc('school_year')->orderBy('office_id');
        }

        return GspIctProfileResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Form options; `?office_id=` adds that office's active ISP accounts for the reliability ratings.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $officeId = $request->integer('office_id');

        $data = [
            'office' => Office::query()
                ->active()
                ->when($user->dataScope() !== DataScope::All, fn (Builder $query) => $query->whereKey($user->office_id ?? 0))
                ->get(['id', 'office_name', 'office_type', 'school_id'])
                ->map(fn (Office $office) => [
                    'value' => $office->id,
                    'label' => $office->school_id ? "{$office->office_name} ({$office->school_id})" : $office->office_name,
                    'default_level' => GspIctReport::defaultLevel($office),
                ])
                ->all(),
            'level' => collect(GspIctReport::LEVELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'dict_free_wifi' => collect(GspIctProfile::DICT_FREE_WIFI)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'reliability' => array_map(fn (string $value) => ['value' => $value, 'label' => $value], GspIctProfile::RELIABILITY),
            'itos' => $this->pickerOptions(Personnel::query()->withPositionLike('Information Technology Officer')->orderBy('last_name')),
            'planning_officers' => $this->pickerOptions(Personnel::query()->withPositionLike('Planning Officer')->orderBy('last_name')),
        ];

        if ($officeId > 0 && $user->reachesOffice($officeId)) {
            $data['school_heads'] = $this->pickerOptions(Personnel::query()->where('office_id', $officeId)->schoolHeads()->orderBy('last_name')->orderBy('first_name'));
            $data['isp_accounts'] = IspAccount::query()
                ->where('office_id', $officeId)
                ->where('inactive_contract', false)
                ->with('ispProvider:id,name')
                ->orderBy('id')
                ->get(['id', 'isp_provider_id', 'isp_billing_account_no', 'isp_connection_type'])
                ->map(fn (IspAccount $account) => [
                    'value' => $account->id,
                    'label' => trim($account->ispProvider->name." · {$account->isp_billing_account_no}"),
                    'connection_type' => $account->isp_connection_type,
                ])
                ->all();
        }

        return response()->json(['data' => $data, 'message' => 'Success']);
    }

    /**
     * The tables the workbook fills from IRIS (Tables 1–3, Box 1 survey answers and subscriptions), computed live.
     */
    public function preview(Request $request, GspIctReport $report): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate([
            'office_id' => ['required', 'integer'],
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'level' => ['required', 'string', Rule::in(array_keys(GspIctReport::LEVELS))],
        ]);

        abort_unless($user->reachesOffice((int) $input['office_id']), 404);
        $office = Office::query()->findOrFail((int) $input['office_id']);

        return response()->json([
            'data' => $report->irisData($office, $user, (int) substr($input['school_year'], 0, 4), $input['level']),
            'message' => 'Success',
        ]);
    }

    /**
     * Active personnel for a signatory picker.
     *
     * @param  Builder<Personnel>  $query
     * @return list<array{value: int, label: string, position: ?string}>
     */
    private function pickerOptions(Builder $query): array
    {
        return array_values($query->active()
            ->with('personnelPosition:id,name')
            ->get(['id', 'full_name', 'position_id'])
            ->map(fn (Personnel $personnel) => $personnel->toOption())
            ->all());
    }

    public function store(StoreGspIctProfileRequest $request): JsonResponse
    {
        $profile = new GspIctProfile($request->validated());
        $profile->forceFill(['office_id' => $request->targetOfficeId(), 'created_by_user_id' => $request->user()->id])->save();

        return new GspIctProfileResource($profile->load(self::RELATIONS))
            ->additional(['message' => 'GSP ICT profile created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(GspIctProfile $gspIctProfile): JsonResponse
    {
        $this->authorize('view', $gspIctProfile);

        return new GspIctProfileResource($gspIctProfile->load(self::RELATIONS))
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function update(UpdateGspIctProfileRequest $request, GspIctProfile $gspIctProfile): JsonResponse
    {
        $gspIctProfile->update($request->validated());

        return new GspIctProfileResource($gspIctProfile->load(self::RELATIONS))
            ->additional(['message' => 'GSP ICT profile updated.'])
            ->response();
    }

    public function destroy(GspIctProfile $gspIctProfile): Response
    {
        $this->authorize('delete', $gspIctProfile);

        $gspIctProfile->delete();

        return response()->noContent();
    }

    /**
     * The GSP Part VI workbook for this profile's office and school year.
     */
    public function download(Request $request, GspIctProfile $gspIctProfile, GspIctReport $report): BinaryFileResponse
    {
        $this->authorize('download', $gspIctProfile);
        /** @var User $user */
        $user = $request->user();
        $office = $gspIctProfile->office()->firstOrFail();
        $gspIctProfile->load(['schoolHead.personnelPosition', 'ito.personnelPosition', 'planningOfficer.personnelPosition']);

        activity()
            ->performedOn($office)
            ->event('report.generated')
            ->withProperties(['report' => 'gsp_ict', 'gsp_ict_profile_id' => $gspIctProfile->id, 'school_year' => $gspIctProfile->school_year])
            ->log('report.generated');

        $label = Str::of($office->office_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->limit(60, '')->toString();

        return response()
            ->download($report->generate($gspIctProfile, $office, $user), "GSP_ICT_Report_{$label}_{$gspIctProfile->school_year}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
