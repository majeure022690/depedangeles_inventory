<?php

namespace Tests\Concerns;

use App\Models\Brand;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Personnel;

/**
 * Valid equipment form payloads. Needs ReferenceDataSeeder.
 */
trait BuildsEquipmentPayloads
{
    /**
     * The fields create and update share.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function equipmentFields(array $overrides = []): array
    {
        return [
            'item_type_id' => ItemType::where('name', 'Laptop')->valueOrFail('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'model' => 'Latitude 3420',
            'serial_number' => 'SN-0001',
            'specifications' => 'Core i5, 8 GB RAM, 256 GB SSD',
            'acquisition_cost' => 55000,
            'received_date' => '2026-03-15',
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_classification_id' => EquipmentClassification::where('name', 'Office, ICT Equipment, Furniture And Fixtures')->valueOrFail('id'),
            'non_dcp' => true,
            'used_by' => 'Teacher',
            'equipment_location' => 'ICT Unit',
            'non_functional' => false,
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'disposition_status' => 'Normal',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function accountabilityFields(?Personnel $holder = null, array $overrides = []): array
    {
        $holder ??= Personnel::factory()->create();

        return [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $holder->id,
            'date_assigned_accountable_officer' => '2026-03-16',
            'end_user_id' => $holder->id,
            'date_assigned_end_user' => '2026-03-16',
            'received_by_id' => $holder->id,
            ...$overrides,
        ];
    }

    /**
     * A POST /api/equipment body.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function newEquipmentPayload(array $overrides = [], ?Personnel $holder = null): array
    {
        return [
            ...$this->equipmentFields(),
            'accountability' => $this->accountabilityFields($holder),
            ...$overrides,
        ];
    }
}
