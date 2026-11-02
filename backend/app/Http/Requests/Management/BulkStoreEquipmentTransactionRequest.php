<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Http\Requests\Management\Concerns\ValidatesEquipmentTransactions;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class BulkStoreEquipmentTransactionRequest extends FormRequest
{
    use ValidatesEquipmentTransactions;

    public const MAX_ITEMS = 200;

    /** @var Collection<int, Equipment>|null */
    private ?Collection $selected = null;

    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo(Permission::EquipmentTransactionsCreate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'equipment_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'equipment_ids.*' => ['integer', 'distinct'],
            ...$this->transactionRules(),
        ];
    }

    /**
     * Every selected item must exist, be one the user may record a transaction on, and not be a
     * peripheral (those move with their desktop). One bad item fails the whole request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('equipment_ids') || $validator->errors()->has('equipment_ids.*')) {
                return;
            }

            $ids = array_map('intval', (array) $this->input('equipment_ids'));
            $items = $this->equipment();
            $problems = [];

            foreach (array_diff($ids, $items->modelKeys()) as $missing) {
                $problems[] = __('Item #:id was not found.', ['id' => $missing]);
            }

            foreach ($items as $item) {
                $label = $item->property_no ?? "#{$item->id}";

                if (Gate::forUser($this->user())->denies('createTransaction', $item)) {
                    $problems[] = __(':item is outside your access.', ['item' => $label]);
                } elseif ($item->parent_equipment_id !== null) {
                    $problems[] = __(':item is a peripheral and moves with its desktop.', ['item' => $label]);
                }
            }

            foreach ($problems as $problem) {
                $validator->errors()->add('equipment_ids', $problem);
            }
        }];
    }

    /**
     * @return Collection<int, Equipment>
     */
    public function equipment(): Collection
    {
        return $this->selected ??= Equipment::query()
            ->whereKey(array_map('intval', (array) $this->input('equipment_ids')))
            ->with('createdBy:id,office_id')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->transactionMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...$this->transactionAttributes(), 'equipment_ids' => 'selected items'];
    }
}
