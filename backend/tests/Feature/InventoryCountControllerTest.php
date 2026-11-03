<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class InventoryCountControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const ALL = [Permission::InventoryCountView, Permission::InventoryCountConduct, Permission::InventoryCountManage, Permission::InventoryCountDelete];

    private Office $office;

    private Office $otherOffice;

    private Personnel $officer;

    private int $serviceable;

    private int $unserviceable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create(['office_name' => 'Sample ES']);
        $this->otherOffice = Office::factory()->create(['office_name' => 'Other ES']);
        $this->officer = Personnel::factory()->create(['office_id' => $this->office->id]);
        $this->serviceable = EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id');
        $this->unserviceable = EquipmentCondition::where('name', 'Unserviceable')->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'item_type_id' => ItemType::query()->valueOrFail('id'),
            'equipment_condition_id' => $this->serviceable,
            'disposition_status' => 'Normal',
            'current_accountable_officer_id' => $this->officer->id,
            'current_end_user_id' => null,
            ...$attributes,
        ]);
    }

    private function manager(DataScope $scope = DataScope::All, ?int $officeId = null): User
    {
        return $this->userWithPermissions(self::ALL, ['office_id' => $officeId], $scope);
    }

    private function openCount(User $user, array $officeIds): InventoryCount
    {
        $id = $this->actingAs($user)->postJson('/api/inventory-counts', [
            'name' => 'Physical Count as of Dec 31, 2026',
            'as_of_date' => '2026-12-31',
            'office_ids' => $officeIds,
        ])->assertCreated()->json('data.id');

        $this->actingAs($user)->postJson("/api/inventory-counts/{$id}/open")->assertOk();

        return InventoryCount::findOrFail($id);
    }

    public function test_opening_copies_the_offices_in_custody_items_as_they_stand(): void
    {
        $kept = $this->item(['equipment_location' => 'Room 1']);
        $this->item(['disposition_status' => 'Disposed']);
        $elsewhere = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $this->item(['current_accountable_officer_id' => $elsewhere->id]);

        $count = $this->openCount($this->manager(), [$this->office->id]);

        $this->assertSame(InventoryCount::OPEN, $count->status);
        $this->assertSame([$kept->id], $count->items()->pluck('equipment_id')->all());
        $this->assertDatabaseHas('inventory_count_items', [
            'equipment_id' => $kept->id,
            'office_id' => $this->office->id,
            'condition_id' => $this->serviceable,
            'accountable_officer_id' => $this->officer->id,
            'location' => 'Room 1',
            'result' => 'pending',
        ]);
    }

    public function test_marking_items_sorts_them_into_comparison_buckets(): void
    {
        $matched = $this->item();
        $broken = $this->item();
        $missing = $this->item();
        $this->item();
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);
        $itemId = fn (Equipment $equipment) => $count->items()->where('equipment_id', $equipment->id)->value('id');

        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}/items/{$itemId($matched)}", ['result' => 'found'])
            ->assertOk()->assertJsonPath('data.bucket', 'matched')->assertJsonPath('data.found.condition_id', $this->serviceable);
        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}/items/{$itemId($broken)}", ['result' => 'found', 'found_condition_id' => $this->unserviceable])
            ->assertOk()->assertJsonPath('data.bucket', 'condition_changed')->assertJsonPath('data.unserviceable', true);
        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}/items/{$itemId($missing)}", ['result' => 'not_found'])
            ->assertOk()->assertJsonPath('data.bucket', 'not_found');

        $this->actingAs($user)->getJson("/api/inventory-counts/{$count->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.buckets', ['pending' => 1, 'matched' => 1, 'condition_changed' => 1, 'holder_changed' => 0, 'not_found' => 1])
            ->assertJsonPath('data.summary.offices.0.total', 4);

        $this->actingAs($user)->getJson("/api/inventory-counts/{$count->id}/items?bucket=discrepancies")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_lookup_resolves_a_scanned_qr_label_and_a_property_number(): void
    {
        $equipment = $this->item(['property_no' => 'PN-2026-0001']);
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);

        $this->actingAs($user)->getJson("/api/inventory-counts/{$count->id}/lookup?".http_build_query(['code' => "https://iris.example/equipment/{$equipment->id}/edit"]))
            ->assertOk()->assertJsonPath('data.item.equipment.id', $equipment->id);
        $this->actingAs($user)->getJson("/api/inventory-counts/{$count->id}/lookup?code=PN-2026-0001")
            ->assertOk()->assertJsonPath('data.item.equipment.id', $equipment->id);
    }

    public function test_an_item_held_elsewhere_can_be_recorded_as_found_at_station_but_a_listed_one_cannot(): void
    {
        $listed = $this->item();
        $elsewhere = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $stray = $this->item(['current_accountable_officer_id' => $elsewhere->id]);
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);

        $this->actingAs($user)->getJson("/api/inventory-counts/{$count->id}/lookup?code={$stray->property_no}")
            ->assertOk()->assertJsonPath('data.item', null)->assertJsonPath('data.equipment.id', $stray->id);

        $this->actingAs($user)->postJson("/api/inventory-counts/{$count->id}/found-items", ['office_id' => $this->office->id, 'equipment_id' => $stray->id])
            ->assertCreated();
        $this->actingAs($user)->postJson("/api/inventory-counts/{$count->id}/found-items", ['office_id' => $this->office->id, 'equipment_id' => $listed->id])
            ->assertUnprocessable()->assertJsonValidationErrors('equipment_id');
        $this->actingAs($user)->postJson("/api/inventory-counts/{$count->id}/found-items", ['office_id' => $this->office->id, 'description' => 'Unlabeled monitor'])
            ->assertCreated();
    }

    public function test_items_cannot_be_marked_once_the_count_is_closed(): void
    {
        $equipment = $this->item();
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);
        $itemId = $count->items()->where('equipment_id', $equipment->id)->value('id');

        $this->actingAs($user)->postJson("/api/inventory-counts/{$count->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}/items/{$itemId}", ['result' => 'found'])->assertForbidden();
    }

    public function test_offices_are_fixed_once_open_and_a_count_can_be_deleted(): void
    {
        $this->item();
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);

        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}", [
            'name' => 'Renamed',
            'as_of_date' => '2026-12-31',
            'office_ids' => [$this->office->id, $this->otherOffice->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('office_ids');

        $this->actingAs($user)->putJson("/api/inventory-counts/{$count->id}", [
            'name' => 'Renamed',
            'as_of_date' => '2026-12-31',
            'office_ids' => [$this->office->id],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $manageOnly = $this->userWithPermissions([Permission::InventoryCountView, Permission::InventoryCountManage]);
        $this->actingAs($manageOnly)->deleteJson("/api/inventory-counts/{$count->id}")->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/inventory-counts/{$count->id}")->assertNoContent();
        $this->assertDatabaseMissing('inventory_counts', ['id' => $count->id]);
        $this->assertSame(0, InventoryCountItem::query()->count());
    }

    public function test_a_count_with_no_equipment_at_its_offices_cannot_be_opened(): void
    {
        $user = $this->manager();
        $id = $this->actingAs($user)->postJson('/api/inventory-counts', [
            'name' => 'Empty',
            'as_of_date' => '2026-12-31',
            'office_ids' => [$this->otherOffice->id],
        ])->assertCreated()->json('data.id');

        $this->actingAs($user)->postJson("/api/inventory-counts/{$id}/open")->assertUnprocessable()->assertJsonValidationErrors('office_ids');
        $this->assertSame(InventoryCount::DRAFT, InventoryCount::findOrFail($id)->status);
    }

    public function test_a_school_scoped_counter_sees_only_counts_and_items_at_their_office(): void
    {
        $this->item();
        $elsewhere = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $this->item(['current_accountable_officer_id' => $elsewhere->id]);
        $count = $this->openCount($this->manager(), [$this->office->id, $this->otherOffice->id]);
        $onlyOther = $this->openCount($this->manager(), [$this->otherOffice->id]);

        $counter = $this->userWithPermissions([Permission::InventoryCountView, Permission::InventoryCountConduct], ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($counter)->getJson('/api/inventory-counts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $count->id);
        $this->actingAs($counter)->getJson("/api/inventory-counts/{$onlyOther->id}")->assertNotFound();
        $this->actingAs($counter)->getJson("/api/inventory-counts/{$count->id}/items")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($counter)->postJson('/api/inventory-counts', ['name' => 'X', 'as_of_date' => '2026-12-31', 'office_ids' => [$this->office->id]])->assertForbidden();
    }

    public function test_a_school_scoped_manager_cannot_count_another_office(): void
    {
        $manager = $this->manager(DataScope::School, $this->office->id);

        $this->actingAs($manager)->postJson('/api/inventory-counts', [
            'name' => 'Physical Count',
            'as_of_date' => '2026-12-31',
            'office_ids' => [$this->otherOffice->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('office_ids.0');
    }

    public function test_opening_and_closing_are_audited(): void
    {
        $this->item();
        $user = $this->manager();
        $count = $this->openCount($user, [$this->office->id]);
        $this->actingAs($user)->postJson("/api/inventory-counts/{$count->id}/close")->assertOk();

        $this->assertDatabaseHas('activity_log', ['event' => 'inventory_count.opened', 'subject_id' => $count->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'inventory_count.closed', 'subject_id' => $count->id]);
        $this->assertSame(1, InventoryCountItem::query()->count());
    }
}
