<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Http\Controllers\Api\Management\Concerns\HasLookupOptions;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\UpdateInternetConnectivitySurveyRequest;
use App\Http\Resources\Management\ConnectivitySurveyOfficeResource;
use App\Http\Resources\Management\InternetConnectivitySurveyResource;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ONE ROW PER OFFICE, same shape as StakeholderProfileController: index()
 * is the cross-office admin list; show()/update() are scoped to one
 * {office} and authorize a transient model before touching the table;
 * only update() persists (createOrFirst()).
 *
 * Audit: `updated` rows come from InternetConnectivitySurvey's LogsActivity.
 */
class InternetConnectivitySurveyController extends Controller
{
    use HasLookupOptions, ResolvesListParameters;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InternetConnectivitySurvey::class);

        $search = $request->string('search')->toString() ?: null;

        $offices = Office::query()
            ->with('internetConnectivitySurvey')
            ->when($search, fn ($query) => $query->where('office_name', 'like', "%{$search}%"))
            ->orderBy('office_name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ConnectivitySurveyOfficeResource::collection($offices)
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * `isp_provider` (id-valued) feeds both the available/subscribed ISP
     * checkbox groups; `mobile_network_signal`, `coverage_area` and
     * `source_of_electricity` are id-valued too; `signal_quality` is a
     * plain string select.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'isp_provider' => IspProvider::activeOptions(),
                ...$this->libraryOptions(ConnectivityLibrary::class, ['signal_quality' => ConnectivityLibraryType::SignalQuality]),
                'mobile_network_signal' => $this->libraryOptionsById(ConnectivityLibrary::class, ConnectivityLibraryType::MobileNetworkSignal),
                'coverage_area' => $this->libraryOptionsById(ConnectivityLibrary::class, ConnectivityLibraryType::CoverageArea),
                'source_of_electricity' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::SourceOfElectricity),
            ],
            'message' => 'Success',
        ]);
    }

    public function show(Office $office): JsonResponse
    {
        $blank = new InternetConnectivitySurvey(['office_id' => $office->id]);
        $this->authorize('view', $blank);

        // Viewing never writes: no saved row yet means the transient blank
        // model, so a GET can't race or pin the office against deletion.
        $survey = InternetConnectivitySurvey::query()->firstWhere('office_id', $office->id) ?? $blank;

        return $this->respond($office, $survey, 'Success');
    }

    public function update(UpdateInternetConnectivitySurveyRequest $request, Office $office): JsonResponse
    {
        $this->authorize('update', new InternetConnectivitySurvey(['office_id' => $office->id]));

        // createOrFirst() leans on the unique office_id index, so concurrent
        // first saves converge on one row. The bare insert isn't audited
        // ($recordEvents = updated); the update below is.
        $survey = InternetConnectivitySurvey::createOrFirst(['office_id' => $office->id]);
        $survey->update($request->validated());

        return $this->respond($office, $survey, 'Internet connectivity survey updated.');
    }

    private function respond(Office $office, InternetConnectivitySurvey $survey, string $message): JsonResponse
    {
        return response()->json([
            'data' => [
                'office' => [
                    'id' => $office->id,
                    'office_name' => $office->office_name,
                    'school_id' => $office->school_id,
                ],
                'survey' => new InternetConnectivitySurveyResource($survey),
            ],
            'message' => $message,
        ]);
    }
}
