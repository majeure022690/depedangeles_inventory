<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EquipmentLibraryType;
use App\Models\Equipment;
use App\Models\EquipmentLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Equipment::class);
    }

    /**
     * Deliberately excludes current_accountable_officer_id/current_end_user_id,
     * and qr_code (auto-derived once QR generation is built — not a user
     * input). Initial accountability is assigned by posting a
     * "Beginning Inventory"-type transaction via
     * EquipmentTransactionController::store right after creation, so
     * current_* pointers are always backed by an audit-log row from day one.
     *
     * TIER 1/TIER 2 SPLIT (lookup-normalization ADR, Step 2 — Equipment
     * only): the 5 Tier 1 fields (item type, brand, category,
     * classification, condition) are real foreign keys now — validated by
     * existence against their dedicated reference table, restricted to
     * active rows the same way Rule::in(activeValues()) used to. The
     * remaining lookup-backed fields are Tier 2 — still validated strings,
     * just repointed at EquipmentLibrary/EquipmentLibraryType instead of
     * the old global Lookup/LookupType.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_no' => ['required', 'string', 'max:255', Rule::unique('equipment', 'property_no')],
            'old_property_no' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],

            'item_type_id' => ['required', 'integer', Rule::exists('item_types', 'id')->where('is_active', true)],
            'uom' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::UnitOfMeasure))],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'model' => ['nullable', 'string', 'max:255'],
            'specifications' => ['nullable', 'string'],

            'non_dcp' => ['boolean'],
            'dcp_package' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DcpPackage))],
            'dcp_year' => ['nullable', 'integer', 'digits:4'],

            'equipment_category_id' => ['required', 'integer', Rule::exists('equipment_categories', 'id')->where('is_active', true)],
            'equipment_classification_id' => ['required', 'integer', Rule::exists('equipment_classifications', 'id')->where('is_active', true)],

            'gl_sl_code' => ['nullable', 'string', 'max:255'],
            'uacs' => ['nullable', 'string', 'max:255'],

            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'received_date' => ['nullable', 'date'],
            'estimated_useful_life' => ['nullable', 'integer', 'min:0'],

            'mode_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => ['nullable', 'string', 'max:255'],
            'source_fund' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],
            'allotment_class' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::AllotmentClass))],

            'pm_plan' => ['nullable', 'string', 'max:255'],
            'equipment_location' => ['nullable', 'string'],

            'non_functional' => ['boolean'],
            'equipment_condition_id' => ['required', 'integer', Rule::exists('equipment_conditions', 'id')->where('is_active', true)],
            'disposition_status' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DispositionStatus))],

            'remarks' => ['nullable', 'string'],

            'under_warranty' => ['boolean'],
            'end_warranty_date' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
