<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddDashboardSectionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const EQUIPMENT_SECTIONS = ['dashboard.equipment_distribution', 'dashboard.equipment_overview', 'dashboard.ppe', 'dashboard.semi_expendable'];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_28_000003_add_dashboard_section_permissions.php');
    }

    private function seedRoles(): void
    {
        foreach (['equipment.view' => 10, 'isp_accounts.view' => 11, 'personnel.view' => 12] as $name => $id) {
            DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'guard_name' => 'web']);
        }

        foreach ([1 => 'both', 2 => 'equipment_only', 3 => 'isp_only', 4 => 'neither'] as $id => $name) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name, 'guard_name' => 'web', 'label' => $name]);
        }

        foreach ([[1, 10], [1, 11], [2, 10], [3, 11], [4, 12]] as [$roleId, $permissionId]) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * @return list<string>
     */
    private function dashboardPermissionsOf(string $role): array
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)
            ->where('permissions.name', 'like', 'dashboard.%')
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    public function test_up_grants_sections_by_existing_view_permissions(): void
    {
        $this->seedRoles();

        $this->migration()->up();

        $this->assertSame(['dashboard.equipment_distribution', 'dashboard.equipment_overview', 'dashboard.internet_connectivity', 'dashboard.ppe', 'dashboard.semi_expendable'], $this->dashboardPermissionsOf('both'));
        $this->assertSame(self::EQUIPMENT_SECTIONS, $this->dashboardPermissionsOf('equipment_only'));
        $this->assertSame(['dashboard.internet_connectivity'], $this->dashboardPermissionsOf('isp_only'));
        $this->assertSame([], $this->dashboardPermissionsOf('neither'));
        $this->assertSame(5, DB::table('permissions')->where('name', 'like', 'dashboard.%')->count());
        $this->assertTrue(DB::table('activity_log')->where('event', 'system.permissions_granted')->exists());
    }

    public function test_up_is_idempotent_and_down_removes_the_permissions(): void
    {
        $this->seedRoles();

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame(10, DB::table('role_has_permissions')->whereIn('role_id', [1, 2, 3])->whereNotIn('permission_id', [10, 11])->count());

        $this->migration()->down();

        $this->assertSame(0, DB::table('permissions')->where('name', 'like', 'dashboard.%')->count());
        $this->assertSame(5, DB::table('role_has_permissions')->count());
    }

    public function test_up_inserts_nothing_on_an_empty_database(): void
    {
        $this->migration()->up();

        $this->assertSame(0, DB::table('permissions')->count());
    }
}
