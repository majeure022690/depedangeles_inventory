<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class ConfirmEquipmentReceiptRequest extends FormRequest
{
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
            'equipment_ids' => ['required', 'array', 'min:1', 'max:'.BulkStoreEquipmentTransactionRequest::MAX_ITEMS],
            'equipment_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Every selected item must exist, be within the user's reach and be awaiting receipt.
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

            foreach (array_diff($ids, $items->modelKeys()) as $missing) {
                $validator->errors()->add('equipment_ids', __('Item #:id was not found.', ['id' => $missing]));
            }

            foreach ($items as $item) {
                $label = $item->property_no ?? "#{$item->id}";

                if (Gate::forUser($this->user())->denies('createTransaction', $item)) {
                    $validator->errors()->add('equipment_ids', __(':item is outside your access.', ['item' => $label]));
                } elseif ($item->awaiting_receipt_since === null) {
                    $validator->errors()->add('equipment_ids', __(':item is not awaiting receipt.', ['item' => $label]));
                }
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
    public function attributes(): array
    {
        return ['equipment_ids' => 'selected items'];
    }
}
