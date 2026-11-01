<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Http\Requests\Management\Concerns\AuthorizesReportOffice;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateRpcppeReportRequest extends FormRequest
{
    use AuthorizesReportOffice;

    public function authorize(): Response
    {
        return $this->authorizeReportOffice(Permission::ReportsRpcppe);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Office $office */
        $office = $this->route('office');
        /** @var User $user */
        $user = $this->user();

        return [
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'accountable_officer_id' => [
                'nullable',
                'integer',
                Rule::in(Personnel::query()->visibleTo($user)->where('office_id', $office->id)->pluck('id')->all()),
            ],
            'assumed_on' => ['nullable', 'date'],
            'prepared_by' => ['nullable', 'string', 'max:150'],
            'school_head' => ['nullable', 'string', 'max:150'],
            'supply_officer' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array{school_year: string, accountable_officer_id: ?int, assumed_on: ?string, prepared_by: ?string, school_head: ?string, supply_officer: ?string}
     */
    public function reportInput(): array
    {
        return [
            'school_year' => $this->string('school_year')->toString(),
            'accountable_officer_id' => $this->filled('accountable_officer_id') ? $this->integer('accountable_officer_id') : null,
            'assumed_on' => $this->optionalString('assumed_on'),
            'prepared_by' => $this->optionalString('prepared_by'),
            'school_head' => $this->optionalString('school_head'),
            'supply_officer' => $this->optionalString('supply_officer'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_year.regex' => 'The school year must look like 2025-2026.',
            'accountable_officer_id.in' => 'Choose an accountable officer from this office.',
        ];
    }
}
