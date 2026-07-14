<?php

namespace Database\Seeders;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\StakeholderLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds the SINGLE `internet_connectivity_surveys` row from
 * database/seeders/data/internet_connectivity_survey.json — the division's
 * answers to the source Excel workbook's 27-question "Internet
 * Connectivity" survey sheet.
 *
 * The source sheet is label/data (one cell per answer, not tabular rows) —
 * see InternetConnectivitySurvey's migration doc-comment. Most of the 27
 * questions are either blank in this division's actual answers, or are
 * "Protected"/derived aggregate figures this table deliberately does not
 * store (Total ISPs, Total Cost/month, etc. — computed live from
 * isp_accounts/isp_subscription_costs instead). Only the genuinely
 * answered, genuinely-stored questions are populated here; every other
 * column stays null, matching what was actually left blank in the source.
 *
 * SAFETY — this is a true singleton (unlike StakeholderProfile, which was
 * later converted to one-per-office). Unconditionally overwriting it would
 * risk clobbering real admin work done through the app after this seeder
 * first ran. Instead: only seed if the existing singleton row (created via
 * firstOrCreate([]) — see InternetConnectivitySurveyController) has never
 * been touched, i.e. every one of its fillable columns is still null. If
 * any field is already populated, skip entirely and log why, rather than
 * guessing which fields are safe to fill.
 */
class InternetConnectivitySurveySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/internet_connectivity_survey.json');

        if (! File::exists($path)) {
            throw new RuntimeException("Internet connectivity survey seed file not found at [{$path}].");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $survey = InternetConnectivitySurvey::firstOrCreate([]);

        if ($this->hasAnyAnswer($survey)) {
            $this->command?->info('internet_connectivity_surveys: existing singleton row already has data — left untouched.');

            return;
        }

        $survey->update([
            'has_isp_in_area' => $data['has_isp_in_area'],
            'available_isps' => $this->resolveIspProviderIds($data['available_isps_names']),
            'available_isps_other' => $data['available_isps_other'],

            'mobile_signal_types' => $this->resolveLibraryIds(
                ConnectivityLibrary::class,
                ConnectivityLibraryType::MobileNetworkSignal->value,
                $data['mobile_signal_type_names'],
            ),

            'has_mobile_data_connectivity' => $data['has_mobile_data_connectivity'],
            'mobile_data_quality' => $data['mobile_data_quality'],

            'subscribes_to_isp' => $data['subscribes_to_isp'],
            'subscribed_isps' => $this->resolveIspProviderIds($data['subscribed_isp_names']),

            'insufficient_bandwidth_explanation' => $data['insufficient_bandwidth_explanation'],

            'coverage_areas' => $this->resolveLibraryIds(
                ConnectivityLibrary::class,
                ConnectivityLibraryType::CoverageArea->value,
                $data['coverage_area_names'],
            ),
            'coverage_areas_other' => $data['coverage_areas_other'],

            'dict_free_wifi_recipient' => $data['dict_free_wifi_recipient'],
            'dict_free_wifi_rating' => $data['dict_free_wifi_rating'],

            'has_sufficient_bandwidth' => $data['has_sufficient_bandwidth'],
            'no_subscription_reason' => $data['no_subscription_reason'],

            'has_electricity_source' => $data['has_electricity_source'],
            'electricity_sources' => $this->resolveLibraryIds(
                StakeholderLibrary::class,
                StakeholderLibraryType::SourceOfElectricity->value,
                $data['electricity_source_names'],
            ),
            'primarily_solar_powered' => $data['primarily_solar_powered'],
            'frequent_brownouts' => $data['frequent_brownouts'],

            'rooms_other_use' => $data['rooms_other_use'],
        ]);

        $this->command?->info('internet_connectivity_surveys: singleton row seeded from source workbook.');
    }

    private function hasAnyAnswer(InternetConnectivitySurvey $survey): bool
    {
        return collect($survey->getAttributes())
            ->except(['id', 'singleton_guard', 'created_at', 'updated_at'])
            ->contains(fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>|null
     */
    private function resolveIspProviderIds(array $names): ?array
    {
        if ($names === []) {
            return null;
        }

        $ids = IspProvider::whereIn('name', $names)->pluck('id', 'name');

        foreach ($names as $name) {
            if (! $ids->has($name)) {
                throw new RuntimeException("internet_connectivity_survey seed: ISP provider [{$name}] not found in isp_providers table.");
            }
        }

        return $ids->only($names)->values()->all();
    }

    /**
     * @param  class-string<ConnectivityLibrary|StakeholderLibrary>  $model
     * @param  array<int, string>  $names
     * @return array<int, int>|null
     */
    private function resolveLibraryIds(string $model, string $type, array $names): ?array
    {
        if ($names === []) {
            return null;
        }

        $ids = $model::where('type', $type)->whereIn('value', $names)->pluck('id', 'value');

        foreach ($names as $name) {
            if (! $ids->has($name)) {
                throw new RuntimeException("internet_connectivity_survey seed: [{$type}] value [{$name}] not found.");
            }
        }

        return $ids->only($names)->values()->all();
    }
}
