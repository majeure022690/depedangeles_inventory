<?php

namespace Tests\Feature;

use App\Enums\Permission;
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
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Tier 1 fields (item type, brand, category, classification, condition)
 * are real FKs validated against active reference rows; Tier 2 fields are
 * validated strings. Accountability (current_* pointers) only ever moves
 * through POST /equipment/{id}/transactions.
 */
class EquipmentControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([
            Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'property_no' => 'AC-2026-IT-LT-000123',
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
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
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEquipment(array $overrides = []): Equipment
    {
        return Equipment::factory()->create(array_merge(
            $this->payload(['property_no' => fake()->unique()->bothify('PN-####-????')]),
            ['current_accountable_officer_id' => null, 'current_end_user_id' => null],
            $overrides,
        ));
    }

    public function test_store_validates_required_fields_and_creates_record_without_a_current_holder(): void
    {
        $user = $this->editor();

        // property_no and acquisition_cost are nullable ("pending" items).
        $this->actingAs($user)->postJson('/api/equipment', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_type_id', 'uom', 'brand_id', 'equipment_category_id'])
            ->assertJsonMissingValidationErrors(['property_no', 'acquisition_cost'])
            ->assertJsonPath('errors.item_type_id.0', 'The item type field is required.')
            ->assertJsonPath('errors.uom.0', 'The unit of measure field is required.');

        $response = $this->actingAs($user)->postJson('/api/equipment', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.property_no', 'AC-2026-IT-LT-000123')
            ->assertJsonPath('data.item', 'Laptop')
            ->assertJsonPath('data.brand_manufacturer', 'Dell')
            ->assertJsonPath('data.category', 'High-value')
            ->assertJsonPath('data.equipment_condition', 'Serviceable')
            ->assertJsonPath('data.current_accountable_officer', null)
            ->assertJsonPath('data.current_end_user', null);

        $this->assertEquals(55000, $response->json('data.acquisition_cost'));

        $equipment = Equipment::findOrFail($response->json('data.id'));
        $this->assertNull($equipment->current_accountable_officer_id);
        $this->assertNull($equipment->current_end_user_id);
        $this->assertSame($equipment->qrCodeUrl(), $response->json('data.qr_code'));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Equipment::class,
            'subject_id' => $equipment->id,
            'event' => 'created',
            'description' => 'equipment.created',
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_creates_a_pending_record_without_property_no_or_acquisition_cost(): void
    {
        $response = $this->actingAs($this->editor())->postJson('/api/equipment', $this->payload([
            'property_no' => null,
            'acquisition_cost' => null,
        ]))->assertCreated()
            ->assertJsonPath('data.property_no', null)
            ->assertJsonPath('data.acquisition_cost', null);

        $equipment = Equipment::findOrFail($response->json('data.id'));
        $this->assertNull($equipment->property_no);
        $this->assertNull($equipment->acquisition_cost);
    }

    public function test_store_allows_a_second_pending_record_with_no_property_no(): void
    {
        $user = $this->editor();

        $this->actingAs($user)->postJson('/api/equipment', $this->payload(['property_no' => null]))->assertCreated();
        $this->actingAs($user)->postJson('/api/equipment', $this->payload(['property_no' => null]))->assertCreated();

        $this->assertSame(2, Equipment::whereNull('property_no')->count());
    }

    public function test_store_rejects_a_nonexistent_item_type_id(): void
    {
        $this->actingAs($this->editor())->postJson('/api/equipment', $this->payload(['item_type_id' => 999999]))
            ->assertJsonValidationErrors(['item_type_id']);

        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-000123']);
    }

    public function test_store_rejects_an_item_type_id_that_has_been_deactivated(): void
    {
        $itemType = ItemType::where('name', 'Laptop')->firstOrFail();
        $itemType->update(['is_active' => false]);

        $this->actingAs($this->editor())->postJson('/api/equipment', $this->payload(['item_type_id' => $itemType->id]))
            ->assertJsonValidationErrors(['item_type_id']);

        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-000123']);
    }

    public function test_update_silently_ignores_current_holder_fields_not_accepted_by_the_form_request(): void
    {
        $equipment = $this->makeEquipment();
        $someoneElse = Personnel::factory()->create();

        $this->actingAs($this->editor())->putJson("/api/equipment/{$equipment->id}", $this->payload([
            'property_no' => $equipment->property_no,
            'acquisition_cost' => 60000,
            'current_accountable_officer_id' => $someoneElse->id,
        ]))->assertOk();

        $equipment->refresh();
        $this->assertEquals(60000, $equipment->acquisition_cost);
        $this->assertNull($equipment->current_accountable_officer_id);

        $updated = Activity::query()->where('subject_id', $equipment->id)->where('event', 'updated')->sole();
        $this->assertSame('equipment.updated', $updated->description);
        $this->assertArrayHasKey('acquisition_cost', $updated->attribute_changes['attributes']);
    }

    public function test_show_returns_the_record_with_its_transaction_history_newest_first(): void
    {
        $equipment = $this->makeEquipment();
        $officer = Personnel::factory()->create();
        $equipment->transactions()->create(['transaction_type' => 'Beginning Inventory']);
        $equipment->transactions()->create(['transaction_type' => 'Issuance/Transfer', 'accountable_officer_id' => $officer->id]);

        $this->actingAs($this->editor())->getJson("/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.item', 'Laptop')
            ->assertJsonPath('data.item_type_id', $equipment->item_type_id)
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.transaction_type', 'Issuance/Transfer')
            ->assertJsonPath('data.transactions.0.accountable_officer.id', $officer->id)
            ->assertJsonPath('data.transactions.1.transaction_type', 'Beginning Inventory');
    }

    public function test_index_omits_transactions_and_filters_by_condition_category_disposition_and_search(): void
    {
        $serviceableId = EquipmentCondition::where('name', 'Serviceable')->value('id');
        $forRepairId = EquipmentCondition::where('name', 'For Repair')->value('id');
        $highValueId = EquipmentCategory::where('name', 'High-value')->value('id');
        $lowValueId = EquipmentCategory::where('name', 'Low-value')->value('id');

        $this->makeEquipment(['property_no' => 'A-1', 'equipment_condition_id' => $serviceableId, 'equipment_category_id' => $highValueId, 'disposition_status' => 'Normal']);
        $this->makeEquipment(['property_no' => 'A-2', 'equipment_condition_id' => $forRepairId, 'equipment_category_id' => $lowValueId, 'disposition_status' => 'For Disposal']);
        $this->makeEquipment(['property_no' => null]);

        $user = $this->editor();

        $this->actingAs($user)->getJson('/api/equipment')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.transactions')
            // Pending (no property number) items sort last by default.
            ->assertJsonPath('data.2.property_no', null);

        $this->actingAs($user)->getJson("/api/equipment?condition={$serviceableId}")->assertJsonPath('meta.total', 2);
        $this->actingAs($user)->getJson("/api/equipment?category={$lowValueId}")->assertJsonPath('data.0.property_no', 'A-2')->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/equipment?disposition_status=For%20Disposal')->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/equipment?search=A-1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.property_no', 'A-1');
    }

    public function test_options_ship_form_filter_and_transaction_lists_and_personnel_only_to_transaction_recorders(): void
    {
        Personnel::factory()->create(['last_name' => 'Active']);
        Personnel::factory()->inactive()->create(['last_name' => 'Gone']);

        $response = $this->actingAs($this->editor())->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'form' => ['item_type', 'brand', 'category', 'classification', 'condition', 'unit_of_measure', 'dcp_package',
                    'mode_of_acquisition', 'source_of_acquisition', 'source_of_funds', 'allotment_class', 'disposition_status'],
                'filters' => ['condition', 'category', 'disposition_status'],
                'transaction' => ['transaction_type', 'supporting_document_type'],
                'personnel',
            ]])
            ->assertJsonCount(1, 'data.personnel');

        $this->assertIsInt($response->json('data.form.item_type.0.value'));
        $this->assertIsString($response->json('data.form.unit_of_measure.0.value'));

        // Without equipment.transactions.create: no personnel list.
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonMissingPath('data.personnel');
    }

    public function test_destroy_soft_deletes_with_equipment_delete_and_is_forbidden_without_it(): void
    {
        $equipment = $this->makeEquipment();

        $this->actingAs($this->editor())->deleteJson("/api/equipment/{$equipment->id}")->assertForbidden();
        $this->assertNotSoftDeleted('equipment', ['id' => $equipment->id]);

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentDelete]))->deleteJson("/api/equipment/{$equipment->id}")->assertNoContent();
        $this->assertSoftDeleted('equipment', ['id' => $equipment->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $equipment->id, 'subject_type' => Equipment::class, 'event' => 'deleted']);
    }

    public function test_transaction_store_records_the_audit_event_and_syncs_current_holder_pointers(): void
    {
        $user = $this->editor();
        $officer = Personnel::factory()->create();
        $endUser = Personnel::factory()->create();
        $equipment = $this->makeEquipment();

        $this->actingAs($user)->postJson("/api/equipment/{$equipment->id}/transactions", [])
            ->assertJsonValidationErrors(['transaction_type']);

        $this->actingAs($user)->postJson("/api/equipment/{$equipment->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
            'end_user_id' => $endUser->id,
        ])->assertCreated()
            ->assertJsonPath('data.transaction_type', 'Beginning Inventory')
            ->assertJsonPath('data.accountable_officer.id', $officer->id)
            ->assertJsonPath('data.end_user.id', $endUser->id);

        $equipment->refresh();
        $this->assertSame($officer->id, $equipment->current_accountable_officer_id);
        $this->assertSame($endUser->id, $equipment->current_end_user_id);

        $transaction = EquipmentTransaction::sole();
        $this->assertSame($user->id, $transaction->recorded_by_user_id);

        $activity = Activity::query()->where('event', 'equipment.transaction.recorded')->sole();
        $this->assertSame(Equipment::class, $activity->subject_type);
        $this->assertSame($equipment->id, $activity->subject_id);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($transaction->id, $activity->properties['transaction_id']);

        // The pointer sync is not logged a second time as a plain update.
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $equipment->id, 'subject_type' => Equipment::class, 'event' => 'updated']);
    }

    public function test_a_later_transaction_reassigns_accountability_and_can_clear_the_end_user(): void
    {
        $user = $this->editor();
        $officer = Personnel::factory()->create();
        $newOfficer = Personnel::factory()->create();
        $equipment = $this->makeEquipment();

        $this->actingAs($user)->postJson("/api/equipment/{$equipment->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
            'end_user_id' => $officer->id,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/equipment/{$equipment->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $newOfficer->id,
        ])->assertCreated();

        $equipment->refresh();
        $this->assertSame($newOfficer->id, $equipment->current_accountable_officer_id);
        $this->assertNull($equipment->current_end_user_id);
        $this->assertSame(2, EquipmentTransaction::count());
    }

    public function test_recording_a_transaction_needs_its_own_permission_not_equipment_edit(): void
    {
        $equipment = $this->makeEquipment();
        $editor = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit]);

        $this->actingAs($editor)->postJson("/api/equipment/{$equipment->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
        ])->assertForbidden();

        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_transactions_are_append_only(): void
    {
        $equipment = $this->makeEquipment();
        $equipment->transactions()->create(['transaction_type' => 'Beginning Inventory']);
        $transaction = EquipmentTransaction::sole();
        $admin = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentDelete, Permission::EquipmentTransactionsCreate]);

        $this->actingAs($admin)->putJson("/api/equipment/{$equipment->id}/transactions/{$transaction->id}", [])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/equipment/{$equipment->id}/transactions/{$transaction->id}")->assertNotFound();
    }

    public function test_direct_write_to_current_holder_pointers_outside_the_service_is_blocked(): void
    {
        $equipment = $this->makeEquipment();
        $officer = Personnel::factory()->create();

        $this->expectException(AccountabilitySyncRequiredException::class);

        $equipment->update(['current_accountable_officer_id' => $officer->id]);
    }
}
