<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed enum for the `type` column of `personnel_libraries` (Tier 2 of
 * the lookup-normalization ADR, docs/architecture-decisions/
 * lookup-normalization.md, Question 1/3). One of four small, per-table
 * successors to the deleted App\Enums\LookupType — scoped to the
 * Personnel bounded context only.
 */
enum PersonnelLibraryType: string
{
    case NameSuffix = 'name_suffix';
    case TeachersFundingSource = 'teachers_funding_source';
    case CauseOfSeparation = 'cause_of_separation';

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
