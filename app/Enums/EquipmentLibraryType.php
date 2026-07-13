<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Backed enum for the `type` column of `equipment_libraries` (Tier 2 of
 * the lookup-normalization ADR, docs/architecture-decisions/
 * lookup-normalization.md, Question 1/3). One of four small, per-table
 * successors to the deleted App\Enums\LookupType — scoped to the
 * Equipment bounded context only.
 *
 * Cross-domain reads are expected and fine (e.g. IspAccount reusing
 * mode_of_acquisition/source_of_acquisition/source_of_funds,
 * StakeholderProfile reusing transaction_type) — see the ADR's "Cross-
 * domain reads of a Tier 2 table are expected and fine" note.
 */
enum EquipmentLibraryType: string
{
    case UnitOfMeasure = 'unit_of_measure';
    case DispositionStatus = 'disposition_status';
    case DcpPackage = 'dcp_package';
    case ModeOfAcquisition = 'mode_of_acquisition';
    case SourceOfAcquisition = 'source_of_acquisition';
    case SourceOfFunds = 'source_of_funds';
    case AllotmentClass = 'allotment_class';
    case SupportingDocumentType = 'supporting_document_type';
    case TransactionType = 'transaction_type';

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
