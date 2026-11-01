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

        $atOffice = Personnel::query()->visibleTo($user)->where('office_id', $office->id)->pluck('id')->all();

        return [
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'accountable_officer_id' => ['nullable', 'integer', Rule::in($atOffice)],
            'assumed_on' => ['nullable', 'date'],
            'prepared_by_id' => ['nullable', 'integer', Rule::in($atOffice)],
            'school_head_id' => ['nullable', 'integer', Rule::in($atOffice)],
            'supply_officer_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array{school_year: string, accountable_officer_id: ?int, assumed_on: ?string, prepared_by: ?Personnel, school_head: ?Personnel, supply_officer: ?Personnel}
     */
    public function reportInput(): array
    {
        return [
            'school_year' => $this->string('school_year')->toString(),
            'accountable_officer_id' => $this->filled('accountable_officer_id') ? $this->integer('accountable_officer_id') : null,
            'assumed_on' => $this->optionalString('assumed_on'),
            'prepared_by' => $this->person('prepared_by_id'),
            'school_head' => $this->person('school_head_id'),
            'supply_officer' => $this->person('supply_officer_id'),
        ];
    }

    private function person(string $key): ?Personnel
    {
        return $this->filled($key) ? Personnel::query()->find($this->integer($key)) : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_year.regex' => 'The school year must look like 2025-2026.',
            'accountable_officer_id.in' => 'Choose an accountable officer from this office.',
            'prepared_by_id.in' => 'Choose someone from this office.',
            'school_head_id.in' => 'Choose someone from this office.',
        ];
    }
}
