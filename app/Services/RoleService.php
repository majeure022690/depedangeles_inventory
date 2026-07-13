<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission as PermissionEnum;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only sanctioned entry point for creating, editing, or deleting
 * roles (the roles.manage admin screen — RoleController). RolePolicy only
 * answers "does this actor hold roles.manage"; every business-rule
 * invariant around a specific mutation — the protected 'pending' role,
 * "a role still assigned to users can't be deleted", and the
 * self-escalation/last-holder lockout guards on editing a role's
 * permission set — is enforced here, the same split UserRoleService
 * already established for user role assignment.
 *
 * Two of the permissions this app defines are "sensitive" in a way no
 * other permission is: RolesManage and UsersManage are the only two
 * permissions that gate the ability to change *anyone's* access,
 * including one's own. Editing a role's permission set to strip either of
 * these off is therefore held to a stricter standard than any other
 * permission edit — see guardAgainstLockout() below.
 */
final class RoleService
{
    /**
     * Permissions whose removal from a role triggers the lockout guards
     * below. Deliberately just these two — see this class's doc-comment
     * and Permission::RolesManage's doc-comment for why they're a
     * different category of risk than e.g. removing equipment.delete
     * from a role (which can never lock anyone out of the admin
     * screens that would let them undo the mistake).
     *
     * @var array<int, PermissionEnum>
     */
    private const SENSITIVE_PERMISSIONS = [
        PermissionEnum::RolesManage,
        PermissionEnum::UsersManage,
    ];

    /**
     * @param  array{name: string, label: string, description: ?string}  $attributes
     * @param  array<int, string>  $permissionValues
     */
    public function create(User $actor, array $attributes, array $permissionValues): Role
    {
        return DB::transaction(function () use ($actor, $attributes, $permissionValues): Role {
            $role = Role::create($attributes);

            $this->syncPermissions($role, $permissionValues);

            AuditLog::record('role.created', $role, [
                'attributes' => $attributes,
                'permissions' => $permissionValues,
            ], $actor->id);

            return $role;
        });
    }

    /**
     * @param  array{name: string, label: string, description: ?string}  $attributes
     * @param  array<int, string>  $permissionValues
     */
    public function update(User $actor, Role $role, array $attributes, array $permissionValues): void
    {
        // Belt-and-suspenders: RoleUpdateRequest already blocks renaming/
        // depriving 'pending' of its zero-permission set at the HTTP
        // layer (where it can populate the Inertia form's error bag), but
        // this Service is the one place the invariant must hold for every
        // caller, HTTP or otherwise — see Role::PENDING's doc-comment for
        // why this specific role can never be allowed to grant anything.
        if ($role->isProtected()) {
            if ($attributes['name'] !== $role->name) {
                throw ValidationException::withMessages([
                    'name' => __("The :label role's name cannot be changed.", ['label' => $role->label]),
                ]);
            }

            if ($permissionValues !== []) {
                throw ValidationException::withMessages([
                    'permissions' => __(
                        'The :label role must always have zero permissions — it is the deny-by-default '.
                        'holding pen for new self-registrations.',
                        ['label' => $role->label],
                    ),
                ]);
            }
        }

        DB::transaction(function () use ($actor, $role, $attributes, $permissionValues): void {
            // guardAgainstLockout() runs INSIDE this transaction, behind a
            // chokepoint row lock it takes on the sensitive Permission
            // itself, so its "remaining holders" count can no longer race
            // against a concurrent mutation — see that method's
            // doc-comment (MEDIUM, 2026-07 follow-up review: TOCTOU).
            $this->guardAgainstLockout($actor, $role, $permissionValues);

            $before = [
                'attributes' => $role->only(['name', 'label', 'description']),
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
            ];

            $role->update($attributes);
            $this->syncPermissions($role, $permissionValues);

            AuditLog::record('role.updated', $role, [
                'before' => $before,
                'after' => [
                    'attributes' => $role->only(['name', 'label', 'description']),
                    'permissions' => collect($permissionValues)->sort()->values()->all(),
                ],
            ], $actor->id);

            // Every user holding this role just had their effective
            // permission set potentially change; nothing in this request
            // re-reads it afterward today, but clearing here keeps the
            // invariant "cached permissions are never stale after a role
            // mutation" true regardless of what future callers do.
            User::query()
                ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
                ->get()
                ->each(fn (User $user) => $user->forgetCachedPermissions());
        });
    }

