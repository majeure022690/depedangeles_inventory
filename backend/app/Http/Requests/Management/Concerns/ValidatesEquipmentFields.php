<?php

declare(strict_types=1);

namespace App\Http\Requests\Management\Concerns;

use App\Enums\EquipmentLibraryType;
use App\Models\Equipment;
use App\Models\EquipmentLibrary;
use Illuminate\Validation\Rule;

/**
 * Fields shared by create and update. Category and GL-SL code are derived
 * from the acquisition cost, and current_* pointers and qr_code change only
 * through a recorded transaction, so none of them is accepted here.
 */
trait ValidatesEquipmentFields
{
    /**
     * A donor only for donor sources, and a DCP package only for DCP items.
     */
    protected function prepareForValidation(): void
    {
        $cleared = [];

        if (! in_array($this->input('source_acquisition'), Equipment::DONOR_SOURCES, true)) {
            $cleared['donor'] = null;
        }

        if ($this->boolean('non_dcp')) {
            $cleared['dcp_package'] = null;
            $cleared['dcp_year'] = null;
        }

        $this->merge($cleared);
    }

    /**
     * @return array<string, mixed>
     */
    protected function equipmentRules(): array
    {
        $requiresDcp = Rule::requiredIf(fn () => ! $this->boolean('non_dcp'));

        return [
            'old_property_no' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:255'],

            'item_type_id' => ['required', 'integer', Rule::exists('item_types', 'id')->where('is_active', true)],
            'uom' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::UnitOfMeasure))],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'model' => ['required', 'string', 'max:255'],
            'specifications' => ['required', 'string'],

            'non_dcp' => ['required', 'boolean'],
            'dcp_package' => [$requiresDcp, 'nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DcpPackage))],
            'dcp_year' => [$requiresDcp, 'nullable', 'integer', 'digits:4'],

            'equipment_classification_id' => ['required', 'integer', Rule::exists('equipment_classifications', 'id')->where('is_active', true)],

            'uacs' => ['nullable', 'string', 'max:255'],

            'acquisition_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'received_date' => ['required', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'estimated_useful_life' => ['nullable', 'integer', 'min:0'],

            'mode_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => [Rule::requiredIf(fn () => in_array($this->input('source_acquisition'), Equipment::DONOR_SOURCES, true)), 'nullable', 'string', 'max:255'],
            'source_fund' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],
            'allotment_class' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::AllotmentClass))],

            'pm_plan' => ['nullable', 'string', 'max:255'],
            'equipment_location' => ['required', 'string'],
            'used_by' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::UsedBy))],

            'non_functional' => ['required', 'boolean'],
            'equipment_condition_id' => ['required', 'integer', Rule::exists('equipment_conditions', 'id')->where('is_active', true)],
            'disposition_status' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DispositionStatus))],

            'remarks' => ['nullable', 'string'],

            'under_warranty' => ['nullable', 'boolean'],
            'end_warranty_date' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function equipmentAttributes(): array
    {
        return [
            'property_no' => 'property number',
            'old_property_no' => 'old property number',
            'item_type_id' => 'item type',
            'uom' => 'unit of measure',
            'brand_id' => 'brand',
            'non_dcp' => 'non-DCP',
            'dcp_package' => 'DCP package',
            'dcp_year' => 'DCP year',
            'equipment_classification_id' => 'equipment classification',
            'uacs' => 'UACS code',
            'received_date' => 'acquisition date',
            'mode_acquisition' => 'mode of acquisition',
            'source_acquisition' => 'source of acquisition',
            'source_fund' => 'source of funds',
            'pm_plan' => 'PM plan',
            'used_by' => 'used by',
            'equipment_condition_id' => 'equipment condition',
            'end_warranty_date' => 'warranty end date',
        ];
    }
}
