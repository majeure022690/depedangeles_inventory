<?php

namespace App\Http\Requests\Management\Concerns;

use App\Models\Equipment;
use App\Models\ItemType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesDesktopBundles
{
    public const int MAX_PERIPHERAL_ROWS = 10;

    protected function bundleParent(): Equipment
    {
        /** @var Equipment $parent */
        $parent = $this->route('equipment');
        $parent->loadMissing('itemType');

        return $parent;
    }

    /**
     * Fails validation unless the routed equipment may carry peripherals.
     */
    protected function ensureBundleParent(Validator $validator, bool $needsPropertyNo): bool
    {
        $parent = $this->bundleParent();

        if (! $parent->canHavePeripherals()) {
            $validator->errors()->add('equipment', __('Only a Desktop (package) or CPU that is not itself linked can have peripherals.'));

            return false;
        }

        if ($needsPropertyNo && blank($parent->property_no)) {
            $validator->errors()->add('equipment', __('Give the desktop a property number before adding peripherals.'));

            return false;
        }

        return true;
    }

    /**
     * A null brand or condition takes the desktop's.
     *
     * @return array<string, mixed>
     */
    protected function peripheralRowsRules(bool $required): array
    {
        return [
            'peripherals' => [...($required ? ['required', 'min:1'] : ['sometimes']), 'array', 'max:'.self::MAX_PERIPHERAL_ROWS],
            'peripherals.*' => ['array'],
            'peripherals.*.item_type_id' => [
                'required', 'integer',
                Rule::exists('item_types', 'id')->where('is_active', true)->whereIn('name', ItemType::PERIPHERAL_NAMES),
            ],
            'peripherals.*.serial_number' => ['nullable', 'string', 'max:255'],
            'peripherals.*.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'peripherals.*.model' => ['nullable', 'string', 'max:255'],
            'peripherals.*.specifications' => ['nullable', 'string'],
            'peripherals.*.equipment_condition_id' => ['nullable', 'integer', Rule::exists('equipment_conditions', 'id')->where('is_active', true)],
            'peripherals.*.acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'peripherals.*.remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function peripheralRowsMessages(): array
    {
        return ['peripherals.*.item_type_id.exists' => __('A peripheral must be a Monitor, Keyboard or Mouse.')];
    }

    /**
     * @return array<string, string>
     */
    protected function peripheralRowsAttributes(): array
    {
        return [
            'peripherals.*.item_type_id' => 'item type',
            'peripherals.*.serial_number' => 'serial number',
            'peripherals.*.brand_id' => 'brand',
            'peripherals.*.model' => 'model',
            'peripherals.*.specifications' => 'specifications',
            'peripherals.*.equipment_condition_id' => 'equipment condition',
            'peripherals.*.acquisition_cost' => 'acquisition cost',
            'peripherals.*.remarks' => 'remarks',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function peripheralRows(): array
    {
        return array_values($this->validated('peripherals') ?? []);
    }
}
