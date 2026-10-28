<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\EquipmentLibraryType;
use App\Models\Equipment;
use App\Models\EquipmentLibrary;
use App\Models\ItemType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEquipmentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('equipment'));
    }

    /**
     * Deliberately excludes current_accountable_officer_id/current_end_user_id
     * and qr_code — see StoreEquipmentRequest for why. Accountability
     * changes are made exclusively via EquipmentTransactionController::store.
     *
     * See StoreEquipmentRequest's doc-comment for the Tier 1/Tier 2 split
     * rationale (lookup-normalization ADR, Step 2 — Equipment only).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_no' => [
                'nullable', 'string', 'max:255',
                Rule::unique('equipment', 'property_no')->ignore($this->route('equipment')),
            ],
            'old_property_no' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],

            'item_type_id' => ['required', 'integer', Rule::exists('item_types', 'id')->where('is_active', true)],
            'uom' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::UnitOfMeasure))],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'model' => ['nullable', 'string', 'max:255'],
            'specifications' => ['nullable', 'string'],

            'non_dcp' => ['boolean'],
            'dcp_package' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DcpPackage))],
            'dcp_year' => ['nullable', 'integer', 'digits:4'],

            'equipment_category_id' => ['nullable', 'integer', Rule::exists('equipment_categories', 'id')->where('is_active', true)],
            'equipment_classification_id' => ['required', 'integer', Rule::exists('equipment_classifications', 'id')->where('is_active', true)],

            'gl_sl_code' => ['nullable', 'string', 'max:255'],
            'uacs' => ['nullable', 'string', 'max:255'],

            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'received_date' => ['nullable', 'date'],
            'estimated_useful_life' => ['nullable', 'integer', 'min:0'],

            'mode_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition))],
            'source_acquisition' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfAcquisition))],
            'donor' => ['nullable', 'string', 'max:255'],
            'source_fund' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::SourceOfFunds))],
            'allotment_class' => ['nullable', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::AllotmentClass))],

            'pm_plan' => ['nullable', 'string', 'max:255'],
            'equipment_location' => ['nullable', 'string'],

            'non_functional' => ['boolean'],
            'equipment_condition_id' => ['required', 'integer', Rule::exists('equipment_conditions', 'id')->where('is_active', true)],
            'disposition_status' => ['required', 'string', Rule::in(EquipmentLibrary::activeValues(EquipmentLibraryType::DispositionStatus))],

            'remarks' => ['nullable', 'string'],

            'under_warranty' => ['nullable', 'boolean'],
            'end_warranty_date' => ['nullable', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A desktop with peripherals and a linked peripheral keep item types that fit the bundle.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Equipment $equipment */
            $equipment = $this->route('equipment');
            $itemType = ItemType::find($this->integer('item_type_id'));

            if ($validator->errors()->has('item_type_id') || $itemType === null) {
                return;
            }

            if ($equipment->parent_equipment_id !== null && ! $itemType->isPeripheral()) {
                $validator->errors()->add('item_type_id', __('A linked peripheral must stay a Monitor, Keyboard or Mouse.'));
            } elseif (! $itemType->isBundleParent() && $equipment->children()->exists()) {
                $validator->errors()->add('item_type_id', __('A desktop with linked peripherals must stay a Desktop (package) or CPU.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StoreEquipmentRequest)->attributes();
    }
}
