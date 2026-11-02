<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Enums\EquipmentLibraryType;
use App\Models\EquipmentLibrary;
use App\Models\EquipmentTransactionDocument;
use Closure;
use Illuminate\Validation\Rule;

trait ValidatesEquipmentTransactions
{
    public const string BEGINNING_INVENTORY = 'Beginning Inventory';

    /** Types that hand the item to an accountable officer, who must then be named with the date. */
    public const array ASSIGNING_TYPES = ['Beginning Inventory', 'Delivery', 'Issuance/Transfer'];

    /** Types where someone physically receives the item, who must then be named with the date. */
    public const array RECEIVING_TYPES = ['Issuance/Transfer', 'Return'];

    public const string DISPOSAL = 'Disposal';

    public const string WASTE_MATERIAL_REPORT = 'Waste Material Report (WMR)';

    /**
     * Which fields are required follows the transaction type; with $requireHolders the
     * accountable officer, end user and receiver, and the two assignment dates, are always required.
     *
     * @return array<string, mixed>
     */
    protected function transactionRules(string $prefix = '', bool $requireHolders = false): array
    {
        $type = (string) $this->input("{$prefix}transaction_type");
        $assigning = $requireHolders || in_array($type, self::ASSIGNING_TYPES, true);
        $receiving = in_array($type, self::RECEIVING_TYPES, true);

        return [
            "{$prefix}transaction_type" => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType))],

            "{$prefix}accountable_officer_id" => $this->personnelRule($assigning ? 'required' : 'nullable'),
            "{$prefix}end_user_id" => $this->personnelRule($requireHolders ? 'required' : 'nullable'),
            "{$prefix}received_by_id" => $this->personnelRule($requireHolders || $receiving ? 'required' : 'nullable'),

            "{$prefix}date_assigned_accountable_officer" => [$assigning ? 'required' : "required_with:{$prefix}accountable_officer_id", 'nullable', 'date'],
            "{$prefix}date_assigned_end_user" => [$requireHolders ? 'required' : "required_with:{$prefix}end_user_id", 'nullable', 'date'],
            "{$prefix}date_received_new_accountable" => [$receiving ? 'required' : "required_with:{$prefix}received_by_id", 'nullable', 'date'],

            "{$prefix}documents" => [$type === self::BEGINNING_INVENTORY ? 'nullable' : 'required', 'array', 'max:'.EquipmentTransactionDocument::MAX_PER_TRANSACTION, $this->disposalNeedsWmr($type)],
            "{$prefix}documents.*.document_type" => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SupportingDocumentType))],
            "{$prefix}documents.*.document_no" => ['nullable', 'string', 'max:255'],
            "{$prefix}documents.*.upload_id" => ['required', 'integer', 'distinct', Rule::exists('equipment_transaction_documents', 'id')
                ->whereNull('equipment_transaction_id')
                ->where('uploaded_by_user_id', $this->user()->id)],
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
            "{$prefix}documents.required" => __('Add at least one supporting document.'),
            "{$prefix}documents.*.upload_id.required" => __('Attach the scanned document.'),
            "{$prefix}documents.*.upload_id.exists" => __('The attached file is no longer available; attach it again.'),
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
            "{$prefix}date_received_new_accountable" => 'date received',
            "{$prefix}documents" => 'supporting documents',
            "{$prefix}documents.*.document_type" => 'document type',
            "{$prefix}documents.*.document_no" => 'document number',
            "{$prefix}documents.*.upload_id" => 'attached file',
        ];
    }

    private function disposalNeedsWmr(string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            if ($type === self::DISPOSAL && ! collect(is_array($value) ? $value : [])->contains(fn ($document) => ($document['document_type'] ?? null) === self::WASTE_MATERIAL_REPORT)) {
                $fail(__('A disposal needs a Waste Material Report (WMR) among its documents.'));
            }
        };
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
