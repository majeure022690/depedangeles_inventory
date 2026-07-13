<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed enum for the `type` column of `stakeholder_libraries` (Tier 2 of
 * the lookup-normalization ADR, docs/architecture-decisions/
 * lookup-normalization.md, Question 1/3). One of four small, per-table
 * successors to the deleted App\Enums\LookupType — scoped to the
 * StakeholderProfile bounded context. `source_of_electricity` also feeds
 * InternetConnectivitySurvey.electricity_sources (Question 4 of the ADR).
 */
enum StakeholderLibraryType: string
{
    case GovernanceLevel = 'governance_level';
    case CommunityContext = 'community_context';
    case TypeOfAccessRoad = 'type_of_access_road';
    case ByTransportation = 'by_transportation';
    case NearbyInstitution = 'nearby_institution';
    case CommunityEngagement = 'community_engagement';
    case SourceOfElectricity = 'source_of_electricity';

    /**
     * Human-readable label for admin UI. Falls back to a title-cased
     * version of the value — no acronym-heavy cases in this table need an
     * explicit override.
     */
    public function label(): string
    {
        return Str::of($this->value)->replace('_', ' ')->title()->toString();
    }
}