    public function delete(User $actor, Role $role): void
    {
        if ($role->isProtected()) {
            throw ValidationException::withMessages([
                'role' => __(
                    'The :label role cannot be deleted — it is required by the self-registration flow.',
                    ['label' => $role->label],
                ),
            ]);
        }

        $userCount = $role->users()->count();

        if ($userCount > 0) {
            throw ValidationException::withMessages([
                'role' => __(
                    'Cannot delete the :label role: it is currently assigned to :count user(s). '.
                    'Reassign those users to another role first.',
                    ['label' => $role->label, 'count' => $userCount],
                ),
            ]);
        }

        DB::transaction(function () use ($actor, $role): void {
            AuditLog::record('role.deleted', $role, [
                'name' => $role->name,
                'label' => $role->label,
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
            ], $actor->id);

            $role->delete();
        });
    }

    /**
     * Enforces the two forward-looking requirements from the RBAC
     * request that made roles admin-editable in the first place:
     *
     *   1. System-wide lockout: a role's permission set can never be
     *      edited to remove RolesManage/UsersManage if doing so would
     *      leave NO role in the system granting it to any user —
     *      generalized from UserRoleService::changeRole()'s single-role
     *      "last remaining users.manage holder" check to consider every
     *      role+user in the system, not just the one role being edited.
     *   2. Self-lockout: a user can never edit the permissions of a role
     *      THEY THEMSELVES currently hold in a way that strips
     *      RolesManage/UsersManage from their own effective permission
     *      set, unless another role they hold still grants it. This is
     *      narrower than UserRoleService's absolute "can't touch your own
     *      role assignment" rule (which makes sense there — the actor and
     *      target are quite literally the same row) — here the actor is
     *      editing a role DEFINITION they may need to maintain (e.g.
     *      adding a brand-new permission to the admin role they hold when
     *      a feature ships), so only the specific act of removing their
     *      own access to roles.manage/users.manage is blocked, not every
     *      edit to a role they happen to hold.
     *
     * MUST be called from inside the same DB::transaction() as the write
     * it's guarding (see update() above) — the chokepoint lock it takes
     * below only serializes concurrent transactions for the duration of
     * an open transaction; taken outside one, MySQL releases a `FOR
     * UPDATE` row lock the instant the SELECT statement completes, which
     * defeats the point (MEDIUM, 2026-07 follow-up review: TOCTOU race).
     *
     * @param  array<int, string>  $newPermissionValues
     */
    private function guardAgainstLockout(User $actor, Role $role, array $newPermissionValues): void
    {
        $currentPermissionValues = $role->permissions()->pluck('name')->all();

        foreach (self::SENSITIVE_PERMISSIONS as $permission) {
            $roleCurrentlyGrants = in_array($permission->value, $currentPermissionValues, true);
            $roleWouldStillGrant = in_array($permission->value, $newPermissionValues, true);

            if (! $roleCurrentlyGrants || $roleWouldStillGrant) {
                continue; // Not being removed — nothing to guard.
            }

            // Chokepoint lock: serializes this check against any other
            // transaction — in this method or
            // UserRoleService::syncRoles(), which locks the same
            // permission row for the same reason — that could
            // concurrently strip the last holder of THIS permission from
            // a different role/user. Whichever transaction commits second
            // is forced to wait, then recounts against the first's
            // already-committed state instead of a stale pre-write
            // snapshot.
            Permission::where('name', $permission->value)->lockForUpdate()->first();

            // Would any user anywhere still hold this permission through
            // any OTHER role after this role's grant is removed?
            $remainingHolders = User::query()
                ->whereHas('roles', fn ($query) => $query
                    ->whereKeyNot($role->id)
                    ->whereHas('permissions', fn ($q) => $q->where('name', $permission->value)))
                ->count();

            if ($remainingHolders === 0) {
                throw ValidationException::withMessages([
                    'permissions' => __(
                        'Cannot remove :permission from the :label role: no other role in the system grants '.
                        'it to anyone. Assign another role that grants it first.',
                        ['permission' => $permission->value, 'label' => $role->label],
                    ),
                ]);
            }

            // Would the ACTOR themselves, specifically, still hold this
            // permission afterward — either because they don't hold this
            // role at all, or because one of their OTHER roles grants it?
            $actorHoldsThisRole = $actor->roles()->whereKey($role->id)->exists();

            if (! $actorHoldsThisRole) {
                continue;
            }

            $actorRetainsElsewhere = $actor->roles()
                ->whereKeyNot($role->id)
                ->whereHas('permissions', fn ($q) => $q->where('name', $permission->value))
                ->exists();

            if (! $actorRetainsElsewhere) {
                throw ValidationException::withMessages([
                    'permissions' => __(
                        'Cannot remove :permission from the :label role: you hold this role and no other '.
                        'role of yours grants :permission — this change would lock you out of it. Have '.
                        'another :permission holder make this change instead.',
                        ['permission' => $permission->value, 'label' => $role->label],
                    ),
                ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $permissionValues
     */
    private function syncPermissions(Role $role, array $permissionValues): void
    {
        $permissionIds = Permission::query()
            ->whereIn('name', $permissionValues)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);
    }
}
