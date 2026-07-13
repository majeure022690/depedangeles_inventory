<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Bare-bones structural model — fillable/casts only.
 *
 * SINGLETON MODEL: exactly one row should ever exist (one Division Office
 * connectivity survey for this whole application). Same singleton
 * treatment as StakeholderProfile — Backend must fetch/create it with an
 * `InternetConnectivitySurvey::firstOrCreate([])`-style pattern, never a
 * list/index. No factory is provided for the same reason.
 *
 * LIVE-COMPUTED "PROTECTED" FIELDS: the source survey displays several
 * fields marked "Protected, source data from..." — Total ISPs, Total
 * Cost/month, Total Amount Spent, Total Projected Expenditure, Rooms
 * Covered (Admin/Classroom), Total Access Points. None of these are
 * columns on this table by design (see the create_internet_connectivity_
 * surveys_table migration) — they must never be duplicated/cached here.
 * Backend needs to query App\Models\IspAccount and the isp_subscription_
 * costs table to compute them live wherever this survey is displayed:
 *   - Total ISPs: count of isp_accounts rows (distinct `isp` or row count,
 *     Backend's call based on what "Total ISPs" is meant to represent).
 *   - Total Cost/month: sum of isp_accounts.cost_per_month.
 *   - Total Amount Spent / Total Projected Expenditure: aggregated from
 *     isp_subscription_costs.
 *   - Rooms Covered (Admin/Classroom): sum of
 *     isp_accounts.number_admin_area_covered /
 *     isp_accounts.number_classrooms_covered.
 *   - Total Access Points: sum of
 *     isp_accounts.number_access_points_linked.
 * There is no FK from this table to isp_accounts / isp_subscription_costs
 * — it's a "query separately at render time" relationship, not a stored
 * one, and deliberately has no Eloquent relationship method here.
 *
 * rooms_other_use is the one exception: it's genuinely user-entered in the
 * source (not protected/derived), so it's a real column here.
 */
#[Fillable([
    'has_isp_in_area', 'available_isps', 'available_isps_other',
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
