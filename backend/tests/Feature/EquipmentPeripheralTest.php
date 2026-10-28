<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\EquipmentTransaction;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentPeripheralTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const PN = 'AC-2016-IT-DST-000001';

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->editor = $this->userWithPermissions([
            Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit,
            Permission::EquipmentDelete, Permission::EquipmentTransactionsCreate,
        ]);
    }

    private function itemType(string $name): int
    {
        return ItemType::where('name', $name)->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function equipment(string $itemType, array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'item_type_id' => $this->itemType($itemType),
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function desktop(array $attributes = []): Equipment
    {
        return $this->equipment('Desktop (package)', ['property_no' => self::PN, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'item_type_id' => $this->itemType('Monitor'),
            'serial_number' => 'MON-123',
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'model' => 'P2422H',
            'specifications' => '24 inch',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'acquisition_cost' => 8000,
            'remarks' => 'Bundled',
            ...$overrides,
        ];
    }

    private function addPeripheral(Equipment $desktop, ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->editor)
            ->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())
            ->assertCreated()
            ->json('data.property_no');
    }

    public function test_store_creates_a_child_numbered_after_the_desktop_inheriting_its_details(): void
    {
        $officer = Personnel::factory()->create();
        $desktop = $this->desktop([
            'current_accountable_officer_id' => $officer->id,
            'equipment_location' => 'ICT Unit',
            'received_date' => '2016-05-01',
            'mode_acquisition' => 'DepEd Purchase',
            'acquisition_cost' => 75000,
        ]);

        $response = $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Peripheral added.')
            ->assertJsonPath('data.property_no', self::PN.'-001')
            ->assertJsonPath('data.parent_equipment_id', $desktop->id)
            ->assertJsonPath('data.parent', ['id' => $desktop->id, 'property_no' => self::PN])
            ->assertJsonPath('data.item', 'Monitor')
            ->assertJsonPath('data.serial_number', 'MON-123')
            ->assertJsonPath('data.equipment_location', 'ICT Unit')
            ->assertJsonPath('data.received_date', '2016-05-01')
            ->assertJsonPath('data.mode_acquisition', 'DepEd Purchase')
            ->assertJsonPath('data.category', 'Low-value');

        $peripheral = Equipment::findOrFail($response->json('data.id'));
        $this->assertSame($officer->id, $peripheral->current_accountable_officer_id);
        $this->assertSame($this->editor->id, $peripheral->created_by_user_id);

        $this->assertSame(self::PN.'-002', $this->addPeripheral($desktop));

        $this->assertSame(2, Activity::query()->where('event', 'equipment.peripheral.created')->where('subject_id', $desktop->id)->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);
        $this->assertDatabaseHas('activity_log', ['event' => 'created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")
            ->assertJsonCount(2, 'data.children')
            ->assertJsonPath('data.children.0', [
                'id' => $peripheral->id,
                'property_no' => self::PN.'-001',
                'item' => 'Monitor',
                'serial_number' => 'MON-123',
                'brand_manufacturer' => 'Dell',
                'model' => 'P2422H',
                'equipment_condition' => 'Serviceable',
            ])
            ->assertJsonPath('data.parent', null);
    }

    public function test_store_copies_the_desktops_latest_transaction_onto_the_new_peripheral(): void
    {
        $officer = Personnel::factory()->create();
        $desktop = $this->desktop();
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
            'par_ics_rrsp_rs_wmr_no' => 'PAR-1',
        ])->assertCreated();

        $id = $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())->assertCreated()->json('data.id');

        $copy = EquipmentTransaction::where('equipment_id', $id)->sole();
        $this->assertSame('Beginning Inventory', $copy->transaction_type);
        $this->assertSame($officer->id, $copy->accountable_officer_id);
        $this->assertSame('PAR-1', $copy->par_ics_rrsp_rs_wmr_no);
        $this->assertSame($officer->id, Equipment::findOrFail($id)->current_accountable_officer_id);
    }

    public function test_sequence_continues_past_unlinked_and_relinked_peripherals_and_taken_numbers(): void
    {
        $desktop = $this->desktop();
        $this->equipment('Monitor', ['property_no' => self::PN.'-001']);

        $this->assertSame(self::PN.'-002', $this->addPeripheral($desktop));
        $this->assertSame(self::PN.'-003', $this->addPeripheral($desktop));

        $third = Equipment::where('property_no', self::PN.'-003')->sole();
        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$third->id}")->assertNoContent();

        $this->assertSame(self::PN.'-004', $this->addPeripheral($desktop));

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $third->id])->assertOk();
        $this->assertSame(self::PN.'-005', $this->addPeripheral($desktop));
        $this->assertSame(self::PN.'-003', $third->fresh()?->property_no);
    }

    public function test_store_validates_the_desktop_and_the_peripheral_type(): void
    {
        $this->actingAs($this->editor)->postJson("/api/equipment/{$this->desktop()->id}/peripherals", $this->payload(['item_type_id' => $this->itemType('Laptop')]))
            ->assertJsonValidationErrors('item_type_id');

        $noPropertyNo = $this->equipment('CPU', ['property_no' => null]);
        $this->actingAs($this->editor)->postJson("/api/equipment/{$noPropertyNo->id}/peripherals", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment');

        $laptop = $this->equipment('Laptop');
        $this->actingAs($this->editor)->postJson("/api/equipment/{$laptop->id}/peripherals", $this->payload())->assertJsonValidationErrors('equipment');

        $this->actingAs($this->editor)->postJson("/api/equipment/{$this->equipment('CPU')->id}/peripherals", $this->payload())->assertCreated();

        $this->assertSame(1, Equipment::whereNotNull('parent_equipment_id')->count());
    }

    public function test_a_peripheral_cannot_itself_take_peripherals(): void
    {
        $desktop = $this->desktop();
        $monitor = $this->equipment('Monitor');
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $monitor->id])->assertOk();

        $this->actingAs($this->editor)->postJson("/api/equipment/{$monitor->id}/peripherals", $this->payload())->assertJsonValidationErrors('equipment');

        $otherDesktop = $this->desktop(['property_no' => 'PN-2']);
        $this->actingAs($this->editor)->postJson("/api/equipment/{$otherDesktop->id}/peripherals/link", ['equipment_id' => $desktop->id])
            ->assertJsonValidationErrors('equipment_id');
    }

    public function test_link_attaches_an_existing_peripheral_without_renumbering_it(): void
    {
        $desktop = $this->desktop();
        $mouse = $this->equipment('Mouse', ['property_no' => 'MS-77']);

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $mouse->id])
            ->assertOk()
            ->assertJsonPath('message', 'Peripheral linked.')
            ->assertJsonPath('data.property_no', 'MS-77')
            ->assertJsonPath('data.parent.id', $desktop->id);

        $this->assertSame($desktop->id, $mouse->fresh()?->parent_equipment_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.linked', 'subject_id' => $desktop->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.linked', 'subject_id' => $mouse->id]);

        $this->actingAs($this->editor)->postJson("/api/equipment/{$this->desktop(['property_no' => 'PN-2'])->id}/peripherals/link", ['equipment_id' => $mouse->id])
            ->assertJsonValidationErrors(['equipment_id' => 'already linked to desktop '.self::PN]);
    }

    public function test_link_rejects_wrong_types_missing_deleted_and_out_of_reach_equipment(): void
    {
        $desktop = $this->desktop();
        $link = fn (mixed $id, ?User $user = null) => $this->actingAs($user ?? $this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $id]);

        $link($this->equipment('Laptop')->id)->assertJsonValidationErrors('equipment_id');
        $link(999999)->assertJsonValidationErrors('equipment_id');
        $link(null)->assertJsonValidationErrors('equipment_id');

        $trashed = $this->equipment('Keyboard');
        $trashed->delete();
        $link($trashed->id)->assertJsonValidationErrors('equipment_id');

        $office = Office::factory()->create();
        $scoped = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate], ['office_id' => $office->id], DataScope::School);
        $ownDesktop = $this->desktop(['property_no' => 'PN-2', 'current_accountable_officer_id' => Personnel::factory()->create(['office_id' => $office->id])->id]);
        $elsewhere = $this->equipment('Keyboard', ['current_accountable_officer_id' => Personnel::factory()->create()->id]);

        $this->actingAs($scoped)->postJson("/api/equipment/{$ownDesktop->id}/peripherals/link", ['equipment_id' => $elsewhere->id])
            ->assertJsonValidationErrors(['equipment_id' => 'The selected equipment is invalid.']);

        $this->assertSame(0, Equipment::whereNotNull('parent_equipment_id')->count());
    }

    public function test_link_moves_the_peripheral_to_the_desktops_accountability(): void
    {
        $officer = Personnel::factory()->create();
        $previous = Personnel::factory()->create();
        $desktop = $this->desktop();
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $officer->id,
        ])->assertCreated();
        $monitor = $this->equipment('Monitor', ['current_accountable_officer_id' => $previous->id]);

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $monitor->id])->assertOk();

        $monitor->refresh();
        $this->assertSame($officer->id, $monitor->current_accountable_officer_id);
        $this->assertSame('Issuance/Transfer', $monitor->transactions()->sole()->transaction_type);
    }

    public function test_unlink_detaches_without_deleting_and_only_from_its_own_desktop(): void
    {
        $desktop = $this->desktop();
        $other = $this->desktop(['property_no' => 'PN-2']);
        $this->addPeripheral($desktop);
        $peripheral = Equipment::where('property_no', self::PN.'-001')->sole();

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$other->id}/peripherals/{$peripheral->id}")->assertNotFound();

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$peripheral->id}")->assertNoContent();

        $peripheral->refresh();
        $this->assertNull($peripheral->parent_equipment_id);
        $this->assertNotSoftDeleted($peripheral);
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.unlinked', 'subject_id' => $desktop->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.unlinked', 'subject_id' => $peripheral->id]);
    }

    public function test_peripheral_endpoints_need_equipment_edit_and_store_also_needs_create(): void
    {
        $desktop = $this->desktop();
        $monitor = $this->equipment('Monitor');
        $viewer = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentTransactionsCreate]);
        $editorOnly = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate]);

        $this->actingAs($viewer)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $monitor->id])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$monitor->id}")->assertForbidden();

        $this->actingAs($editorOnly)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())->assertForbidden();
        $this->actingAs($editorOnly)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $monitor->id])->assertOk();
        $this->actingAs($editorOnly)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$monitor->id}")->assertNoContent();

        $this->assertSame(0, Equipment::where('property_no', 'like', self::PN.'-%')->count());
    }

    public function test_out_of_reach_desktops_are_not_found(): void
    {
        $office = Office::factory()->create();
        $scoped = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate], ['office_id' => $office->id], DataScope::School);
        $desktop = $this->desktop(['current_accountable_officer_id' => Personnel::factory()->create()->id]);

        $this->actingAs($scoped)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())->assertNotFound();
        $this->actingAs($scoped)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $this->equipment('Monitor')->id])->assertNotFound();
    }

    public function test_transactions_on_a_desktop_cascade_to_its_peripherals(): void
    {
        $officer = Personnel::factory()->create();
        $endUser = Personnel::factory()->create();
        $desktop = $this->desktop();
        $this->addPeripheral($desktop);
        $this->addPeripheral($desktop);
        $standalone = $this->equipment('Keyboard');

        $parentTransactionId = $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $officer->id,
            'end_user_id' => $endUser->id,
        ])->assertCreated()->json('data.id');

        foreach ($desktop->children()->get() as $peripheral) {
            $this->assertSame($officer->id, $peripheral->current_accountable_officer_id);
            $this->assertSame($endUser->id, $peripheral->current_end_user_id);

            $copy = $peripheral->transactions()->sole();
            $this->assertSame('Issuance/Transfer', $copy->transaction_type);
            $this->assertSame($this->editor->id, $copy->recorded_by_user_id);

            $activity = Activity::query()->where('event', 'equipment.transaction.recorded')->where('subject_id', $peripheral->id)->sole();
            $this->assertSame($parentTransactionId, $activity->properties['copied_from_transaction_id']);
            $this->assertSame($desktop->id, $activity->properties['parent_equipment_id']);
        }

        $this->assertNull($standalone->fresh()?->current_accountable_officer_id);
        $this->assertSame(3, EquipmentTransaction::count());
    }

    public function test_a_linked_peripheral_cannot_record_its_own_transaction(): void
    {
        $desktop = $this->desktop();
        $this->addPeripheral($desktop);
        $peripheral = Equipment::where('property_no', self::PN.'-001')->sole();

        $this->actingAs($this->editor)->postJson("/api/equipment/{$peripheral->id}/transactions", ['transaction_type' => 'Return'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment' => 'This peripheral moves with its desktop '.self::PN.'.']);

        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_a_desktop_with_peripherals_cannot_be_deleted_until_they_are_unlinked(): void
    {
        $desktop = $this->desktop();
        $this->addPeripheral($desktop);
        $peripheral = Equipment::where('property_no', self::PN.'-001')->sole();

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unlink or remove its peripherals first.');
        $this->assertNotSoftDeleted($desktop);

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$peripheral->id}")->assertNoContent();
        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}")->assertNoContent();
    }

    public function test_a_soft_deleted_peripheral_keeps_its_number_and_no_longer_blocks_or_follows_the_desktop(): void
    {
        $officer = Personnel::factory()->create();
        $desktop = $this->desktop();
        $this->addPeripheral($desktop);
        $this->addPeripheral($desktop);
        $second = Equipment::where('property_no', self::PN.'-002')->sole();

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$second->id}")->assertNoContent();
        $this->assertSoftDeleted($second);

        $this->assertSame(self::PN.'-003', $this->addPeripheral($desktop));

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $officer->id,
        ])->assertCreated();

        $this->assertSame(0, EquipmentTransaction::where('equipment_id', $second->id)->count());
        $this->assertNull(Equipment::withTrashed()->findOrFail($second->id)->current_accountable_officer_id);
        $this->assertSame(2, EquipmentTransaction::whereIn('equipment_id', $desktop->children()->pluck('id'))->count());

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}/peripherals/{$second->id}")->assertNotFound();

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.children')
            ->assertJsonPath('data.children.0.property_no', self::PN.'-001')
            ->assertJsonPath('data.children.1.property_no', self::PN.'-003');

        foreach ($desktop->children()->get() as $peripheral) {
            $this->actingAs($this->editor)->deleteJson("/api/equipment/{$peripheral->id}")->assertNoContent();
        }

        $this->actingAs($this->editor)->deleteJson("/api/equipment/{$desktop->id}")->assertNoContent();
    }

    public function test_a_school_scoped_editor_bundles_within_their_office_only(): void
    {
        $office = Office::factory()->create();
        $colleague = Personnel::factory()->create(['office_id' => $office->id]);
        $outsider = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);
        $editor = $this->userWithPermissions(
            [Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate],
            ['office_id' => $office->id],
            DataScope::School,
        );

        $desktop = $this->desktop(['current_accountable_officer_id' => $colleague->id]);
        $outsideMonitor = $this->equipment('Monitor', ['current_accountable_officer_id' => $outsider->id]);

        $created = $this->actingAs($editor)->postJson("/api/equipment/{$desktop->id}/peripherals", $this->payload())
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($editor)->getJson("/api/equipment/{$created}")->assertOk();

        $this->actingAs($editor)->postJson("/api/equipment/{$desktop->id}/peripherals/link", ['equipment_id' => $outsideMonitor->id])
            ->assertJsonValidationErrors('equipment_id');
        $this->assertNull($outsideMonitor->fresh()?->parent_equipment_id);
    }

    public function test_bundle_members_keep_item_types_that_fit_the_bundle(): void
    {
        $desktop = $this->desktop();
        $this->addPeripheral($desktop);
        $peripheral = Equipment::where('property_no', self::PN.'-001')->sole();

        $update = fn (Equipment $equipment, string $itemType) => $this->actingAs($this->editor)->putJson("/api/equipment/{$equipment->id}", [
            ...$equipment->only(['property_no', 'brand_id', 'equipment_classification_id', 'equipment_condition_id']),
            'item_type_id' => $this->itemType($itemType),
            'uom' => 'Piece',
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'disposition_status' => 'Normal',
        ]);

        $update($desktop, 'Laptop')->assertJsonValidationErrors('item_type_id');
        $update($peripheral, 'Laptop')->assertJsonValidationErrors('item_type_id');
        $update($desktop, 'CPU')->assertOk()->assertJsonCount(1, 'data.children');
        $update($peripheral, 'Keyboard')->assertOk()->assertJsonPath('data.parent.id', $desktop->id);
    }
}
