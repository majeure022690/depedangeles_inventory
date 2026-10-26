<?php

namespace Tests\Feature\Security;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * An actor can never hand out, manage, create or edit a reach wider than
 * their own data scope, even when they hold every permission involved.
 */
class DataScopeTierGuardTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const ENCODER_PERMISSIONS = [
        Permission::PersonnelView, Permission::PersonnelCreate, Permission::PersonnelEdit,
        Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate,
        Permission::IspAccountsView, Permission::IspAccountsCreate, Permission::IspAccountsEdit,
        Permission::StakeholderProfileView, Permission::StakeholderProfileEdit,
        Permission::InternetConnectivityView, Permission::InternetConnectivityEdit,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $editing = array_map(fn (Permission $p) => $p->value, self::ENCODER_PERMISSIONS);
        Role::create(['name' => 'division-editor', 'label' => 'Division Editor', 'data_scope' => DataScope::All])->givePermissionTo($editing);
        Role::create(['name' => 'office-editor', 'label' => 'Office Editor', 'data_scope' => DataScope::School])->givePermissionTo($editing);
        Role::create(['name' => 'reader', 'label' => 'Reader', 'data_scope' => DataScope::Own])->givePermissionTo(Permission::EquipmentView->value);
    }

    private function actor(DataScope $scope): User
    {
        return $this->userWithPermissions([
            ...self::ENCODER_PERMISSIONS,
            Permission::UsersView, Permission::UsersEdit,
            Permission::RolesView, Permission::RolesCreate, Permission::RolesEdit,
        ], ['office_id' => Office::factory()->create()->id], $scope);
    }

    private function roleId(string $name): int
    {
        return (int) Role::query()->where('name', $name)->value('id');
    }

    /**
     * @return list<string>
     */
    private function encoderPermissionValues(): array
    {
        return array_map(fn (Permission $p) => $p->value, self::ENCODER_PERMISSIONS);
    }

    public function test_assigning_a_role_wider_than_the_actors_scope_is_refused(): void
    {
        $actor = $this->actor(DataScope::School);
        $target = $this->userWithRole('reader');

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", ['role_id' => $this->roleId('division-editor')])
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", ['role_id' => $this->roleId('office-editor')])->assertOk();
        $this->assertSame(['office-editor'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_assignable_roles_exclude_wider_scopes(): void
    {
        $names = collect($this->actingAs($this->actor(DataScope::School))->getJson('/api/users/options')->assertOk()->json('data.roles'))
            ->pluck('name')->all();

        $this->assertContains('office-editor', $names);
        $this->assertContains('reader', $names);
        $this->assertNotContains('division-editor', $names);

        $ownNames = collect($this->actingAs($this->actor(DataScope::Own))->getJson('/api/users/options')->json('data.roles'))->pluck('name')->all();
        $this->assertNotContains('office-editor', $ownNames);
        $this->assertContains('reader', $ownNames);
    }

    public function test_an_account_with_a_wider_scope_cannot_be_managed(): void
    {
        $actor = $this->actor(DataScope::School);
        $divisionEncoder = $this->userWithRole('division-editor');
        $officeEncoder = $this->userWithRole('office-editor');

        $this->assertFalse(app(UserRoleService::class)->canManage($actor, $divisionEncoder));
        $this->assertTrue(app(UserRoleService::class)->canManage($actor, $officeEncoder));

        $this->actingAs($actor)->putJson("/api/users/{$divisionEncoder->id}", ['role_id' => $this->roleId('reader')])->assertForbidden();
        $this->assertSame(['division-editor'], $divisionEncoder->fresh()->roles()->pluck('name')->all());

        $rows = collect($this->actingAs($actor)->getJson('/api/users?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($rows[$divisionEncoder->id]['can_edit']);
        $this->assertTrue($rows[$officeEncoder->id]['can_edit']);
    }

    public function test_role_create_cannot_exceed_the_actors_scope(): void
    {
        $actor = $this->actor(DataScope::School);
        $permissions = [Permission::EquipmentView->value];

        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'wide', 'label' => 'Wide', 'data_scope' => 'all', 'permissions' => $permissions])
            ->assertUnprocessable()->assertJsonValidationErrors('data_scope');
        $this->assertNull(Role::query()->where('name', 'wide')->first());

        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'same', 'label' => 'Same', 'data_scope' => 'school', 'permissions' => $permissions])
            ->assertCreated()->assertJsonPath('data.data_scope', 'school');
        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'default', 'label' => 'Default', 'permissions' => $permissions])
            ->assertCreated()->assertJsonPath('data.data_scope', 'own');
    }

    public function test_role_update_cannot_widen_beyond_the_actors_scope(): void
    {
        $actor = $this->actor(DataScope::School);
        $role = Role::create(['name' => 'narrow', 'label' => 'Narrow', 'data_scope' => DataScope::Own]);

        $this->actingAs($actor)->putJson("/api/roles/{$role->id}", ['name' => 'narrow', 'label' => 'Narrow', 'data_scope' => 'all', 'permissions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('data_scope');
        $this->assertSame(DataScope::Own, $role->fresh()->data_scope);

        $this->actingAs($actor)->putJson("/api/roles/{$role->id}", ['name' => 'narrow', 'label' => 'Narrow', 'data_scope' => 'school', 'permissions' => []])
            ->assertOk();
        $this->assertSame(DataScope::School, $role->fresh()->data_scope);
    }

    public function test_a_scoped_actor_cannot_edit_an_all_scope_role_at_all(): void
    {
        $actor = $this->actor(DataScope::School);
        $encoder = Role::query()->where('name', 'division-editor')->firstOrFail();
        $payload = ['name' => 'division-editor', 'label' => 'Hijacked', 'permissions' => $this->encoderPermissionValues()];

        foreach (['omitted' => [], 'all' => ['data_scope' => 'all'], 'school' => ['data_scope' => 'school'], 'own' => ['data_scope' => 'own']] as $case => $scope) {
            $response = $this->actingAs($actor)->putJson("/api/roles/{$encoder->id}", [...$payload, ...$scope]);
            $this->assertSame(422, $response->status(), "data_scope {$case}: answered {$response->status()}");
            $response->assertJsonValidationErrors('data_scope');
        }
        $encoder->refresh();
        $this->assertSame('Division Editor', $encoder->label);
        $this->assertSame(DataScope::All, $encoder->data_scope);
    }

    public function test_all_scope_actor_can_set_any_scope(): void
    {
        $actor = $this->actor(DataScope::All);

        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'wide', 'label' => 'Wide', 'data_scope' => 'all', 'permissions' => []])
            ->assertCreated()->assertJsonPath('data.data_scope', 'all');
    }
}
