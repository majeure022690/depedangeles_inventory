<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesDesktopBundles;
use App\Models\Equipment;
use App\Models\ItemType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEquipmentPeripheralRequest extends FormRequest
{
    use ValidatesDesktopBundles;

    /**
     * Editing the desktop, creating a new equipment record, and recording
     * the desktop's accountability onto it.
     */
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $desktop = $this->route('equipment');

        foreach ([['update', $desktop], ['create', Equipment::class], ['createTransaction', $desktop]] as [$ability, $subject]) {
            $response = $gate->inspect($ability, $subject);

            if ($response->denied()) {
                return $response;
            }
        }

        return Response::allow();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_type_id' => [
                'required', 'integer',
                Rule::exists('item_types', 'id')->where('is_active', true)->whereIn('name', ItemType::PERIPHERAL_NAMES),
            ],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'model' => ['nullable', 'string', 'max:255'],
            'specifications' => ['nullable', 'string'],
            'equipment_condition_id' => ['required', 'integer', Rule::exists('equipment_conditions', 'id')->where('is_active', true)],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->ensureBundleParent($validator, needsPropertyNo: true);
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['item_type_id.exists' => __('A peripheral must be a Monitor, Keyboard or Mouse.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_type_id' => 'item type',
            'brand_id' => 'brand',
            'equipment_condition_id' => 'equipment condition',
        ];
    }
}
