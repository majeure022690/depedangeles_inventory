<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{int, int, int}
     */
    private function counts(): array
    {
        return [DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count()];
    }

    /**
     * @return list<string>
     */
    private function permissionsOf(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()->permissions()->orderBy('name')->pluck('name')->all();
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $before = $this->counts();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($before, $this->counts());
    }

    public function test_admin_edits_to_default_roles_survive_a_reseed(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $encoder = Role::query()->where('name', 'encoder')->firstOrFail();
        $encoder->update(['label' => 'Renamed Encoder', 'data_scope' => DataScope::School]);
        $encoder->syncPermissions([Permission::EquipmentView->value]);

        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        $viewer->givePermissionTo(Permission::IspAccountsView->value);

        $this->seed(RolePermissionSeeder::class);

        $encoder->refresh();
        $this->assertSame('Renamed Encoder', $encoder->label);
        $this->assertSame(DataScope::School, $encoder->data_scope);
        $this->assertSame([Permission::EquipmentView->value], $this->permissionsOf('encoder'));
        $this->assertContains(Permission::IspAccountsView->value, $this->permissionsOf('viewer'));
    }

    public function test_super_administrator_is_always_resynced(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $super = Role::query()->where('name', Role::SUPER_ADMINISTRATOR)->firstOrFail();
        DB::table('roles')->where('id', $super->id)->update(['data_scope' => 'own', 'label' => 'Tampered']);
        $super->revokePermissionTo(Permission::RolesEdit->value);

        $this->seed(RolePermissionSeeder::class);

        $super->refresh();
        $this->assertSame(DataScope::All, $super->data_scope);
        $this->assertSame('Super Administrator', $super->label);
        $this->assertEqualsCanonicalizing(
            array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
            $this->permissionsOf(Role::SUPER_ADMINISTRATOR),
        );
    }

    public function test_a_missing_default_role_is_recreated_with_its_defaults(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $expected = $this->permissionsOf('office_encoder');
        Role::query()->where('name', 'office_encoder')->firstOrFail()->delete();

        $this->seed(RolePermissionSeeder::class);

        $role = Role::query()->where('name', 'office_encoder')->firstOrFail();
        $this->assertSame(DataScope::School, $role->data_scope);
        $this->assertSame($expected, $this->permissionsOf('office_encoder'));
    }
}
