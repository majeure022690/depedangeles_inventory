<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Enums\EquipmentLibraryType;
use App\Models\EquipmentLibrary;
use Illuminate\Validation\Rule;

trait ValidatesEquipmentTransactions
{
    /**
     * With $requireHolders the accountable officer, end user and receiver,
     * and the two assignment dates, must all be named.
     *
     * @return array<string, mixed>
     */
    protected function transactionRules(string $prefix = '', bool $requireHolders = false): array
    {
        $presence = $requireHolders ? 'required' : 'nullable';
        $documentTypes = EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType);

        return [
            "{$prefix}transaction_type" => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType))],

            "{$prefix}accountable_officer_id" => $this->personnelRule($presence),
            "{$prefix}end_user_id" => $this->personnelRule($presence),
            "{$prefix}received_by_id" => $this->personnelRule($presence),

            "{$prefix}date_assigned_accountable_officer" => [$presence, 'date'],
            "{$prefix}date_assigned_end_user" => [$presence, 'date'],
            "{$prefix}date_received_new_accountable" => ['nullable', 'date'],

            "{$prefix}supporting_documents1" => ['nullable', 'string', Rule::in($documentTypes)],
            "{$prefix}or_si_dr_iar_no" => ['nullable', 'string', 'max:255'],

            "{$prefix}supporting_documents2" => ['nullable', 'string', Rule::in($documentTypes)],
            "{$prefix}par_ics_rrsp_rs_wmr_no" => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function transactionMessages(string $prefix = ''): array
    {
        $message = __('Choose someone within your access.');

        return [
            "{$prefix}accountable_officer_id.in" => $message,
            "{$prefix}end_user_id.in" => $message,
            "{$prefix}received_by_id.in" => $message,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function transactionAttributes(string $prefix = ''): array
    {
        return [
            "{$prefix}transaction_type" => 'transaction type',
            "{$prefix}accountable_officer_id" => 'accountable officer',
            "{$prefix}end_user_id" => 'end user',
            "{$prefix}received_by_id" => 'received by',
            "{$prefix}date_assigned_accountable_officer" => 'date assigned to accountable officer',
            "{$prefix}date_assigned_end_user" => 'date assigned to end user',
            "{$prefix}date_received_new_accountable" => 'date received by new accountable officer',
            "{$prefix}supporting_documents1" => 'supporting document',
            "{$prefix}or_si_dr_iar_no" => 'OR/SI/DR/IAR number',
            "{$prefix}supporting_documents2" => 'supporting document',
            "{$prefix}par_ics_rrsp_rs_wmr_no" => 'PAR/ICS/RRSP/RS/WMR number',
        ];
    }

    /**
     * Personnel the acting user may assign: anyone not deleted, or only those within their data scope.
     *
     * @return list<mixed>
     */
    private function personnelRule(string $presence): array
    {
        $reachable = $this->user()->scopedPersonnelIds();

        return [$presence, 'integer', $reachable === null ? Rule::exists('personnel', 'id')->withoutTrashed() : Rule::in($reachable)];
    }
}
