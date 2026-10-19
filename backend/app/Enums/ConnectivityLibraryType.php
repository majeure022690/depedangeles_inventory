<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed enum for the `type` column of `connectivity_libraries` (Tier 2 of
 * the lookup-normalization ADR, docs/architecture-decisions/
 * lookup-normalization.md, Question 1/3). One of four small, per-table
 * successors to the deleted App\Enums\LookupType — scoped to the ISP/
 * connectivity bounded context.
 *
 * `SchoolLevelCoverage` is DELIBERATELY here, not in
 * StakeholderLibraryType — the old LookupType enum's comment grouped it
 * under "Organizational / stakeholder context," but its only real
 * consumer is `isp_accounts.school_area_coverage`. This is a deliberate
 * ADR correction on actual-usage grounds, not an oversight.
 */
enum ConnectivityLibraryType: string
{
    case IspConnectionType = 'isp_connection_type';
    case SubscriptionType = 'subscription_type';
    case PurposeOfSubscription = 'purpose_of_subscription';
    case SignalQuality = 'signal_quality';
    case MobileNetworkSignal = 'mobile_network_signal';
    case CoverageArea = 'coverage_area';
    case SchoolLevelCoverage = 'school_level_coverage';

    /**
     * Human-readable label for admin UI. Falls back to a title-cased
     * version of the value; ISP is spelled out in full caps rather than
     * title-cased "Isp".
     */
    public function label(): string
    {
        return match ($this) {
            self::IspConnectionType => 'ISP Connection Type',
            default => Str::of($this->value)->replace('_', ' ')->title()->toString(),
        };
    }
}
