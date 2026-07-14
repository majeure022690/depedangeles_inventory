<?php

namespace Tests\Feature;

use App\Exceptions\AccountabilitySyncRequiredException;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipment's Tier 1 fields (item type, brand, category, classification,
 * condition) are real foreign keys as of the lookup-normalization ADR's
 * Step 2 — fixtures here pull real ids from the reference tables (seeded
 * by ReferenceDataSeeder) rather than the old Lookup/LookupType system,
 * which Equipment no longer consults at all. Tier 2 fields (uom,
 * disposition_status, mode_acquisition, source_acquisition, source_fund,
 * allotment_class, transaction_type) stay string-validated, now against
 * the real seeded EquipmentLibrary rows instead of ad hoc test fixtures.
 */
class EquipmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeEquipment(array $overrides = []): Equipment
    {
        return Equipment::factory()->create(array_merge([
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'uom' => 'Piece',
            'disposition_status' => 'Normal',
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
        ], $overrides));
    }

    public function test_store_validates_required_fields_and_creates_record_without_a_current_holder(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        // property_no and acquisition_cost are deliberately absent here:
        // both are nullable now (equipment can be registered "pending" an
        // Asset Management Office-assigned property number/cost — see
        // 2026_07_14_132356's migration), so an empty payload must NOT
        // produce validation errors for either.
        $this->actingAs($user)->post(route('equipment.store'), [])
            ->assertSessionHasErrors(['item_type_id', 'uom', 'brand_id', 'equipment_category_id'])
            ->assertSessionDoesntHaveErrors(['property_no', 'acquisition_cost']);

        $itemTypeId = ItemType::where('name', 'Laptop')->value('id');
        $brandId = Brand::where('name', 'Dell')->value('id');
        $categoryId = EquipmentCategory::where('name', 'High-value')->value('id');
        $classificationId = EquipmentClassification::query()->active()->value('id');
        $conditionId = EquipmentCondition::where('name', 'Serviceable')->value('id');

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'property_no' => 'AC-2026-IT-LT-000123',
            'item_type_id' => $itemTypeId,
            'uom' => 'Piece',
            'brand_id' => $brandId,
            'equipment_category_id' => $categoryId,
            'equipment_classification_id' => $classificationId,
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => $conditionId,
            'disposition_status' => 'Normal',
        ]);

        $equipment = Equipment::where('property_no', 'AC-2026-IT-LT-000123')->firstOrFail();
        $response->assertRedirect(route('equipment.edit', $equipment));

        // No current holder until a transaction assigns one.
        $this->assertNull($equipment->current_accountable_officer_id);
        $this->assertNull($equipment->current_end_user_id);

        // Tier 1 fields landed on the new FK columns, relationships resolve.
        $this->assertSame($itemTypeId, $equipment->item_type_id);
        $this->assertSame('Laptop', $equipment->itemType->name);
        $this->assertSame($brandId, $equipment->brand_id);
        $this->assertSame('Dell', $equipment->brand->name);
        $this->assertSame($categoryId, $equipment->equipment_category_id);
        $this->assertSame('High-value', $equipment->equipmentCategory->name);
        $this->assertSame($conditionId, $equipment->equipment_condition_id);
        $this->assertSame('Serviceable', $equipment->equipmentCondition->name);
    }

    public function test_store_creates_a_pending_record_without_property_no_or_acquisition_cost(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $itemTypeId = ItemType::where('name', 'Laptop')->value('id');
        $brandId = Brand::where('name', 'Dell')->value('id');
        $categoryId = EquipmentCategory::where('name', 'High-value')->value('id');
        $classificationId = EquipmentClassification::query()->active()->value('id');
        $conditionId = EquipmentCondition::where('name', 'Serviceable')->value('id');

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'item_type_id' => $itemTypeId,
            'uom' => 'Piece',
            'brand_id' => $brandId,
            'equipment_category_id' => $categoryId,
            'equipment_classification_id' => $classificationId,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => $conditionId,
            'disposition_status' => 'Normal',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $equipment = Equipment::query()->whereNull('property_no')->latest('id')->firstOrFail();
        $response->assertRedirect(route('equipment.edit', $equipment));

        $this->assertNull($equipment->property_no);
        $this->assertNull($equipment->acquisition_cost);
    }

    public function test_store_allows_a_second_pending_record_with_no_property_no(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        Equipment::factory()->pending()->create([
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'uom' => 'Piece',
            'disposition_status' => 'Normal',
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
        ]);

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ]);

        // A second NULL property_no must not trip the unique() rule.
        $response->assertSessionDoesntHaveErrors(['property_no']);
        $this->assertSame(2, Equipment::whereNull('property_no')->count());
    }

    public function test_store_rejects_a_nonexistent_item_type_id(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'property_no' => 'AC-2026-IT-LT-000124',
            'item_type_id' => 999999,
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ]);

        $response->assertSessionHasErrors(['item_type_id']);
        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-000124']);
    }

    public function test_store_rejects_an_item_type_id_that_has_been_deactivated(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $itemType = ItemType::where('name', 'Laptop')->firstOrFail();
        $itemType->update(['is_active' => false]);

        $response = $this->actingAs($user)->post(route('equipment.store'), [
            'property_no' => 'AC-2026-IT-LT-000125',
            'item_type_id' => $itemType->id,
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ]);

        $response->assertSessionHasErrors(['item_type_id']);
        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-000125']);
    }

    public function test_update_silently_ignores_current_holder_fields_not_accepted_by_the_form_request(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $equipment = $this->makeEquipment();
        $someoneElse = Personnel::factory()->create();

        $response = $this->actingAs($user)->put(route('equipment.update', $equipment), array_merge(
            $equipment->only([
                'property_no', 'item_type_id', 'uom', 'brand_id', 'equipment_category_id',
                'equipment_classification_id', 'acquisition_cost', 'mode_acquisition',
                'source_acquisition', 'source_fund', 'allotment_class', 'equipment_condition_id',
                'disposition_status',
            ]),
            ['acquisition_cost' => 60000, 'current_accountable_officer_id' => $someoneElse->id]
        ));

        $response->assertRedirect(route('equipment.edit', $equipment));

        $equipment->refresh();
        $this->assertEquals(60000, $equipment->acquisition_cost);
        $this->assertNull($equipment->current_accountable_officer_id);
    }

    public function test_transaction_store_creates_audit_row_and_syncs_current_holder_pointers(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $officer = Personnel::factory()->create();
        $endUser = Personnel::factory()->create();
        $equipment = $this->makeEquipment();

        $this->actingAs($user)->post(route('equipment.transactions.store', $equipment), [])
            ->assertSessionHasErrors(['transaction_type']);

        $response = $this->actingAs($user)->post(route('equipment.transactions.store', $equipment), [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
            'end_user_id' => $endUser->id,
        ]);
        $response->assertRedirect(route('equipment.edit', $equipment));

        $equipment->refresh();
        $this->assertSame($officer->id, $equipment->current_accountable_officer_id);
        $this->assertSame($endUser->id, $equipment->current_end_user_id);
        $this->assertSame(1, EquipmentTransaction::count());

        $transaction = EquipmentTransaction::sole();
        $this->assertSame($user->id, $transaction->recorded_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'equipment.transaction.recorded',
            'subject_type' => $equipment->getMorphClass(),
            'subject_id' => $equipment->id,
        ]);
    }

    public function test_a_later_transaction_reassigns_accountability_and_can_clear_the_end_user(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $officer = Personnel::factory()->create();
        $newOfficer = Personnel::factory()->create();
        $equipment = $this->makeEquipment();

        $this->actingAs($user)->post(route('equipment.transactions.store', $equipment), [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
            'end_user_id' => $officer->id,
        ]);

        $this->actingAs($user)->post(route('equipment.transactions.store', $equipment), [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $newOfficer->id,
            // end_user_id omitted deliberately.
        ]);

        $equipment->refresh();
        $this->assertSame($newOfficer->id, $equipment->current_accountable_officer_id);
        $this->assertNull($equipment->current_end_user_id);
        $this->assertSame(2, EquipmentTransaction::count());
    }

    public function test_direct_write_to_current_holder_pointers_outside_the_service_is_blocked(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $equipment = $this->makeEquipment();
        $officer = Personnel::factory()->create();

        $this->expectException(AccountabilitySyncRequiredException::class);

        $equipment->update(['current_accountable_officer_id' => $officer->id]);
    }

    public function test_condition_category_and_disposition_scopes_filter_independently(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $serviceableId = EquipmentCondition::where('name', 'Serviceable')->value('id');
        $forRepairId = EquipmentCondition::where('name', 'For Repair')->value('id');
        $highValueId = EquipmentCategory::where('name', 'High-value')->value('id');
        $lowValueId = EquipmentCategory::where('name', 'Low-value')->value('id');

        $this->makeEquipment([
            'property_no' => 'A-1',
            'equipment_condition_id' => $serviceableId,
            'equipment_category_id' => $highValueId,
            'disposition_status' => 'Normal',
        ]);
        $this->makeEquipment([
            'property_no' => 'A-2',
            'equipment_condition_id' => $forRepairId,
            'equipment_category_id' => $lowValueId,
            'disposition_status' => 'For Disposal',
        ]);

        $this->assertSame(1, Equipment::condition($serviceableId)->count());
        $this->assertSame(1, Equipment::category($lowValueId)->count());
        $this->assertSame(1, Equipment::dispositionStatus('For Disposal')->count());
        $this->assertSame(2, Equipment::condition(null)->category(null)->dispositionStatus(null)->count());
        $this->assertSame(1, Equipment::search('A-1')->count());
    }
}
