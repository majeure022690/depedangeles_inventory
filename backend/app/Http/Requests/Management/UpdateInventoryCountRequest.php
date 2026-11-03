<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesInventoryCountFields;
use App\Models\InventoryCount;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The offices and as-of date are fixed once the count opens, since its expected list was built from them.
 */
class UpdateInventoryCountRequest extends FormRequest
{
    use ValidatesInventoryCountFields;

    public function authorize(): Response
    {
        $count = $this->inventoryCount();
        $response = Gate::forUser($this->user())->inspect('update', $count);

        if ($response->allowed() && ! in_array($count->status, [InventoryCount::DRAFT, InventoryCount::OPEN], true)) {
            return Response::deny('A closed or cancelled count can no longer be edited.');
        }

        return $response;
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
        return [
            $this->requireReachableOffices(),
            function (Validator $validator) {
                $count = $this->inventoryCount();

                if ($count->isDraft()) {
                    return;
                }

                $offices = array_map('intval', (array) $this->input('office_ids', []));
                sort($offices);
                $current = $count->office_ids;
                sort($current);

                if ($offices !== $current) {
                    $validator->errors()->add('office_ids', 'The offices cannot be changed once the count is open.');
                }

                if ($this->input('as_of_date') !== $count->as_of_date->toDateString()) {
                    $validator->errors()->add('as_of_date', 'The as-of date cannot be changed once the count is open.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->inventoryCountAttributes();
    }

    private function inventoryCount(): InventoryCount
    {
        /** @var InventoryCount */
        return $this->route('inventoryCount');
    }
}
