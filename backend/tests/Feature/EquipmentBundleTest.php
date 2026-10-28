<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentClassification;
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
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentBundleTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const PN = 'AC-2016-IT-DST-000001';

    private const NEW_PN = 'AC-2016-IT-DST-000099';

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
        return [
            'property_no' => self::PN,
            'item_type_id' => $this->itemType('Desktop (package)'),
            'brand_id' => $this->dell(),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 60000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => $this->serviceable(),
            'disposition_status' => 'Normal',
            'equipment_location' => 'ICT Unit',
            ...$overrides,
        ];
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
        return [
            ...$equipment->only(['property_no', 'item_type_id', 'brand_id', 'equipment_classification_id', 'equipment_condition_id']),
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'disposition_status' => 'Normal',
            ...$overrides,
        ];
    }

    public function test_store_creates_a_desktop_with_numbered_peripherals_inheriting_blank_brand_and_condition(): void
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
            $this->assertNull($peripheral->current_accountable_officer_id);
            $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);
            $this->assertDatabaseHas('activity_log', ['event' => 'created', 'subject_id' => $peripheral->id, 'subject_type' => Equipment::class]);
        }

        $this->assertSame(3, Activity::query()->where('event', 'equipment.peripheral.created')->where('subject_id', $desktop->id)->count());
        $this->assertSame(0, EquipmentTransaction::count());

        $issued = Personnel::factory()->create();
        $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
            'accountable_officer_id' => $issued->id,
        ])->assertCreated();
        $this->assertSame(3, Equipment::where('parent_equipment_id', $desktop->id)->where('current_accountable_officer_id', $issued->id)->count());
    }

    public function test_store_rejects_peripherals_on_a_non_bundle_type_or_without_a_property_number(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'item_type_id' => $this->itemType('Laptop'),
            'peripherals' => [$this->row()],
        ]))->assertUnprocessable()->assertJsonValidationErrors('peripherals');

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'property_no' => null,
            'peripherals' => [$this->row()],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['property_no' => 'the peripherals are numbered after it']);

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

    public function test_an_unscoped_editor_renumbers_linked_moved_and_deleted_rows_only(): void
    {
        $desktop = $this->desktop();
        $otherDesktop = $this->desktop(['property_no' => 'PN-OTHER']);
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);
        $keepsOldNo = $this->equipment('Keyboard', ['property_no' => self::PN.'-002', 'old_property_no' => 'LEGACY-7', 'parent_equipment_id' => $desktop->id]);
        $moved = $this->equipment('Mouse', ['property_no' => self::PN.'-003', 'parent_equipment_id' => $otherDesktop->id]);
        $trashed = $this->equipment('Mouse', ['property_no' => self::PN.'-004']);
        $trashed->delete();
        $untouched = [
            $this->equipment('Mouse', ['property_no' => self::PN.'-A1', 'parent_equipment_id' => $desktop->id]),
            $this->equipment('Mouse', ['property_no' => self::PN.'-001-2']),
            $this->equipment('Mouse', ['property_no' => self::PN.'0-001']),
            $this->equipment('Mouse', ['property_no' => self::PN.'-']),
        ];

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")
            ->assertJsonPath('data.renumberable_peripherals_count', 4);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('data.property_no', self::NEW_PN)
            ->assertJsonPath('data.renumberable_peripherals_count', 4)
            ->assertJsonPath('data.children.1.property_no', self::NEW_PN.'-001');

        $this->assertSame(self::NEW_PN.'-001', $linked->fresh()?->property_no);
        $this->assertSame(self::PN.'-001', $linked->fresh()?->old_property_no);
        $this->assertSame(self::NEW_PN.'-002', $keepsOldNo->fresh()?->property_no);
        $this->assertSame('LEGACY-7', $keepsOldNo->fresh()?->old_property_no);
        $this->assertSame(self::NEW_PN.'-003', $moved->fresh()?->property_no);
        $this->assertSame($otherDesktop->id, $moved->fresh()?->parent_equipment_id);
        $this->assertSame(self::NEW_PN.'-004', Equipment::withTrashed()->findOrFail($trashed->id)->property_no);

        foreach ($untouched as $row) {
            $this->assertSame($row->property_no, $row->fresh()?->property_no);
            $this->assertNull($row->fresh()?->old_property_no);
        }

        $activity = Activity::query()->where('event', 'equipment.peripheral.renumbered')->where('subject_id', $moved->id)->sole();
        $this->assertSame(self::PN.'-003', $activity->properties['old']);
        $this->assertSame(self::NEW_PN.'-003', $activity->properties['new']);
        $this->assertSame(5, Activity::query()->where('event', 'equipment.peripheral.renumbered')->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.peripheral.renumbered', 'subject_id' => $desktop->id]);
    }

    public function test_renumber_fails_without_changing_anything_when_a_target_number_is_taken(): void
    {
        $desktop = $this->desktop();
        $first = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);
        $this->equipment('Keyboard', ['property_no' => self::PN.'-002', 'parent_equipment_id' => $desktop->id]);
        $this->equipment('Mouse', ['property_no' => self::NEW_PN.'-002'])->delete();

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'remarks' => 'Corrected',
            'renumber_peripherals' => true,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['renumber_peripherals' => 'already in use: '.self::NEW_PN.'-002.']);

        $this->assertSame(self::PN, $desktop->fresh()?->property_no);
        $this->assertSame($desktop->remarks, $desktop->fresh()?->remarks);
        $this->assertSame(self::PN.'-001', $first->fresh()?->property_no);
        $this->assertNull($first->fresh()?->old_property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->count());
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

    public function test_a_scoped_editor_renumbers_and_counts_only_rows_within_their_scope(): void
    {
        [$scoped, $colleague, $outsider] = $this->schoolEditor();
        $desktop = $this->desktop(['current_accountable_officer_id' => $colleague->id]);
        $outsideDesktop = $this->desktop(['property_no' => 'PN-OUT', 'current_accountable_officer_id' => $outsider->id]);
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id, 'current_accountable_officer_id' => $colleague->id]);
        $movedOut = $this->equipment('Mouse', ['property_no' => self::PN.'-002', 'parent_equipment_id' => $outsideDesktop->id, 'current_accountable_officer_id' => $outsider->id]);
        $trashed = $this->equipment('Keyboard', ['property_no' => self::PN.'-003', 'current_accountable_officer_id' => $colleague->id]);
        $trashed->delete();

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")->assertJsonPath('data.renumberable_peripherals_count', 3);
        $this->actingAs($scoped)->getJson("/api/equipment/{$desktop->id}")->assertJsonPath('data.renumberable_peripherals_count', 2);

        $this->actingAs($scoped)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertOk();

        $this->assertSame(self::NEW_PN.'-001', $linked->fresh()?->property_no);
        $this->assertSame(self::NEW_PN.'-003', Equipment::withTrashed()->findOrFail($trashed->id)->property_no);
        $this->assertSame(self::PN.'-002', $movedOut->fresh()?->property_no);
        $this->assertNull($movedOut->fresh()?->old_property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->where('subject_id', $movedOut->id)->count());
        $this->assertSame(0, Activity::query()->where('subject_id', $movedOut->id)->where('event', 'updated')->count());

        $summary = Activity::query()->where('event', 'equipment.peripheral.renumbered')->where('subject_id', $desktop->id)->sole();
        $this->assertEqualsCanonicalizing([$linked->id, $trashed->id], $summary->properties['renumbered_equipment_ids']);
    }

    public function test_the_collision_message_names_only_numbers_within_the_editors_scope(): void
    {
        [$scoped, $colleague, $outsider] = $this->schoolEditor();
        $desktop = $this->desktop(['current_accountable_officer_id' => $colleague->id]);
        $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id, 'current_accountable_officer_id' => $colleague->id]);
        $this->equipment('Keyboard', ['property_no' => self::PN.'-002', 'parent_equipment_id' => $desktop->id, 'current_accountable_officer_id' => $colleague->id]);
        $this->equipment('Mouse', ['property_no' => self::NEW_PN.'-001', 'current_accountable_officer_id' => $outsider->id]);
        $visibleClash = $this->equipment('Mouse', ['property_no' => self::NEW_PN.'-002', 'current_accountable_officer_id' => $colleague->id]);

        $renumber = fn () => $this->actingAs($scoped)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertUnprocessable();

        $message = $renumber()->json('errors.renumber_peripherals.0');
        $this->assertStringContainsString(self::NEW_PN.'-002 and 1 other', $message);
        $this->assertStringNotContainsString(self::NEW_PN.'-001', $message);

        $visibleClash->forceDelete();

        $message = $renumber()->json('errors.renumber_peripherals.0');
        $this->assertSame('Cannot renumber the peripherals: 1 of the new property numbers is already in use.', $message);

        $this->assertSame(self::PN, $desktop->fresh()?->property_no);
    }

    public function test_renumber_is_rejected_unless_the_item_type_is_a_bundle_parent(): void
    {
        $laptop = $this->equipment('Laptop', ['property_no' => self::PN]);
        $stray = $this->equipment('Mouse', ['property_no' => self::PN.'-001']);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$laptop->id}", $this->updatePayload($laptop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertJsonValidationErrors(['renumber_peripherals' => 'Only a Desktop (package) or CPU']);

        $this->assertSame(self::PN, $laptop->fresh()?->property_no);
        $this->assertSame(self::PN.'-001', $stray->fresh()?->property_no);
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

    public function test_without_the_flag_or_a_previous_number_peripherals_keep_their_numbers(): void
    {
        $desktop = $this->desktop();
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, ['property_no' => self::NEW_PN]))->assertOk();
        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, ['property_no' => 'PN-3', 'renumber_peripherals' => false]))->assertOk();

        $pending = $this->desktop(['property_no' => null]);
        $this->actingAs($this->editor)->putJson("/api/equipment/{$pending->id}", $this->updatePayload($pending, ['property_no' => 'PN-4', 'renumber_peripherals' => true]))->assertOk();

        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
        $this->assertNull($linked->fresh()?->old_property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->count());

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, ['renumber_peripherals' => 'yes']))
            ->assertJsonValidationErrors('renumber_peripherals');
    }

    public function test_renumbering_needs_permission_to_edit_the_desktop(): void
    {
        $desktop = $this->desktop();
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);
        $viewer = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate]);

        $this->actingAs($viewer)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertForbidden();

        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
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
        $this->assertNull($created->current_accountable_officer_id);
        $this->assertNull($created->current_end_user_id);
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
        $this->equipment('Mouse', ['property_no' => self::PN.'-001']);
        $this->equipment('Mouse', ['property_no' => self::PN.'-003'])->delete();

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->desktopPayload([
            'item_type_id' => $this->itemType('CPU'),
            'peripherals' => [$this->row('Monitor'), $this->row('Keyboard')],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.item', 'CPU')
            ->assertJsonPath('data.children.0.property_no', self::PN.'-002')
            ->assertJsonPath('data.children.1.property_no', self::PN.'-004');
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

    public function test_a_scoped_editor_cannot_batch_add_or_renumber_outside_their_scope(): void
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
        $this->actingAs($scoped)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertNotFound();

        $this->assertSame(1, Equipment::where('parent_equipment_id', $desktop->id)->count());
        $this->assertSame(self::PN, $desktop->fresh()?->property_no);
        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
    }

    public function test_renumber_matches_the_old_number_literally(): void
    {
        $desktop = $this->desktop(['property_no' => 'PN_1']);
        $match = $this->equipment('Monitor', ['property_no' => 'PN_1-001', 'parent_equipment_id' => $desktop->id]);
        $wildcard = $this->equipment('Keyboard', ['property_no' => 'PNX1-002']);
        $otherCase = $this->equipment('Mouse', ['property_no' => 'pn_1-003']);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")->assertJsonPath('data.renumberable_peripherals_count', 1);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => 'PN%2',
            'renumber_peripherals' => true,
        ]))->assertOk();

        $this->assertSame('PN%2-001', $match->fresh()?->property_no);
        $this->assertSame('PNX1-002', $wildcard->fresh()?->property_no);
        $this->assertSame('pn_1-003', $otherCase->fresh()?->property_no);
    }

    public function test_renumber_rejects_a_new_number_too_long_for_the_suffixes(): void
    {
        $desktop = $this->desktop();
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);
        $longNumber = str_repeat('A', 252);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => $longNumber,
            'renumber_peripherals' => true,
        ]))->assertJsonValidationErrors(['renumber_peripherals' => 'too long']);

        $this->assertSame(self::PN, $desktop->fresh()?->property_no);
        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => $longNumber,
            'renumber_peripherals' => false,
        ]))->assertOk();
        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
    }

    public function test_renumber_flag_with_an_unchanged_or_cleared_number_changes_nothing(): void
    {
        $desktop = $this->desktop();
        $linked = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'remarks' => 'Same number',
            'renumber_peripherals' => true,
        ]))->assertOk()->assertJsonPath('data.renumberable_peripherals_count', 1);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => null,
            'renumber_peripherals' => true,
        ]))->assertOk()->assertJsonPath('data.renumberable_peripherals_count', 0);

        $this->assertSame(self::PN.'-001', $linked->fresh()?->property_no);
        $this->assertNull($linked->fresh()?->old_property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->count());
    }

    public function test_numbering_links_only_peripherals_to_bundle_parents(): void
    {
        $desktop = $this->desktop();
        $monitor = $this->equipment('Monitor', ['property_no' => self::PN.'-001', 'parent_equipment_id' => $desktop->id]);
        $laptopRow = $this->equipment('Laptop', ['property_no' => self::PN.'-002']);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$desktop->id}")->assertJsonPath('data.renumberable_peripherals_count', 1);
        $this->actingAs($this->editor)->getJson("/api/equipment/{$laptopRow->id}")->assertJsonPath('data.original_bundle', null);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$desktop->id}", $this->updatePayload($desktop, [
            'property_no' => self::NEW_PN,
            'renumber_peripherals' => true,
        ]))->assertOk()->assertJsonPath('data.renumberable_peripherals_count', 1);

        $this->assertSame(self::NEW_PN.'-001', $monitor->fresh()?->property_no);
        $this->assertSame(self::PN.'-002', $laptopRow->fresh()?->property_no);
        $this->assertNull($laptopRow->fresh()?->old_property_no);
        $this->assertSame(0, Activity::query()->where('event', 'equipment.peripheral.renumbered')->where('subject_id', $laptopRow->id)->count());

        $laptop = $this->equipment('Laptop', ['property_no' => 'LT-9']);
        $underLaptop = $this->equipment('Monitor', ['property_no' => 'LT-9-001']);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$laptop->id}")->assertJsonPath('data.renumberable_peripherals_count', 0);
        $this->actingAs($this->editor)->getJson("/api/equipment/{$underLaptop->id}")->assertJsonPath('data.original_bundle', null);
        $this->actingAs($this->editor)->getJson("/api/equipment/{$monitor->id}")
            ->assertJsonPath('data.original_bundle', ['id' => $desktop->id, 'property_no' => self::NEW_PN]);
    }
}
