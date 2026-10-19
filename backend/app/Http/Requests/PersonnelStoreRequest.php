<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PersonnelLibraryType;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonnelStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Personnel::class);
    }

    /**
     * TIER 1/TIER 2 SPLIT (lookup-normalization ADR, Step 2 — Personnel
     * only): the 3 Tier 1 fields (position, RO office, SDO office) are
     * real foreign keys now — validated by existence against their
     * dedicated reference table, restricted to active rows, the same way
     * Rule::in(activeValues()) used to restrict to active lookup values.
     * The remaining lookup-backed fields are Tier 2 — still validated
     * strings, just repointed at PersonnelLibrary/PersonnelLibraryType
     * instead of the old global Lookup/LookupType. `fund_source` is the
     * one JSON-array field (ADR Question 4): each element is now a
     * `personnel_libraries` row id (teachers_funding_source type), not a
     * value string, validated per-element via Rule::exists(). The `max:`
     * bound (count of active TeachersFundingSource rows) mirrors the
     * pattern StakeholderProfileUpdateRequest/
     * InternetConnectivitySurveyUpdateRequest use for their array fields —
     * without it, a client could submit an array of arbitrary length and
     * force one Rule::exists() query per element.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fundSources = PersonnelLibrary::activeValues(PersonnelLibraryType::TeachersFundingSource);

        return [
            'employee_id' => ['required', 'string', 'max:255', Rule::unique('personnel', 'employee_id')],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', Rule::in(PersonnelLibrary::activeValues(PersonnelLibraryType::NameSuffix))],

            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('is_active', true)],
            'ro_office_id' => ['nullable', 'integer', Rule::exists('ro_offices', 'id')->where('is_active', true)],
            'sdo_office_id' => ['nullable', 'integer', Rule::exists('sdo_offices', 'id')->where('is_active', true)],

            'oic' => ['boolean'],
            'oic_office' => ['nullable', 'string', 'max:255'],

            'mobile_1' => ['nullable', 'string', 'max:20'],
            'mobile_2' => ['nullable', 'string', 'max:20'],
            'deped_email' => ['nullable', 'email', 'max:255'],
            'personal_email' => ['nullable', 'email', 'max:255'],

            'date_hired' => ['nullable', 'date'],

            'non_deped_funded' => ['boolean'],
            'fund_source' => ['nullable', 'array', 'max:'.count($fundSources)],
            'fund_source.*' => [
                'integer',
                Rule::exists('personnel_libraries', 'id')
                    ->where('type', PersonnelLibraryType::TeachersFundingSource->value)
                    ->where('is_active', true),
            ],

            'inactive' => ['boolean'],
            'separation_date' => ['nullable', 'date'],
            'separation_cause' => ['nullable', 'string', Rule::in(PersonnelLibrary::activeValues(PersonnelLibraryType::CauseOfSeparation))],

            'transferred_from' => ['nullable', 'string', 'max:255'],
            'transferred_to' => ['nullable', 'string', 'max:255'],
        ];
    }
}
