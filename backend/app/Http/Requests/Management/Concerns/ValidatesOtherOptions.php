<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Models\Equipment;

/**
 * The free-text "please specify" beside a dropdown, required when Others is picked.
 */
trait ValidatesOtherOptions
{
    private const array OTHER_LABELS = [
        'item_type_other' => 'item',
        'brand_other' => 'brand',
        'dcp_package_other' => 'DCP package',
        'source_fund_other' => 'source of funds',
        'equipment_condition_other' => 'condition',
    ];

    /**
     * @param  list<string>  $otherFields
     * @return array<string, mixed>
     */
    protected function otherRules(array $otherFields, string $prefix = ''): array
    {
        $rules = [];

        foreach ($otherFields as $other) {
            $others = Equipment::othersValue($other);
            $rules[$prefix.$other] = [
                ...($others === null ? [] : ['required_if:'.$prefix.Equipment::OTHER_FIELDS[$other][0].','.$others]),
                'nullable', 'string', 'max:255',
            ];
        }

        return $rules;
    }

    /**
     * @param  list<string>  $otherFields
     * @return array<string, string>
     */
    protected function otherMessages(array $otherFields, string $prefix = ''): array
    {
        $messages = [];

        foreach ($otherFields as $other) {
            $messages[$prefix.$other.'.required_if'] = __('Specify the :label when Others is selected.', ['label' => self::OTHER_LABELS[$other]]);
        }

        return $messages;
    }

    /**
     * @param  list<string>  $otherFields
     * @return array<string, string>
     */
    protected function otherAttributes(array $otherFields, string $prefix = ''): array
    {
        $attributes = [];

        foreach ($otherFields as $other) {
            $attributes[$prefix.$other] = self::OTHER_LABELS[$other].' (please specify)';
        }

        return $attributes;
    }
}
