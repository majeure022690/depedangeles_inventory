<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentBulkTransactionTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $from;

    private Office $to;

    private Personnel $holder;

    private Personnel $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->from = Office::factory()->create();
        $this->to = Office::factory()->create();
        $this->holder = Personnel::factory()->create(['office_id' => $this->from->id, 'inactive' => false]);
        $this->receiver = Personnel::factory()->create(['office_id' => $this->to->id, 'inactive' => false]);
    }

    private function item(array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'current_accountable_officer_id' => $this->holder->id,
            'current_end_user_id' => null,
            'disposition_status' => 'Normal',
            ...$attributes,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function payload(array $ids): array
    {
        return [
            'equipment_ids' => $ids,
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->receiver->id,
            'date_assigned_accountable_officer' => '2026-11-02',
            'par_ics_rrsp_rs_wmr_no' => 'PAR-2026-001',
        ];
    }

    public function test_moves_every_selected_item_and_its_peripherals_to_the_new_officer(): void
    {
        $laptop = $this->item();
        $desktop = $this->item();
        $monitor = $this->item(['parent_equipment_id' => $desktop->id]);
        $untouched = $this->item();

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentTransactionsCreate]))
            ->postJson('/api/equipment/transactions/bulk', $this->payload([$laptop->id, $desktop->id]))
            ->assertCreated()
            ->assertJsonPath('data.count', 2);

        foreach ([$laptop, $desktop, $monitor] as $moved) {
            $this->assertSame($this->receiver->id, $moved->fresh()->current_accountable_officer_id);
            $this->assertDatabaseHas('equipment_transactions', ['equipment_id' => $moved->id, 'transaction_type' => 'Issuance/Transfer', 'par_ics_rrsp_rs_wmr_no' => 'PAR-2026-001']);
        }
        $this->assertSame($this->holder->id, $untouched->fresh()->current_accountable_officer_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'equipment.bulk_transaction']);
    }

    public function test_nothing_moves_when_any_selected_item_is_refused(): void
    {
        $mine = $this->item();
        $desktop = $this->item();
        $peripheral = $this->item(['parent_equipment_id' => $desktop->id]);
        $elsewhere = $this->item(['current_accountable_officer_id' => Personnel::factory()->create(['office_id' => Office::factory()->create()->id])->id]);
        $user = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentTransactionsCreate], ['office_id' => $this->from->id], DataScope::School);

        $this->actingAs($user)->postJson('/api/equipment/transactions/bulk', [...$this->payload([$mine->id, $peripheral->id, $elsewhere->id]), 'accountable_officer_id' => $this->holder->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment_ids']);

        $this->assertSame(0, $mine->transactions()->count());
    }

    public function test_needs_the_transaction_permission_and_a_selection(): void
    {
        $item = $this->item();

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit]))
            ->postJson('/api/equipment/transactions/bulk', $this->payload([$item->id]))
            ->assertForbidden();

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentTransactionsCreate]))
            ->postJson('/api/equipment/transactions/bulk', $this->payload([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment_ids']);
    }
}
