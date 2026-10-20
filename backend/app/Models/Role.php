<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role is a named, admin-configurable bundle of permissions, never a
 * branch in application code. RolePermissionSeeder seeds four starting
 * roles (pending / viewer / encoder / admin); RoleService lets a
 * roles.manage holder create, edit and delete roles from there. The
 * permission catalog itself stays code-defined in App\Enums\Permission.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $label
 * @property string|null $description
 */
#[Fillable(['name', 'guard_name', 'label', 'description'])]
class Role extends SpatieRole
{
    /**
     * The role with baked-in behavior: Super Administrator must always
     * exist, always grant every permission, and cannot be renamed or
     * deleted. RoleService enforces this.
     */
    public const SUPER_ADMINISTRATOR = 'super_administrator';

    /**
     * Spatie declares RoleContract as the return type; narrowed so callers
     * get this model back.
     *
     * The guard is pinned to `web`: Spatie otherwise falls back to the
     * current default guard, which `auth:sanctum` switches to `sanctum`
     * for the rest of an API request, and a `sanctum` role would match no
     * permission.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = []): static
    {
        $attributes['guard_name'] ??= 'web';

        $role = parent::create($attributes);

        if (! $role instanceof static) {
            throw new \LogicException('Spatie Role::create() returned an unexpected model class.');
        }

        return $role;
    }

    public function isProtected(): bool
    {
        return $this->name === self::SUPER_ADMINISTRATOR;
    }

    public function assignedUsersCount(): int
    {
        return (int) static::assignedUsersCountByRoleId([$this->id])->get($this->id, 0);
    }

    /**
     * @param  iterable<int>  $roleIds
     * @return Collection<int, int>
     */
    public static function assignedUsersCountByRoleId(iterable $roleIds): Collection
    {
        return DB::table('model_has_roles')
            ->select('role_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('role_id', collect($roleIds)->all())
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');
    }
}
