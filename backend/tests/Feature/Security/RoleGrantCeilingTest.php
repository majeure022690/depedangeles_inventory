<?php

namespace Tests\Feature\Security;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Owner decision (L1): a roles.create/roles.edit holder may only grant permissions
 * they hold themselves, on create and update. Permissions a role already
 * has may be kept or removed. Full admins hold everything, so they're
 * unaffected.
 */
class RoleGrantCeilingTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function roleEditorActor(): User
    {
        return $this->userWithPermissions([PermissionEnum::RolesCreate, PermissionEnum::RolesEdit, PermissionEnum::EquipmentView]);
    }

    public function test_store_rejects_granting_a_permission_the_actor_does_not_hold(): void
    {
        $this->actingAs($this->roleEditorActor())->postJson('/api/roles', [
            'name' => 'escalated',
            'label' => 'Escalated',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::UsersEdit->value],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions'])
            ->assertJsonFragment(['You can only grant permissions you hold yourself. You do not hold: users.edit.']);

        $this->assertNull(Role::where('name', 'escalated')->first());
    }

    public function test_store_allows_permissions_within_the_actors_own_set(): void
    {
        $this->actingAs($this->roleEditorActor())->postJson('/api/roles', [
            'name' => 'reader',
            'label' => 'Reader',
            'permissions' => [PermissionEnum::EquipmentView->value],
        ])->assertCreated();
    }

    public function test_update_rejects_adding_a_permission_the_actor_does_not_hold(): void
    {
        $role = Role::create(['name' => 'readers', 'label' => 'Readers', 'data_scope' => DataScope::Own]);
        $role->givePermissionTo(PermissionEnum::EquipmentView->value);
        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $this->actingAs($this->roleEditorActor())->putJson("/api/roles/{$role->id}", [
            'name' => 'readers',
            'label' => $role->label,
            'permissions' => [...$before, PermissionEnum::UsersEdit->value],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permissions']);

        $this->assertSame($before, $role->fresh()->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_update_may_keep_or_remove_permissions_the_role_already_grants(): void
    {
        $role = Role::create(['name' => 'ops', 'label' => 'Ops']);
        $role->givePermissionTo([PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentDelete->value, PermissionEnum::OfficeDelete->value]);

        // Relabel, keep equipment.delete (not held by the actor), drop office.delete.
        $this->actingAs($this->roleEditorActor())->putJson("/api/roles/{$role->id}", [
            'name' => 'ops',
            'label' => 'Operations',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentDelete->value],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentDelete->value],
            $role->fresh()->permissions()->pluck('name')->all(),
        );
    }

    public function test_a_holder_of_every_permission_can_grant_any_permission(): void
    {
        $this->actingAs($this->userWithAllPermissions())->postJson('/api/roles', [
            'name' => 'everything',
            'label' => 'Everything',
            'permissions' => array_map(fn (PermissionEnum $p) => $p->value, PermissionEnum::cases()),
        ])->assertCreated();
    }

    public function test_service_backstop_rejects_unheld_grants(): void
    {
        $actor = $this->roleEditorActor();

        $this->expectException(ValidationException::class);

        try {
            app(RoleService::class)->create($actor, ['name' => 'x', 'label' => 'X', 'description' => null], [PermissionEnum::RolesDelete->value, PermissionEnum::AuditLogView->value]);
        } finally {
            $this->assertNull(Role::where('name', 'x')->first());
        }
    }
}
