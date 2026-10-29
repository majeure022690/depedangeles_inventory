<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesEquipmentFields;
use App\Models\Equipment;
use App\Models\ItemType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The property number is never edited, and accountability changes only
 * through EquipmentTransactionController::store after creation.
 */
class UpdateEquipmentRequest extends FormRequest
{
    use ValidatesEquipmentFields;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('equipment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_no' => ['prohibited'],
            ...$this->equipmentRules(),
        ];
    }

    /**
     * A desktop with peripherals and a linked peripheral keep item types that fit the bundle.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Equipment $equipment */
            $equipment = $this->route('equipment');
            $itemType = ItemType::find($this->integer('item_type_id'));

            if ($validator->errors()->has('item_type_id') || $itemType === null) {
                return;
            }

            if ($equipment->parent_equipment_id !== null && ! $itemType->isPeripheral()) {
                $validator->errors()->add('item_type_id', __('A linked peripheral must stay a Monitor, Keyboard or Mouse.'));
            } elseif (! $itemType->isBundleParent() && $equipment->children()->exists()) {
                $validator->errors()->add('item_type_id', __('A desktop with linked peripherals must stay a Desktop (package) or CPU.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->equipmentAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['property_no.prohibited' => __('The property number cannot be changed.')];
    }
}
