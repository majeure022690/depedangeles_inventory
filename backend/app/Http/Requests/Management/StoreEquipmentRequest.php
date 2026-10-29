<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesDesktopBundles;
use App\Http\Requests\Management\Concerns\ValidatesEquipmentFields;
use App\Http\Requests\Management\Concerns\ValidatesEquipmentTransactions;
use App\Models\Equipment;
use App\Models\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The property number is generated on save, and the initial
 * `accountability` is recorded as the record's first transaction, as part
 * of creating it.
 */
class StoreEquipmentRequest extends FormRequest
{
    use ValidatesDesktopBundles, ValidatesEquipmentFields, ValidatesEquipmentTransactions;

    public function authorize(): bool
    {
        return $this->user()->can('create', Equipment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_no' => ['prohibited'],
            ...$this->equipmentRules(),
            'accountability' => ['required', 'array'],
            ...$this->transactionRules('accountability.', requireHolders: true),
            ...$this->peripheralRowsRules(required: false),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('item_type_id')) {
                return;
            }

            $itemType = ItemType::find($this->integer('item_type_id'));

            if (blank($itemType?->abbreviation)) {
                $validator->errors()->add('item_type_id', __('This item type has no abbreviation yet, so no property number can be generated.'));
            }

            if (filled($this->input('peripherals')) && $itemType?->isBundleParent() !== true) {
                $validator->errors()->add('peripherals', __('Only a Desktop (package) or CPU can have peripherals.'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function equipmentAttributesToSave(): array
    {
        return $this->safe()->except(['property_no', 'accountability', 'peripherals']);
    }

    /**
     * @return array<string, mixed>
     */
    public function accountability(): array
    {
        /** @var array<string, mixed> $accountability */
        $accountability = $this->validated('accountability');

        return $accountability;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'property_no.prohibited' => __('The property number is generated when the record is saved.'),
            ...$this->transactionMessages('accountability.'),
            ...$this->peripheralRowsMessages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...$this->equipmentAttributes(),
            'accountability' => 'accountability',
            ...$this->transactionAttributes('accountability.'),
            ...$this->peripheralRowsAttributes(),
        ];
    }
}
