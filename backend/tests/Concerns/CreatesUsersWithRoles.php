<?php

namespace Tests\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Expects RolePermissionSeeder to have run (super_administrator/system_administrator/encoder/viewer).
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
     * A user whose only role grants exactly these permissions.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithPermissions(array $permissions, array $attributes = []): User
    {
        $role = Role::create([
            'name' => 'custom-'.uniqid(),
            'label' => 'Custom',
        ]);
        $role->givePermissionTo(array_map(fn (Permission $p) => $p->value, $permissions));

        return $this->userWithRole($role->name, $attributes);
    }
}
