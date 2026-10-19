<?php

namespace Tests\Feature\Auth;

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

    public function test_security_settings_manage_is_seeded_to_admin_only(): void
    {
        $holders = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', Permission::SecuritySettingsManage->value))
            ->pluck('name')
            ->all();

        $this->assertSame(['admin'], $holders);
    }

    public function test_pending_role_grants_nothing(): void
    {
        $this->assertSame(0, Role::where('name', Role::PENDING)->firstOrFail()->permissions()->count());
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
