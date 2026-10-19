<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EquipmentLibraryType;
use App\Models\EquipmentLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentTransactionStoreRequest extends FormRequest
{
    /**
     * Recording a transaction is checked against its own permission
     * (Permission::EquipmentTransactionsCreate via
     * EquipmentPolicy::createTransaction) — distinct from
     * Permission::EquipmentEdit, since reassigning accountability for a
     * physical asset is a more sensitive action than editing its
     * descriptive fields.
     */
    public function authorize(): bool
    {
        return $this->user()->can('createTransaction', $this->route('equipment'));
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
        return [
            'transaction_type' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType))],

            'accountable_officer_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')],
            'end_user_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')],
            'received_by_id' => ['nullable', 'integer', Rule::exists('personnel', 'id')],

            'date_assigned_accountable_officer' => ['nullable', 'date'],
            'date_assigned_end_user' => ['nullable', 'date'],
            'date_received_new_accountable' => ['nullable', 'date'],

            'supporting_documents1' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType))],
            'or_si_dr_iar_no' => ['nullable', 'string', 'max:255'],

            'supporting_documents2' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType))],
            'par_ics_rrsp_rs_wmr_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
