<?php

namespace App\Http\Requests\Management\Concerns;

use App\Enums\PropertyCategory;
use App\Enums\PropertySource;
use App\Models\Personnel;
use App\Models\PropertyItem;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPropertyItemFields
{
    /**
     * @return array<string, mixed>
     */
    protected function propertyItemRules(): array
    {
        return [
            'category' => ['required', Rule::enum(PropertyCategory::class)],
            'article' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'property_no' => ['nullable', 'string', 'max:100'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'date_acquired' => ['nullable', 'date', 'before_or_equal:today'],
            'unit_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'on_hand_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'grade_level' => ['nullable', 'string', Rule::in(PropertyItem::GRADE_LEVELS)],
            'source' => ['nullable', Rule::enum(PropertySource::class)],
            'condition' => ['nullable', 'string', Rule::in(PropertyItem::CONDITIONS)],
            'with_are' => ['boolean'],
            'accountable_officer_id' => ['nullable', 'integer'],
            'end_user_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The accountable officer and end user must be active personnel of the item's office.
     *
     * @return Closure(Validator): void
     */
    protected function requirePersonnelAtOffice(?int $officeId): Closure
    {
        return function (Validator $validator) use ($officeId): void {
            foreach (['accountable_officer_id', 'end_user_id'] as $field) {
                if (! $this->filled($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $belongs = $officeId !== null && Personnel::query()
                    ->whereKey($this->integer($field))
                    ->where('office_id', $officeId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add($field, __('Choose someone from this office.'));
                }
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function propertyItemAttributes(): array
    {
        return [
            'office_id' => 'office',
            'property_no' => 'property number',
            'unit_of_measure' => 'unit of measure',
            'on_hand_count' => 'on-hand count',
            'source' => 'transferred / donated by',
            'with_are' => 'with MR/ARE',
            'accountable_officer_id' => 'accountable officer',
            'end_user_id' => 'end user',
        ];
    }
}
