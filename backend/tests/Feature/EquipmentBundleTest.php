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
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentBundleTest extends TestCase
{
    use BuildsEquipmentPayloads, CreatesUsersWithRoles, RefreshDatabase;

    private const PN = 'AC-2016-IT-DST-000001';

    private const NEW_PN = 'AC-2016-IT-DST-000099';

    private const CPU_PN = 'AC-2016-IT-CPU-000001';

    private Personnel $holder;

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

        $this->holder = Personnel::factory()->create();
    }

    private function itemType(string $name): int
    {
        return ItemType::where('name', $name)->valueOrFail('id');
    }

    private function dell(): int
    {
        return Brand::where('name', 'Dell')->valueOrFail('id');
    }

    private function serviceable(): int
    {
        return EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id');
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
            'old_property_no' => null,
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
    private function desktopPayload(array $overrides = []): array
    {
        return $this->newEquipmentPayload([
            'item_type_id' => $this->itemType('Desktop (package)'),
            'uom' => 'Set (bundled)',
            'acquisition_cost' => 60000,
            'received_date' => '2016-05-02',
            ...$overrides,
        ], $this->holder);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $itemType = 'Monitor', array $overrides = []): array
    {
        return [
            'item_type_id' => $this->itemType($itemType),
            'serial_number' => "{$itemType}-SN",
            'brand_id' => null,
            'equipment_condition_id' => null,
            'acquisition_cost' => 1500,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Equipment $equipment, array $overrides = []): array
    {
        return $this->equipmentFields([
            ...$equipment->only(['item_type_id', 'brand_id', 'equipment_classification_id', 'equipment_condition_id']),
            ...$overrides,
        ]);
    }

    public function test_store_creates_a_desktop_with_numbered_peripherals_inheriting_blank_brand_condition_and_accountability(): void
    {
        $otherBrand = Brand::query()->where('is_active', true)->where('name', '!=', 'Dell')->valueOrFail('id');
        $otherCondition = EquipmentCondition::query()->where('is_active', true)->where('name', '!=', 'Serviceable')->valueOrFail('id');

        $response = $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'peripherals' => [
                $this->row('Monitor', ['brand_id' => $otherBrand, 'equipment_condition_id' => $otherCondition]),
                $this->row('Keyboard'),
                ['item_type_id' => $this->itemType('Mouse')],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.property_no', self::PN)
            ->assertJsonCount(3, 'data.children')
            ->assertJsonPath('data.children.0.property_no', self::PN.'-001')
            ->assertJsonPath('data.children.1.property_no', self::PN.'-002')
            ->assertJsonPath('data.children.2.property_no', self::PN.'-003')
            ->assertJsonPath('data.children.2.item', 'Mouse');

        $desktop = Equipment::findOrFail($response->json('data.id'));
        [$monitor, $keyboard, $mouse] = $desktop->children()->orderBy('property_no')->get()->all();

        $this->assertSame($otherBrand, $monitor->brand_id);
        $this->assertSame($otherCondition, $monitor->equipment_condition_id);
        foreach ([$keyboard, $mouse] as $peripheral) {
            $this->assertSame($this->dell(), $peripheral->brand_id);
            $this->assertSame($this->serviceable(), $peripheral->equipment_condition_id);
        }

        foreach ([$monitor, $keyboard, $mouse] as $peripheral) {
            $this->assertSame('ICT Unit', $peripheral->equipment_location);
            $this->assertSame($this->editor->id, $peripheral->created_by_user_id);
            $this->assertSame($this->holder->id, $peripheral->current_accountable_officer_id);
            $this->assertSame($this->holder->id, $peripheral->current_end_user_id);
            $this->assertSame('Teacher', $peripheral->used_by);
            $this->assertSame('Beginning Inventory', $peripheral->transactions()->sole()->transaction_type);
            $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);
            $this->assertDatabaseHas('activity_log', ['event' => 'created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);
        }

        $this->assertSame(3, Activity::query()->where('event', 'equipment.peripheral.created')->where('subject_id', $desktop->id)->count());
        $this->assertSame(4, EquipmentTransaction::count());
        $this->assertSame($desktop->transactions()->sole()->id, Activity::query()
            ->where('event', 'equipment.transaction.recorded')->where('subject_id', $monitor->id)->sole()->properties['copied_from_transaction_id']);

        $issued = Personnel::factory()->create();
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $issued->id,
        ])->assertCreated();
        $this->assertSame(3, Equipment::where('parent_equipment_id', $desktop->id)->where('current_accountable_officer_id', $issued->id)->count());
    }

    public function test_store_rejects_peripherals_on_a_non_bundle_type(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'item_type_id' => $this->itemType('Laptop'),
            'peripherals' => [$this->row()],
        ]))->assertUnprocessable()->assertJsonValidationErrors('peripherals');

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'peripherals' => array_fill(0, 11, $this->row()),
        ]))->assertJsonValidationErrors('peripherals');

        $this->assertSame(0, Equipment::count());

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'item_type_id' => $this->itemType('Laptop'),
            'peripherals' => [],
        ]))->assertCreated()->assertJsonCount(0, 'data.children');
    }

    public function test_store_with_one_invalid_row_creates_nothing(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'peripherals' => [
                $this->row('Monitor'),
                $this->row('Laptop', ['brand_id' => 999999]),
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['peripherals.1.item_type_id' => 'A peripheral must be a Monitor, Keyboard or Mouse.', 'peripherals.1.brand_id'])
            ->assertJsonMissingValidationErrors(['peripherals.0.item_type_id', 'peripherals']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_store_rolls_back_the_desktop_when_a_peripheral_fails_to_save(): void
    {
        Equipment::creating(function (Equipment $equipment): void {
            if ($equipment->property_no === self::PN.'-002') {
                throw new RuntimeException('Simulated failure');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
                'peripherals' => [$this->row('Monitor'), $this->row('Keyboard')],
            ]));
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }

        $this->assertSame(0, Equipment::withTrashed()->count());
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.created')->count());
    }

    public function test_batch_adds_several_peripherals_to_an_existing_desktop_atomically(): void
    {
        $officer = Personnel::factory()->create();
        $desktop = $this->desktop(['brand_id' => $this->dell(), 'equipment_condition_id' => $this->serviceable()]);
        $this->equipment('Monitor', ['property_no' => self::PN.'-001']);
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
        ])->assertCreated();

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => [$this->row('Monitor'), $this->row('Laptop')],
        ])->assertJsonValidationErrors('peripherals.1.item_type_id');
        $this->assertSame(0, $desktop->children()->count());

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => [$this->row('Monitor'), $this->row('Keyboard')],
        ])
            ->assertCreated()
            ->assertJsonPath('message', '2 peripherals added.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.property_no', self::PN.'-002')
            ->assertJsonPath('data.1.property_no', self::PN.'-003')
            ->assertJsonPath('data.1.brand_manufacturer', 'Dell')
            ->assertJsonPath('data.1.parent', ['id' => $desktop->id, 'property_no' => self::PN]);

        foreach ($desktop->children()->get() as $peripheral) {
            $this->assertSame($officer->id, $peripheral->current_accountable_officer_id);
            $this->assertSame('Beginning Inventory', $peripheral->transactions()->sole()->transaction_type);
        }
        $this->assertSame(2, Activity::query()->where('event', 'equipment.peripheral.created')->where('subject_id', $desktop->id)->count());

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", ['peripherals' => []])
            ->assertJsonValidationErrors('peripherals');
        $this->actingAs($this->editor)->postJson("/api/equipment/{$this->equipment('Laptop')->id}/peripherals/batch", ['peripherals' => [$this->row()]])
            ->assertJsonValidationErrors('equipment');
    }

    public function test_batch_add_needs_the_same_permissions_as_a_single_add(): void
    {
        $desktop = $this->desktop();
        $editorOnly = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate]);
        $noTransactions = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit]);

        $this->actingAs($editorOnly)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", ['peripherals' => [$this->row()]])->assertForbidden();
        $this->actingAs($noTransactions)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", ['peripherals' => [$this->row()]])->assertForbidden();

        $this->assertSame(0, $desktop->children()->count());
    }

    /**
     * @return array{User, Personnel, Personnel}
     */
    private function schoolEditor(): array
    {
        $office = Office::factory()->create();
        $editor = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit], ['office_id' => $office->id], DataScope::School);

        return [
            $editor,
            Personnel::factory()->create(['office_id' => $office->id]),
            Personnel::factory()->create(['office_id' => Office::factory()->create()->id]),
        ];
    }

    public function test_a_number_taken_mid_request_is_a_validation_error(): void
    {
        $desktop = $this->desktop();
        $raced = false;
        Equipment::creating(function (Equipment $equipment) use (&$raced): void {
            if (! $raced && $equipment->property_no === self::PN.'-001') {
                $raced = true;
                $this->equipment('Mouse', ['property_no' => self::PN.'-001']);
            }
        });

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => [$this->row('Monitor'), $this->row('Keyboard')],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['peripherals' => 'was just taken by another record']);

        $this->assertSame(0, $desktop->children()->count());
    }

    public function test_original_bundle_is_shown_on_detail_only_when_visible(): void
    {
        $office = Office::factory()->create();
        $colleague = Personnel::factory()->create(['office_id' => $office->id]);
        $scoped = $this->userWithPermissions([Permission::EquipmentView], ['office_id' => $office->id], DataScope::School);

        $original = $this->desktop(['current_accountable_officer_id' => Personnel::factory()->create()->id]);
        $current = $this->desktop(['property_no' => 'PN-CURRENT', 'current_accountable_officer_id' => $colleague->id]);
        $moved = $this->equipment('Monitor', [
            'property_no' => self::PN.'-001',
            'parent_equipment_id' => $current->id,
            'current_accountable_officer_id' => $colleague->id,
        ]);
        $ownNumber = $this->equipment('Mouse', ['property_no' => 'MS-77', 'current_accountable_officer_id' => $colleague->id]);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$moved->id}")
            ->assertOk()
            ->assertJsonPath('data.original_bundle', ['id' => $original->id, 'property_no' => self::PN])
            ->assertJsonPath('data.parent', ['id' => $current->id, 'property_no' => 'PN-CURRENT']);

        $this->actingAs($scoped)->getJson("/api/equipment/{$moved->id}")
            ->assertOk()
            ->assertJsonPath('data.original_bundle', null)
            ->assertJsonPath('data.parent.id', $current->id);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$ownNumber->id}")->assertJsonPath('data.original_bundle', null);

        $original->delete();
        $this->actingAs($this->editor)->getJson("/api/equipment/{$moved->id}")->assertJsonPath('data.original_bundle', null);

        $this->actingAs($this->editor)->getJson('/api/equipment')
            ->assertOk()
            ->assertJsonMissingPath('data.0.original_bundle')
            ->assertJsonMissingPath('data.0.renumberable_peripherals_count');
    }

    public function test_peripheral_rows_cannot_set_numbering_linkage_accountability_or_creator(): void
    {
        $officer = Personnel::factory()->create();
        $intruder = Personnel::factory()->create();
        $otherDesktop = $this->desktop(['property_no' => 'PN-OTHER']);
        $otherUser = User::factory()->create();
        $injected = [
            'property_no' => 'HIJACK-1',
            'old_property_no' => 'HIJACK-OLD',
            'parent_equipment_id' => $otherDesktop->id,
            'current_accountable_officer_id' => $intruder->id,
            'current_end_user_id' => $intruder->id,
            'created_by_user_id' => $otherUser->id,
            'equipment_location' => 'Injected',
            'qr_code' => 'INJECTED-QR',
        ];

        $id = $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'peripherals' => [$this->row('Monitor', $injected)],
        ]))->assertCreated()->json('data.id');

        $created = Equipment::where('parent_equipment_id', $id)->sole();
        $this->assertSame(self::PN.'-001', $created->property_no);
        $this->assertNull($created->old_property_no);
        $this->assertSame($this->holder->id, $created->current_accountable_officer_id);
        $this->assertSame($this->holder->id, $created->current_end_user_id);
        $this->assertSame($this->editor->id, $created->created_by_user_id);
        $this->assertSame('ICT Unit', $created->equipment_location);
        $this->assertNotSame('INJECTED-QR', $created->qr_code);

        $desktop = Equipment::findOrFail($id);
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $officer->id,
        ])->assertCreated();

        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => [$this->row('Keyboard', $injected)],
        ])->assertCreated();

        $batched = Equipment::where('parent_equipment_id', $desktop->id)->where('property_no', self::PN.'-002')->sole();
        $this->assertSame($officer->id, $batched->current_accountable_officer_id);
        $this->assertNull($batched->current_end_user_id);
        $this->assertSame($this->editor->id, $batched->created_by_user_id);
        $this->assertNull($batched->old_property_no);
        $this->assertSame(0, $otherDesktop->children()->count());
        $this->assertFalse(Equipment::withTrashed()->where('property_no', 'HIJACK-1')->exists());
    }

    public function test_store_numbers_a_cpu_bundle_past_numbers_already_taken(): void
    {
        $this->equipment('Mouse', ['property_no' => self::CPU_PN.'-001']);
        $this->equipment('Mouse', ['property_no' => self::CPU_PN.'-003'])->delete();

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'item_type_id' => $this->itemType('CPU'),
            'peripherals' => [$this->row('Monitor'), $this->row('Keyboard')],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.item', 'CPU')
            ->assertJsonPath('data.property_no', self::CPU_PN)
            ->assertJsonPath('data.children.0.property_no', self::CPU_PN.'-002')
            ->assertJsonPath('data.children.1.property_no', self::CPU_PN.'-004');
    }

    public function test_peripheral_row_shape_and_count_limits(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload(['peripherals' => []]))
            ->assertCreated()
            ->assertJsonCount(0, 'data.children');
        Equipment::query()->forceDelete();

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload(['peripherals' => 'monitor']))
            ->assertJsonValidationErrors('peripherals');
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload(['peripherals' => ['monitor']]))
            ->assertJsonValidationErrors('peripherals.0');
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload(['peripherals' => [['serial_number' => 'X']]]))
            ->assertJsonValidationErrors('peripherals.0.item_type_id');
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'peripherals' => array_fill(0, 10, $this->row()),
        ]))->assertCreated()->assertJsonCount(10, 'data.children')->assertJsonPath('data.children.9.property_no', self::PN.'-010');

        $desktop = $this->desktop(['property_no' => 'PN-BATCH']);
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => array_fill(0, 11, $this->row()),
        ])->assertJsonValidationErrors('peripherals');
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", [
            'peripherals' => [$this->row('Monitor', ['acquisition_cost' => -1])],
        ])->assertJsonValidationErrors('peripherals.0.acquisition_cost');
        $this->assertSame(0, $desktop->children()->count());
    }

    public function test_a_scoped_editor_cannot_batch_add_or_edit_outside_their_scope(): void
    {
        $office = Office::factory()->create();
        $scoped = $this->userWithPermissions([
            Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate,
        ], ['office_id' => $office->id], DataScope::School);
        $outsider = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);
        $desktop = $this->desktop(['current_accountable_officer_id' => $outsider->id]);
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id, 'current_accountable_officer_id' => $outsider->id]);

        $this->actingAs($scoped)->postJson("/api/equipment/{$desktop->id}/peripherals/batch", ['peripherals' => [$this->row()]])
            ->assertNotFound();
        $this->actingAs($scoped)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop))->assertNotFound();

        $this->assertSame(1, Equipment::where('parent_equipment_id', $desktop->id)->count());
        $this->assertSame(self::PN, $desktop->fresh()?->property_no);
        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
    }

    public function test_original_bundle_links_only_peripherals_to_bundle_parents(): void
    {
        $desktop = $this->desktop();
        $monitor = $this->equipment('Monitor', ['property_no' => self::PN.'-001']);
        $laptopRow = $this->equipment('Laptop', ['property_no' => self::PN.'-002']);
        $this->equipment('Laptop', ['property_no' => 'LT-9']);
        $underLaptop = $this->equipment('Monitor', ['property_no' => 'LT-9-001']);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$monitor->id}")
            ->assertJsonPath('data.original_bundle', ['id' => $desktop->id, 'property_no' => self::PN])
            ->assertJsonMissingPath('data.renumberable_peripherals_count');
        $this->actingAs($this->editor)->getJson("/api/equipment/{$laptopRow->id}")->assertJsonPath('data.original_bundle', null);
        $this->actingAs($this->editor)->getJson("/api/equipment/{$underLaptop->id}")->assertJsonPath('data.original_bundle', null);
    }

    public function test_editing_a_desktop_never_renumbers_its_peripherals(): void
    {
        $desktop = $this->desktop();
        $monitor = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertJsonValidationErrors('property_no');

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'remarks' => 'Checked.',
            'renumber_peripherals' => true,
        ]))->assertOk()
            ->assertJsonPath('data.property_no', self::PN)
            ->assertJsonMissingPath('data.renumberable_peripherals_count');

        $this->assertSame(self::PN.'-001', $monitor->fresh()?->property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->count());
    }
}
