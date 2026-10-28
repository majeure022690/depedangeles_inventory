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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentPeripheralScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const ENCODER_PERMISSIONS = [
        Permission::EquipmentView, Permission::EquipmentCreate,
        Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate,
    ];

    private Office $schoolX;

    private Office $schoolY;

    private Personnel $officerX;

    private Personnel $officerY;

    private User $division;

    private User $schoolYEncoder;

    private Equipment $desktop;

    private Equipment $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->schoolX = Office::factory()->create();
        $this->schoolY = Office::factory()->create();
        $this->officerX = Personnel::factory()->create(['office_id' => $this->schoolX->id]);
        $this->officerY = Personnel::factory()->create(['office_id' => $this->schoolY->id]);

        $this->division = $this->userWithPermissions(self::ENCODER_PERMISSIONS);
        $this->schoolYEncoder = $this->userWithPermissions(self::ENCODER_PERMISSIONS, ['office_id' => $this->schoolY->id], DataScope::School);
        $schoolYCreator = User::factory()->create(['office_id' => $this->schoolY->id]);

        $this->desktop = $this->equipment('Desktop (package)', [
            'property_no' => 'AC-2016-IT-DST-000001',
            'created_by_user_id' => $schoolYCreator->id,
        ]);
        $this->monitor = $this->equipment('Monitor', [
            'property_no' => 'MON-X-1',
            'serial_number' => 'SECRET-SN',
            'model' => 'P2422H',
            'current_accountable_officer_id' => $this->officerX->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function equipment(string $itemType, array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'item_type_id' => ItemType::where('name', $itemType)->valueOrFail('id'),
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
            ...$attributes,
        ]);
    }

    private function linkDirectly(): void
    {
        $this->monitor->forceFill(['parent_equipment_id' => $this->desktop->id])->save();
    }

    private function issueTo(Personnel $officer, User $user): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/equipment/{$this->desktop->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $officer->id,
        ]);
    }

    public function test_link_is_refused_when_holders_differ_and_the_desktop_has_no_transaction(): void
    {
        $this->actingAs($this->division)
            ->postJson("/api/equipment/{$this->desktop->id}/peripherals/link", ['equipment_id' => $this->monitor->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment_id' => 'Record an issuance on the desktop first']);

        $this->monitor->refresh();
        $this->assertNull($this->monitor->parent_equipment_id);
        $this->assertSame($this->officerX->id, $this->monitor->current_accountable_officer_id);
        $this->assertSame(0, EquipmentTransaction::count());
        $this->assertDatabaseMissing('activity_log', ['event' => 'equipment.peripheral.linked']);
    }

    public function test_link_succeeds_without_a_desktop_transaction_when_holders_match(): void
    {
        $unissued = $this->equipment('Keyboard', ['property_no' => 'KB-1']);

        $this->actingAs($this->division)
            ->postJson("/api/equipment/{$this->desktop->id}/peripherals/link", ['equipment_id' => $unissued->id])
            ->assertOk();

        $this->assertSame($this->desktop->id, $unissued->fresh()?->parent_equipment_id);
        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_link_mirrors_the_desktop_transaction_when_holders_differ(): void
    {
        $this->issueTo($this->officerY, $this->division)->assertCreated();

        $this->actingAs($this->division)
            ->postJson("/api/equipment/{$this->desktop->id}/peripherals/link", ['equipment_id' => $this->monitor->id])
            ->assertOk();

        $this->assertSame($this->officerY->id, $this->monitor->fresh()?->current_accountable_officer_id);
    }

    public function test_a_school_encoder_cannot_move_a_linked_peripheral_outside_their_office(): void
    {
        $this->linkDirectly();

        $this->issueTo($this->officerY, $this->schoolYEncoder)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment' => 'A linked peripheral (MON-X-1) is outside your access; ask an administrator.']);

        $this->assertSame(0, EquipmentTransaction::count());
        $this->assertNull($this->desktop->fresh()?->current_accountable_officer_id);
        $this->assertSame($this->officerX->id, $this->monitor->fresh()?->current_accountable_officer_id);
        $this->assertDatabaseMissing('activity_log', ['event' => 'equipment.transaction.recorded']);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'authorization.denied',
            'subject_id' => $this->monitor->id,
            'causer_id' => $this->schoolYEncoder->id,
        ]);
    }

    public function test_a_school_encoder_cannot_unlink_a_peripheral_outside_their_office(): void
    {
        $this->linkDirectly();

        $this->actingAs($this->schoolYEncoder)
            ->deleteJson("/api/equipment/{$this->desktop->id}/peripherals/{$this->monitor->id}")
            ->assertNotFound();

        $this->assertSame($this->desktop->id, $this->monitor->fresh()?->parent_equipment_id);
        $this->assertDatabaseMissing('activity_log', ['event' => 'equipment.peripheral.unlinked']);
    }

    public function test_out_of_scope_children_are_redacted_to_id_and_property_no(): void
    {
        $this->linkDirectly();

        $this->actingAs($this->schoolYEncoder)
            ->getJson("/api/equipment/{$this->desktop->id}")
            ->assertOk()
            ->assertJsonPath('data.children.0.id', $this->monitor->id)
            ->assertJsonPath('data.children.0.property_no', 'MON-X-1')
            ->assertJsonPath('data.children.0.serial_number', null)
            ->assertJsonPath('data.children.0.model', null)
            ->assertJsonPath('data.children.0.item', null);

        $this->actingAs($this->division)
            ->getJson("/api/equipment/{$this->desktop->id}")
            ->assertOk()
            ->assertJsonPath('data.children.0.serial_number', 'SECRET-SN')
            ->assertJsonPath('data.children.0.model', 'P2422H')
            ->assertJsonPath('data.children.0.item', 'Monitor');
    }

    public function test_an_unscoped_encoder_still_moves_the_whole_bundle(): void
    {
        $this->linkDirectly();

        $this->issueTo($this->officerY, $this->division)->assertCreated();

        $this->assertSame($this->officerY->id, $this->desktop->fresh()?->current_accountable_officer_id);
        $this->assertSame($this->officerY->id, $this->monitor->fresh()?->current_accountable_officer_id);
        $this->assertSame(2, EquipmentTransaction::count());
    }

    public function test_adding_or_linking_a_peripheral_needs_the_transactions_permission(): void
    {
        $editor = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit]);
        $keyboard = $this->equipment('Keyboard', ['property_no' => 'KB-1']);

        $this->actingAs($editor)->postJson("/api/equipment/{$this->desktop->id}/peripherals", [
            'item_type_id' => ItemType::where('name', 'Monitor')->valueOrFail('id'),
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
        ])->assertForbidden();

        $this->actingAs($editor)
            ->postJson("/api/equipment/{$this->desktop->id}/peripherals/link", ['equipment_id' => $keyboard->id])
            ->assertForbidden();

        $this->assertSame(0, Equipment::whereNotNull('parent_equipment_id')->count());
    }
}
