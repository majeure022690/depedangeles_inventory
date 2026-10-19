<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PersonnelLibraryType;
use App\Models\PersonnelLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonnelUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('personnel'));
    }

    /**
     * See PersonnelStoreRequest's doc-comment for the Tier 1/Tier 2 split
     * rationale (lookup-normalization ADR, Step 2 — Personnel only).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fundSources = PersonnelLibrary::activeValues(PersonnelLibraryType::TeachersFundingSource);

        return [
            'employee_id' => [
                'required', 'string', 'max:255',
                Rule::unique('personnel', 'employee_id')->ignore($this->route('personnel')),
            ],
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
