<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesInventoryCountFields
{
    /**
     * @return array<string, mixed>
     */
    protected function inventoryCountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'as_of_date' => ['required', 'date'],
            'office_ids' => ['required', 'array', 'min:1', 'max:500'],
            'office_ids.*' => ['integer', 'distinct', Rule::exists('offices', 'id')->where('is_active', true)],
            'committee_chair_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')],
            'committee_member_ids' => ['nullable', 'array', 'max:20'],
            'committee_member_ids.*' => ['integer', 'distinct', Rule::exists('personnel', 'id')],
            'coa_representative' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return Closure(Validator): void
     */
    protected function requireReachableOffices(): Closure
    {
        return function (Validator $validator) {
            foreach ((array) $this->input('office_ids', []) as $index => $officeId) {
                if (is_numeric($officeId) && ! $this->user()->reachesOffice((int) $officeId)) {
                    $validator->errors()->add("office_ids.{$index}", 'You can only count offices within your data scope.');
                }
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function inventoryCountAttributes(): array
    {
        return [
            'as_of_date' => 'as-of date',
            'office_ids' => 'offices',
            'office_ids.*' => 'office',
            'committee_chair_id' => 'committee chair',
            'committee_member_ids' => 'committee members',
            'committee_member_ids.*' => 'committee member',
            'coa_representative' => 'COA representative',
        ];
    }
}
