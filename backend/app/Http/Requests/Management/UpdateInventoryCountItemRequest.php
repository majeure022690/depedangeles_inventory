<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\InventoryCountItem;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateInventoryCountItemRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('conduct', $this->route('inventoryCount'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', Rule::in(InventoryCountItem::RESULTS)],
            'found_condition_id' => ['nullable', 'integer', Rule::exists('equipment_conditions', 'id')],
            'found_holder_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')],
            'found_location' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'found_condition_id' => 'condition',
            'found_holder_id' => 'holder',
            'found_location' => 'location',
        ];
    }
}
