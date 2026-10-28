<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\ItemType;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentItemTypeFilterCapTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    public function test_item_type_ids_beyond_the_first_fifty_are_ignored(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWithPermissions([Permission::EquipmentView]);
        $monitorId = ItemType::where('name', 'Monitor')->valueOrFail('id');
        $monitor = Equipment::factory()->create(['item_type_id' => $monitorId]);

        $padding = range(900001, 900050);

        $this->actingAs($user)->getJson('/api/equipment?item_type='.implode(',', [...$padding, $monitorId]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson('/api/equipment?item_type='.implode(',', [...array_slice($padding, 1), $monitorId]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $monitor->id);
    }
}
