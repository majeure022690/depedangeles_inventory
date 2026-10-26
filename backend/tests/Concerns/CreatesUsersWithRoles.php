<?php

namespace Tests\Concerns;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Access depends only on permissions and data scope, so actors are built
 * from exact permission sets (userWithPermissions / userWithAllPermissions)
 * with an explicit scope, never from a seeded role name. userWithRole() is
 * for tests whose subject is a specific role itself (the seeded defaults,
 * the protected super_administrator role, role assignment targets).
 */
trait CreatesUsersWithRoles
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A user whose only role grants exactly these permissions at this scope.
     *
     * @param  array<int, Permission>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithPermissions(array $permissions, array $attributes = [], DataScope $scope = DataScope::All): User
    {
        return $this->grantPermissions(User::factory()->create($attributes), $permissions, $scope);
    }

    /**
     * Gives an existing user a new role granting exactly these permissions at this scope.
     *
     * @param  array<int, Permission>  $permissions
     */
    protected function grantPermissions(User $user, array $permissions, DataScope $scope = DataScope::All): User
    {
        $role = Role::create([
            'name' => 'custom-'.uniqid('', true),
            'label' => 'Custom',
            'data_scope' => $scope,
        ]);
        $role->givePermissionTo(array_map(fn (Permission $p) => $p->value, $permissions));
        $user->assignRole($role->name);

        return $user;
    }

    /**
     * A user holding every permission except the given ones, at this scope.
     *
     * @param  array<int, Permission>  $except
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithAllPermissions(array $except = [], array $attributes = [], DataScope $scope = DataScope::All): User
    {
        return $this->userWithPermissions(
            array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, $except, true))),
            $attributes,
            $scope,
        );
    }

    /**
     * Gives a user the data scope of a role that grants no permissions, for
     * actors whose permissions are granted directly.
     */
    protected function giveDataScope(User $user, DataScope $scope): User
    {
        $role = Role::create(['name' => 'scope-'.uniqid(), 'label' => 'Scope only', 'data_scope' => $scope]);
        $user->assignRole($role->name);

        return $user;
    }
}
