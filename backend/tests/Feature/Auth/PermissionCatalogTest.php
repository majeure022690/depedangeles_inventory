<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_every_enum_case_is_seeded_on_the_web_guard(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
            PermissionModel::where('guard_name', 'web')->pluck('name')->all(),
        );
        $this->assertSame(0, PermissionModel::where('guard_name', '!=', 'web')->count());
    }

    public function test_security_settings_manage_is_seeded_to_the_administrator_roles_only(): void
    {
        $this->assertEqualsCanonicalizing(['super_administrator', 'system_administrator'], $this->holdersOf(Permission::SecuritySettingsManage));
    }

    public function test_reference_data_manage_is_seeded_to_the_administrator_roles_only(): void
    {
        $this->assertEqualsCanonicalizing(['super_administrator', 'system_administrator'], $this->holdersOf(Permission::ReferenceDataManage));
    }

    public function test_email_log_manage_no_longer_exists(): void
    {
        $this->assertNotContains('email_log.manage', array_map(fn (Permission $permission) => $permission->value, Permission::cases()));
        $this->assertFalse(PermissionModel::where('name', 'email_log.manage')->exists());
    }

    /**
     * @return array<int, string>
     */
    private function holdersOf(Permission $permission): array
    {
        return Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission->value))
            ->pluck('name')
            ->all();
    }

    public function test_seeded_roles_and_their_permission_sets(): void
    {
        $all = array_map(fn (Permission $permission) => $permission->value, Permission::cases());
        $roleWrites = [Permission::RolesCreate->value, Permission::RolesEdit->value, Permission::RolesDelete->value];

        $this->assertEqualsCanonicalizing(['super_administrator', 'system_administrator', 'encoder', 'office_encoder', 'viewer'], Role::pluck('name')->all());
        $this->assertEqualsCanonicalizing($all, $this->permissionsOf('super_administrator'));
        $this->assertEqualsCanonicalizing(array_values(array_diff($all, $roleWrites)), $this->permissionsOf('system_administrator'));
        $this->assertNotContains(Permission::IspAccountsView->value, $this->permissionsOf('viewer'));
        $this->assertEqualsCanonicalizing($this->permissionsOf('encoder'), $this->permissionsOf('office_encoder'));

        $scopes = Role::pluck('data_scope', 'name')->map(fn (DataScope $scope) => $scope->value)->all();
        $this->assertSame('all', $scopes['super_administrator']);
        $this->assertSame('all', $scopes['system_administrator']);
        $this->assertSame('all', $scopes['encoder']);
        $this->assertSame('school', $scopes['office_encoder']);
        $this->assertSame('own', $scopes['viewer']);
        $this->assertSame('Division Encoder', Role::findByName('encoder')->label);
        $this->assertSame('Office Encoder', Role::findByName('office_encoder')->label);
    }

    /**
     * @return array<int, string>
     */
    private function permissionsOf(string $role): array
    {
        return Role::where('name', $role)->firstOrFail()->permissions()->pluck('name')->all();
    }

    public function test_every_permission_has_one_of_the_picker_groups(): void
    {
        $groups = ['Personnel', 'Equipment', 'ISP Accounts', 'Offices', 'Stakeholder Profile', 'Internet Connectivity', 'Administration'];

        foreach (Permission::cases() as $permission) {
            $this->assertContains($permission->group(), $groups, $permission->value);
            $this->assertNotSame('', $permission->label());
        }

        $this->assertSame('Administration', Permission::ReferenceDataManage->group());
        $this->assertSame('Administration', Permission::SecuritySettingsManage->group());
        $this->assertSame('Offices', Permission::OfficeDelete->group());
    }

    public function test_view_all_permissions_no_longer_exist(): void
    {
        $values = array_map(fn (Permission $permission) => $permission->value, Permission::cases());

        $this->assertNotContains('stakeholder_profile.view_all', $values);
        $this->assertNotContains('internet_connectivity.view_all', $values);
        $this->assertSame(0, PermissionModel::where('name', 'like', '%.view_all')->count());
    }

    public function test_roles_created_after_an_api_request_keep_the_web_guard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');

        // auth:sanctum makes `sanctum` the default guard from here on.
        $this->actingAs($user)->getJson('/api/user')->assertOk();
        $this->assertSame('sanctum', config('auth.defaults.guard'));

        $role = Role::create(['name' => 'created-after-request', 'label' => 'Created After Request']);
        $role->givePermissionTo(Permission::EquipmentView->value);
        $user->assignRole('created-after-request');

        $this->assertSame('web', $role->guard_name);
        $this->assertTrue($user->fresh()->hasPermissionTo(Permission::EquipmentView));
    }
}
