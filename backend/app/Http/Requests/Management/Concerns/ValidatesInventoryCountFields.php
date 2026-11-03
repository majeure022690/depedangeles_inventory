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
        ];
    }
}
