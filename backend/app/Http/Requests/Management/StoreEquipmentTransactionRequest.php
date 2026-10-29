<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesEquipmentTransactions;
use App\Models\Equipment;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreEquipmentTransactionRequest extends FormRequest
{
    use ValidatesEquipmentTransactions;

    /**
     * Recording a transaction is checked against its own permission
     * (Permission::EquipmentTransactionsCreate via
     * EquipmentPolicy::createTransaction) — distinct from
     * Permission::EquipmentEdit, since reassigning accountability for a
     * physical asset is a more sensitive action than editing its
     * descriptive fields.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('createTransaction', $this->route('equipment'));
    }

    /**
     * accountable_officer_id / end_user_id are nullable: a transaction
     * that doesn't name one clears that side of Equipment's current_*
     * pointers (see EquipmentAccountabilityService's sync policy note) —
     * e.g. a Return transaction with no new accountable officer yet.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->transactionRules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Equipment $equipment */
            $equipment = $this->route('equipment');

            if ($equipment->parent_equipment_id !== null) {
                $validator->errors()->add('equipment', __('This peripheral moves with its desktop :property_no.', [
                    'property_no' => $equipment->parent()->withTrashed()->value('property_no'),
                ]));
            }
        }];
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
        return $this->transactionAttributes();
    }
}
