<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Exceptions\AccountabilitySyncRequiredException;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentCondition;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Tier 1 fields (item type, brand, category, classification, condition)
 * are real FKs validated against active reference rows; Tier 2 fields are
 * validated strings. Accountability (current_* pointers) is set by the
 * create form's initial transaction and afterwards only moves through
 * POST /equipment/{id}/transactions.
 */
class EquipmentControllerTest extends TestCase
{
    use BuildsEquipmentPayloads, CreatesUsersWithRoles, RefreshDatabase;

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
        return $this->equipmentFields($overrides);
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

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->editor())->postJson('/api/equipment', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'item_type_id', 'uom', 'brand_id', 'model', 'serial_number', 'specifications', 'acquisition_cost',
                'received_date', 'mode_acquisition', 'source_acquisition', 'source_fund', 'equipment_classification_id',
                'non_dcp', 'used_by', 'equipment_location', 'non_functional', 'equipment_condition_id', 'disposition_status',
                'accountability',
            ])
            ->assertJsonMissingValidationErrors(['property_no', 'old_property_no', 'uacs', 'allotment_class', 'estimated_useful_life',
                'pm_plan', 'remarks', 'supplier', 'under_warranty', 'end_warranty_date', 'equipment_category_id', 'gl_sl_code'])
            ->assertJsonPath('errors.item_type_id.0', 'The item type field is required.');

        $this->assertSame(0, Equipment::count());
    }

    public function test_store_creates_the_record_with_its_initial_accountability_and_audits_both(): void
    {
        $user = $this->editor();
        $officer = Personnel::factory()->create();
        $endUser = Personnel::factory()->create();
        $receiver = Personnel::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/equipment', $this->newEquipmentPayload([
            'accountability' => [
                'transaction_type' => 'Beginning Inventory',
                'accountable_officer_id' => $officer->id,
                'date_assigned_accountable_officer' => '2026-03-16',
                'end_user_id' => $endUser->id,
                'date_assigned_end_user' => '2026-03-17',
                'received_by_id' => $receiver->id,
                'date_received_new_accountable' => '2026-03-18',
                'supporting_documents2' => 'Property Acknowledgment Receipt (PAR)',
                'par_ics_rrsp_rs_wmr_no' => 'PAR-2026-001',
                'supporting_documents1' => 'Sales Invoice (SI)',
                'or_si_dr_iar_no' => 'SI-778',
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.property_no', 'AC-2026-IT-LPT-000001')
            ->assertJsonPath('data.item', 'Laptop')
            ->assertJsonPath('data.brand_manufacturer', 'Dell')
            ->assertJsonPath('data.category', 'High-value')
            ->assertJsonPath('data.gl_sl_code', '10605030')
            ->assertJsonPath('data.used_by', 'Teacher')
            ->assertJsonPath('data.equipment_condition', 'Serviceable')
            ->assertJsonPath('data.current_accountable_officer.id', $officer->id)
            ->assertJsonPath('data.current_end_user.id', $endUser->id);

        $this->assertEquals(55000, $response->json('data.acquisition_cost'));

        $equipment = Equipment::findOrFail($response->json('data.id'));
        $this->assertSame($equipment->qrCodeUrl(), $response->json('data.qr_code'));

        $transaction = EquipmentTransaction::sole();
        $this->assertSame($equipment->id, $transaction->equipment_id);
        $this->assertSame('Beginning Inventory', $transaction->transaction_type);
        $this->assertSame($receiver->id, $transaction->received_by_id);
        $this->assertSame('PAR-2026-001', $transaction->par_ics_rrsp_rs_wmr_no);
        $this->assertSame('Sales Invoice (SI)', $transaction->supporting_documents1);
        $this->assertSame('SI-778', $transaction->or_si_dr_iar_no);
        $this->assertSame($user->id, $transaction->recorded_by_user_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Equipment::class,
            'subject_id' => $equipment->id,
            'event' => 'created',
            'description' => 'equipment.created',
            'causer_id' => $user->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Equipment::class,
            'subject_id' => $equipment->id,
            'event' => 'equipment.transaction.recorded',
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_requires_the_accountability_holders_and_dates(): void
    {
        $this->actingAs($this->editor())->postJson('/api/equipment', $this->newEquipmentPayload(['accountability' => ['transaction_type' => '']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'accountability.transaction_type', 'accountability.accountable_officer_id', 'accountability.date_assigned_accountable_officer',
                'accountability.end_user_id', 'accountability.date_assigned_end_user', 'accountability.received_by_id',
            ])
            ->assertJsonMissingValidationErrors(['accountability.date_received_new_accountable', 'accountability.supporting_documents1',
                'accountability.or_si_dr_iar_no', 'accountability.supporting_documents2', 'accountability.par_ics_rrsp_rs_wmr_no']);

        $this->actingAs($this->editor())->postJson('/api/equipment', $this->newEquipmentPayload([
            'accountability' => $this->accountabilityFields(overrides: [
                'accountable_officer_id' => 999999,
                'transaction_type' => 'Borrowed',
                'supporting_documents2' => 'Napkin',
            ]),
        ]))->assertJsonValidationErrors(['accountability.accountable_officer_id', 'accountability.transaction_type', 'accountability.supporting_documents2']);

        $this->assertSame(0, Equipment::count());
        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_equipment_create_alone_covers_the_initial_accountability(): void
    {
        $creator = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate]);
        $holder = Personnel::factory()->create();

        $this->actingAs($creator)->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonPath('data.personnel.0.value', $holder->id);

        $this->actingAs($creator)->postJson('/api/equipment', $this->newEquipmentPayload(holder: $holder))
            ->assertCreated()
            ->assertJsonPath('data.current_accountable_officer.id', $holder->id);

        $this->assertSame($creator->id, EquipmentTransaction::sole()->recorded_by_user_id);
    }

    public function test_store_is_forbidden_without_equipment_create(): void
    {
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentTransactionsCreate]))
            ->postJson('/api/equipment', $this->newEquipmentPayload())
            ->assertForbidden();

        $this->assertSame(0, Equipment::count());
    }

    public function test_store_rejects_a_nonexistent_item_type_id(): void
    {
        $this->actingAs($this->editor())->postJson('/api/equipment', $this->newEquipmentPayload(['item_type_id' => 999999]))
            ->assertJsonValidationErrors(['item_type_id']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_store_rejects_an_item_type_id_that_has_been_deactivated(): void
    {
        $itemType = ItemType::where('name', 'Laptop')->firstOrFail();
        $itemType->update(['is_active' => false]);

        $this->actingAs($this->editor())->postJson('/api/equipment', $this->newEquipmentPayload(['item_type_id' => $itemType->id]))
            ->assertJsonValidationErrors(['item_type_id']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_update_silently_ignores_current_holder_and_accountability_fields(): void
    {
        $equipment = $this->makeEquipment();
        $someoneElse = Personnel::factory()->create();

        $this->actingAs($this->editor())->putJson("/api/equipment/{$equipment->id}", $this->payload([
            'acquisition_cost' => 60000,
            'current_accountable_officer_id' => $someoneElse->id,
            'accountability' => $this->accountabilityFields($someoneElse),
        ]))->assertOk()
            ->assertJsonPath('data.current_accountable_officer', null);

        $equipment->refresh();
        $this->assertEquals(60000, $equipment->acquisition_cost);
        $this->assertNull($equipment->current_accountable_officer_id);
        $this->assertSame(0, EquipmentTransaction::count());

        $updated = Activity::query()->where('subject_id', $equipment->id)->where('event', 'updated')->sole();
        $this->assertSame('equipment.updated', $updated->description);
        $this->assertArrayHasKey('acquisition_cost', $updated->attribute_changes['attributes']);
    }

    public function test_update_validates_the_same_required_fields(): void
    {
        $equipment = $this->makeEquipment();

        $this->actingAs($this->editor())->putJson("/api/equipment/{$equipment->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'item_type_id', 'uom', 'brand_id', 'model', 'serial_number', 'specifications', 'acquisition_cost',
                'received_date', 'mode_acquisition', 'source_acquisition', 'source_fund', 'equipment_classification_id',
                'non_dcp', 'used_by', 'equipment_location', 'non_functional', 'equipment_condition_id', 'disposition_status',
            ])
            ->assertJsonMissingValidationErrors(['property_no', 'accountability']);

        $this->actingAs($this->editor())->putJson("/api/equipment/{$equipment->id}", $this->payload(['used_by' => 'Visitor']))
            ->assertJsonValidationErrors(['used_by']);
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
        $this->makeEquipment(['property_no' => null, 'equipment_category_id' => $highValueId]);

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

    public function test_index_filters_by_dashboard_group_including_other_and_ships_the_group(): void
    {
        $itemType = fn (string $name) => ItemType::where('name', $name)->valueOrFail('id');

        $this->makeEquipment(['property_no' => 'D-1', 'item_type_id' => $itemType('Desktop (package)')]);
        $this->makeEquipment(['property_no' => 'D-2', 'item_type_id' => $itemType('Monitor')]);
        $this->makeEquipment(['property_no' => 'L-1', 'item_type_id' => $itemType('Laptop')]);
        $this->makeEquipment(['property_no' => 'O-1', 'item_type_id' => $itemType('Thin Client Server (Set)')]);

        $user = $this->editor();

        $this->actingAs($user)->getJson('/api/equipment?dashboard_group=desktop_computer')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.dashboard_group', 'desktop_computer');
        $this->actingAs($user)->getJson('/api/equipment?dashboard_group=other')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.property_no', 'O-1')
            ->assertJsonPath('data.0.dashboard_group', null);
        $this->actingAs($user)->getJson('/api/equipment?dashboard_group=not_a_group')->assertJsonPath('meta.total', 4);
    }

    public function test_index_filters_by_comma_separated_item_types(): void
    {
        $itemType = fn (string $name) => ItemType::where('name', $name)->valueOrFail('id');

        $this->makeEquipment(['property_no' => 'D-1', 'item_type_id' => $itemType('Desktop (package)')]);
        $this->makeEquipment(['property_no' => 'C-1', 'item_type_id' => $itemType('CPU')]);
        $this->makeEquipment(['property_no' => 'M-1', 'item_type_id' => $itemType('Monitor')]);

        $user = $this->editor();

        $this->actingAs($user)->getJson('/api/equipment?item_type='.$itemType('Desktop (package)').','.$itemType('CPU'))->assertJsonPath('meta.total', 2);
        $this->actingAs($user)->getJson('/api/equipment?item_type='.$itemType('Monitor'))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.property_no', 'M-1');
        $this->actingAs($user)->getJson('/api/equipment?item_type=abc,-1')->assertJsonPath('meta.total', 3);
    }

    public function test_options_ship_form_filter_and_transaction_lists_and_personnel_only_to_transaction_recorders(): void
    {
        Personnel::factory()->create(['last_name' => 'Active']);
        Personnel::factory()->inactive()->create(['last_name' => 'Gone']);

        $response = $this->actingAs($this->editor())->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'form' => ['item_type', 'brand', 'category', 'classification', 'condition', 'unit_of_measure', 'dcp_package',
                    'mode_of_acquisition', 'source_of_acquisition', 'source_of_funds', 'allotment_class', 'disposition_status', 'used_by'],
                'filters' => ['condition', 'category', 'disposition_status'],
                'transaction' => ['transaction_type', 'supporting_document_type'],
                'personnel',
            ]])
            ->assertJsonCount(1, 'data.personnel')
            ->assertJsonPath('data.form.used_by', [
                ['value' => 'Learner', 'label' => 'Learner'],
                ['value' => 'Teacher', 'label' => 'Teacher'],
                ['value' => 'Teaching-Related', 'label' => 'Teaching-Related'],
                ['value' => 'Non-Teaching', 'label' => 'Non-Teaching'],
            ])
            ->assertJsonPath('data.donor_sources', [
                'Local Government Unit (LGU)', 'Private Corporation', 'Non-Governmental Organization (NGO)',
                'DICT', 'Other National Government Agency', "Parent Teacher's Association",
            ])
            ->assertJsonPath('data.category_rules', [
                'threshold' => 50000,
                'low' => ['category' => 'Low-value', 'gl_sl_code' => '10405030'],
                'high' => ['category' => 'High-value', 'gl_sl_code' => '10605030'],
            ]);

        $this->assertIsInt($response->json('data.form.item_type.0.value'));
        $this->assertIsString($response->json('data.form.unit_of_measure.0.value'));

        // Without equipment.create or equipment.transactions.create: no personnel list.
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
