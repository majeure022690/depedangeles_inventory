<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Enums\Permission as PermissionEnum;
use App\Models\EquipmentLibrary;
use App\Models\ItemType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the reference-data.manage permission and the shared
 * ReferenceDataPolicy that gates all 13 lookup-normalization tables (see
 * that Policy's doc-comment for why one shared class, not 13). Exercises
 * one Tier 1 model (ItemType) and one Tier 2 model (EquipmentLibrary) as
 * representatives — the Policy has no per-model branching, so these two
 * stand in for all 13 the same way LookupControllerTest's single model
 * stood in for all 35 old lookup types.
 */
class ReferenceDataPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('division-ict-admin');

        return $user;
    }

    private function encoder(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encoder');

        return $user;
    }

    public function test_division_ict_admin_role_has_reference_data_manage(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = $this->admin();

        $this->assertTrue($admin->hasPermissionTo(PermissionEnum::ReferenceDataManage));
    }

    public function test_encoder_and_viewer_roles_lack_reference_data_manage(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $encoder = $this->encoder();
        $this->assertFalse($encoder->hasPermissionTo(PermissionEnum::ReferenceDataManage));

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->assertFalse($viewer->hasPermissionTo(PermissionEnum::ReferenceDataManage));
    }

    public function test_admin_can_view_and_manage_a_tier1_reference_row(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->admin();

        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->assertTrue($admin->can('viewAny', ItemType::class));
        $this->assertTrue($admin->can('view', $itemType));
        $this->assertTrue($admin->can('create', ItemType::class));
        $this->assertTrue($admin->can('update', $itemType));
        $this->assertTrue($admin->can('delete', $itemType));
    }

    public function test_admin_can_view_and_manage_a_tier2_reference_row(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->admin();

        $library = EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->assertTrue($admin->can('viewAny', EquipmentLibrary::class));
        $this->assertTrue($admin->can('view', $library));
        $this->assertTrue($admin->can('create', EquipmentLibrary::class));
        $this->assertTrue($admin->can('update', $library));
        $this->assertTrue($admin->can('delete', $library));
    }

    public function test_encoder_is_denied_on_reference_data_models_despite_having_other_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $encoder = $this->encoder();

        $itemType = ItemType::create(['name' => 'Desktop', 'sort_order' => 0, 'is_active' => true]);
        $library = EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->assertFalse($encoder->can('viewAny', ItemType::class));
        $this->assertFalse($encoder->can('update', $itemType));
        $this->assertFalse($encoder->can('viewAny', EquipmentLibrary::class));
        $this->assertFalse($encoder->can('update', $library));
    }
}
