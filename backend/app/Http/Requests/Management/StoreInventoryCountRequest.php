<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesInventoryCountFields;
use App\Models\InventoryCount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInventoryCountRequest extends FormRequest
{
    use ValidatesInventoryCountFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryCount::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->inventoryCountRules();
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->requireReachableOffices()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->inventoryCountAttributes();
    }
}
