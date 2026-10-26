<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\PersonnelLibraryType;
use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdatePersonnelRequest extends FormRequest
{
    use RestrictsScopedAssignments;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->personnel());
    }

    /**
     * See StorePersonnelRequest's doc-comment for the Tier 1/Tier 2 split
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
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule($this->personnel()->office_id)],

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

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->lockForScopedActors(['office_id' => [$this->personnel()->office_id]]),
            $this->lockUnchangedForScopedActors(['employee_id' => $this->personnel()->employee_id]),
        ];
    }

    private function personnel(): Personnel
    {
        $personnel = $this->route('personnel');

        return $personnel instanceof Personnel ? $personnel : throw new NotFoundHttpException;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StorePersonnelRequest)->attributes();
    }
}
