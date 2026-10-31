<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\Equipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewEquipmentPropertyNumberRequest extends FormRequest
{
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
            'item_type_id' => ['required', 'integer', Rule::exists('item_types', 'id')->where('is_active', true)],
            'received_date' => ['required', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_type_id' => 'item type',
            'received_date' => 'acquisition date',
        ];
    }
}
