<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Office (see docs/features/internet-connectivity-survey.md).
 * No factory — surveys are lazily created per office, not seeded in bulk.
 * The "protected" aggregate fields (Total ISPs, etc.) aren't columns here;
 * they're computed live from IspAccount/isp_subscription_costs elsewhere.
 */
#[Fillable([
    'office_id', 'has_isp_in_area', 'available_isps', 'available_isps_other',
    'mobile_signal_types', 'has_mobile_data_connectivity',
    'mobile_data_quality', 'subscribes_to_isp', 'subscribed_isps',
    'insufficient_bandwidth_explanation', 'coverage_areas',
    'coverage_areas_other', 'dict_free_wifi_recipient',
    'dict_free_wifi_rating', 'has_sufficient_bandwidth',
    'no_subscription_reason', 'has_electricity_source',
    'electricity_sources', 'primarily_solar_powered', 'frequent_brownouts',
    'rooms_other_use',
])]
class InternetConnectivitySurvey extends Model
{
    /**
     * True once any field beyond office_id has actually been filled in —
     * firstOrCreate() persists an empty row on first edit-page view, so
     * row-existence alone can't tell "started" from "just opened".
     */
    public function hasAnswers(): bool
    {
        return collect($this->getAttributes())
            ->except(['id', 'office_id', 'created_at', 'updated_at'])
            ->contains(fn ($value) => $value !== null);
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    protected function casts(): array
    {
        return [
            'has_isp_in_area' => 'boolean',
            'available_isps' => 'array',
            'mobile_signal_types' => 'array',
            'has_mobile_data_connectivity' => 'boolean',
            'subscribes_to_isp' => 'boolean',
            'subscribed_isps' => 'array',
            'coverage_areas' => 'array',
            'dict_free_wifi_recipient' => 'boolean',
            'dict_free_wifi_rating' => 'integer',
            'has_sufficient_bandwidth' => 'boolean',
            'has_electricity_source' => 'boolean',
            'electricity_sources' => 'array',
            'primarily_solar_powered' => 'boolean',
            'frequent_brownouts' => 'boolean',
            'rooms_other_use' => 'integer',
        ];
    }
}
