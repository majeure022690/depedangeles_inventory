<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\Equipment;
use App\Models\InventoryCount;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryCountFoundItemRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('conduct', $this->inventoryCount());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', Rule::in($this->inventoryCount()->officeIdsFor($this->user()))],
            'equipment_id' => ['nullable', 'integer'],
            'description' => ['required_without:equipment_id', 'nullable', 'string', 'max:255'],
            'property_no' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'condition_id' => ['nullable', 'integer', Rule::exists('equipment_conditions', 'id')],
            'location' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $equipmentId = $this->input('equipment_id');

                if ($equipmentId === null || $validator->errors()->has('equipment_id')) {
                    return;
                }

                if (! Equipment::query()->visibleTo($this->user())->whereKey((int) $equipmentId)->exists()) {
                    $validator->errors()->add('equipment_id', 'The selected equipment is invalid.');

                    return;
                }

                if ($this->inventoryCount()->items()->where('equipment_id', (int) $equipmentId)->exists()) {
                    $validator->errors()->add('equipment_id', 'This item is already on the count\'s list. Mark it there instead.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['office_id' => 'office', 'equipment_id' => 'equipment', 'condition_id' => 'condition', 'serial_no' => 'serial number', 'property_no' => 'property number'];
    }

    private function inventoryCount(): InventoryCount
    {
        /** @var InventoryCount */
        return $this->route('inventoryCount');
    }
}
