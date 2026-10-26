<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\EquipmentLibraryType;
use App\Models\EquipmentLibrary;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreEquipmentTransactionRequest extends FormRequest
{
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
        return [
            'transaction_type' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType))],

            'accountable_officer_id' => $this->personnelRule(),
            'end_user_id' => $this->personnelRule(),
            'received_by_id' => $this->personnelRule(),

            'date_assigned_accountable_officer' => ['nullable', 'date'],
            'date_assigned_end_user' => ['nullable', 'date'],
            'date_received_new_accountable' => ['nullable', 'date'],

            'supporting_documents1' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType))],
            'or_si_dr_iar_no' => ['nullable', 'string', 'max:255'],

            'supporting_documents2' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType))],
            'par_ics_rrsp_rs_wmr_no' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = __('Choose someone within your access.');

        return [
            'accountable_officer_id.in' => $message,
            'end_user_id.in' => $message,
            'received_by_id.in' => $message,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function personnelRule(): array
    {
        $reachable = $this->user()->scopedPersonnelIds();

        return ['nullable', 'integer', $reachable === null ? Rule::exists('personnel', 'id')->withoutTrashed() : Rule::in($reachable)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'accountable_officer_id' => 'accountable officer',
            'end_user_id' => 'end user',
            'received_by_id' => 'received by',
            'date_received_new_accountable' => 'date received by new accountable officer',
            'or_si_dr_iar_no' => 'OR/SI/DR/IAR number',
            'par_ics_rrsp_rs_wmr_no' => 'PAR/ICS/RRSP/RS/WMR number',
        ];
    }
}
