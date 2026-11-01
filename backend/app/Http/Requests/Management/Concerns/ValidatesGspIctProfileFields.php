<?php

namespace App\Http\Requests\Management\Concerns;

use App\Models\GspIctProfile;
use App\Models\IspAccount;
use App\Services\GspIctReport;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesGspIctProfileFields
{
    /**
     * @return array<string, mixed>
     */
    protected function gspIctProfileRules(?int $officeId, ?int $ignoreId = null): array
    {
        return [
            'school_year' => [
                'required', 'string', 'regex:/^\d{4}-\d{4}$/',
                Rule::unique('gsp_ict_profiles', 'school_year')->where('office_id', $officeId)->ignore($ignoreId),
            ],
            'as_of' => ['required', 'date'],
            'level' => ['required', 'string', Rule::in(array_keys(GspIctReport::LEVELS))],
            'radio_teachers' => ['nullable', 'array:'.implode(',', GspIctProfile::RADIO_GRADES)],
            'radio_teachers.*' => ['array:literacy,numeracy'],
            'radio_teachers.*.literacy' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'radio_teachers.*.numeracy' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'dict_free_wifi' => ['nullable', 'string', Rule::in(array_keys(GspIctProfile::DICT_FREE_WIFI))],
            'dict_free_wifi_installed' => ['nullable', 'string', 'max:30'],
            'isp_reliability' => ['nullable', 'array'],
            'isp_reliability.*' => ['nullable', 'string', Rule::in(GspIctProfile::RELIABILITY)],
            'school_head_name' => ['nullable', 'string', 'max:150'],
            'school_head_position' => ['nullable', 'string', 'max:150'],
            'ito_name' => ['nullable', 'string', 'max:150'],
            'planning_officer_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Reliability ratings may only be given for the office's own ISP accounts.
     *
     * @return Closure(Validator): void
     */
    protected function requireOfficeIspAccounts(?int $officeId): Closure
    {
        return function (Validator $validator) use ($officeId): void {
            $ids = array_map('intval', array_keys((array) $this->input('isp_reliability', [])));

            if ($ids === [] || $validator->errors()->has('isp_reliability')) {
                return;
            }

            $owned = $officeId === null ? 0 : IspAccount::query()->whereKey($ids)->where('office_id', $officeId)->count();

            if ($owned !== count(array_unique($ids))) {
                $validator->errors()->add('isp_reliability', __('Rate only this office\'s ISP accounts.'));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function gspIctProfileAttributes(): array
    {
        return [
            'office_id' => 'office',
            'as_of' => 'as-of date',
            'dict_free_wifi' => 'DICT Free WiFi',
            'dict_free_wifi_installed' => 'installation date',
            'ito_name' => 'Information Technology Officer',
            'radio_teachers.*.literacy' => 'literacy teachers',
            'radio_teachers.*.numeracy' => 'numeracy teachers',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function gspIctProfileMessages(): array
    {
        return [
            'school_year.regex' => 'The school year must look like 2025-2026.',
            'school_year.unique' => 'This office already has a profile for that school year.',
        ];
    }
}
