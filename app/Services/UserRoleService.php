<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission as PermissionEnum;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only sanctioned entry point for the users.manage admin screen's
 * full CRUD — creating admin-provisioned accounts (createUser()),
 * updating profile + role set together (updateUser()), removing
 * accounts (deleteUser()), and changing just the role set (syncRoles(),
 * still called on its own by updateUser() and directly by earlier
 * callers). role_user is a genuine many-to-many pivot — a user commonly
 * needs more than one permission bundle (e.g. Encoder plus some future
 * cross-cutting role) — so syncRoles() replaces a user's entire role set
 * in one call, the same way Eloquent's BelongsToMany::sync() itself
 * works, rather than the older single-role changeRole() this method
 * replaces.
 *
 * Enforces the following requirements from the 2026-07 security review,
 * generalized to multi-role semantics (and extended to createUser()/
 * deleteUser() below):
 *   1. A user can never change their own role set (self-escalation
 *      guard) — unchanged from the single-role version: still an
 *      absolute block, because actor and target are the same row
 *      regardless of how many roles are involved.
 *   2. Permission-tier separation (CRITICAL, follow-up review): a
 *      users.manage holder can assign only roles whose ENTIRE permission
 *      set is already a subset of their own effective permissions — see
 *      guardAgainstPermissionTierViolation() below. Without this, holding
 *      users.manage alone was sufficient to grant ANY role — including
 *      one carrying roles.manage — to any account, silently bypassing the
 *      permission-tier separation Permission::RolesManage's doc-comment
 *      describes but nothing previously enforced.
 *   3. The last remaining holder of `users.manage` can never be synced
 *      into a role set that doesn't grant it — generalized from "does
 *      the single newly-selected role grant it" to "does ANY role in the
 *      new set grant it" (a UNION check, since permissions across a
 *      user's roles are additive). This check now runs INSIDE the write
 *      transaction behind a chokepoint row lock (MEDIUM, follow-up
 *      review) — see the DB::transaction() closure below for why.
 */
final class UserRoleService
{
    /**
     * Every role the given actor is permitted to assign to another user —
     * i.e. every role whose permission set is a SUBSET of the actor's own
     * effective permissions. This is the read-side mirror of
     * guardAgainstPermissionTierViolation() below: UserController::index()
     * calls this to build the `roles` prop so the admin UI never even
     * offers an option the write side (syncRoles()) would reject.
     *
     * division-ict-admin holds every permission in the system, so every
     * role is trivially a subset of their own — every role remains
     * assignable to them with no special-casing required, it falls
     * straight out of the subset rule.
     *
     * @return EloquentCollection<int, Role>
     */
    public function assignableRolesFor(User $actor): EloquentCollection
    {
        $actorPermissionNames = $actor->permissionNames();

        return Role::query()
            ->orderBy('label')
            ->with('permissions:id,name')
            ->get(['id', 'name', 'label'])
            ->filter(fn (Role $role) => $role->permissions
                ->pluck('name')
                ->every(fn (string $permission) => in_array($permission, $actorPermissionNames, true)))
            ->values();
    }

    /**
     * Creates a brand-new user account (the admin-provisioning counterpart
     * to Fortify self-registration — see CreateNewUser) and assigns its
     * initial role set in one step. Subject to the same permission-tier
     * guard as syncRoles(): an actor can only hand out roles whose entire
     * permission set is already a subset of their own.
     *
     * @param  array{name: string, email: string, password: string, office_id?: int|null}  $attributes
     * @param  array<int, int>  $roleIds
     */
    public function createUser(User $actor, array $attributes, array $roleIds): User
    {
        $this->guardAgainstPermissionTierViolation($actor, $roleIds);

        return DB::transaction(function () use ($actor, $attributes, $roleIds) {
            $user = User::create($attributes);

            if ($roleIds !== []) {
                $user->roles()->sync($roleIds);
                $user->forgetCachedPermissions();
            }

            AuditLog::record('user.created', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'office_id' => $user->office_id,
                'role_ids' => $roleIds,
                'roles' => Role::query()->whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all(),
            ], $actor->id);

            return $user;
        });
    }

    /**
     * Updates an existing user's profile (name/email) and role set
     * together — the single entry point the users.manage "Edit" action
     * uses. An actor can never edit their own account through this path
     * (mirrors syncRoles()'s self-escalation guard, and the Index.vue UI
     * never even offers the action against the acting user's own row) —
     * self-service name/email changes go through Settings instead.
     *
     * @param  array{name: string, email: string, office_id?: int|null}  $attributes
     * @param  array<int, int>  $roleIds
     */
    public function updateUser(User $actor, User $target, array $attributes, array $roleIds): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'name' => __('You cannot edit your own account here — use Settings instead.'),
            ]);
        }

        $target->fill($attributes);

        if ($target->isDirty(['name', 'email', 'office_id'])) {
            $previous = [
                'name' => $target->getOriginal('name'),
                'email' => $target->getOriginal('email'),
                'office_id' => $target->getOriginal('office_id'),
            ];

            $target->save();

            AuditLog::record('user.profile_updated', $target, [
                'previous' => $previous,
                'new' => ['name' => $target->name, 'email' => $target->email, 'office_id' => $target->office_id],
            ], $actor->id);
        }

        $this->syncRoles($actor, $target, $roleIds);
    }

    /**
     * Deletes a user account. Two guards mirror the ones already enforced
     * for role changes: an actor can never delete their own account
     * (self-escalation-adjacent — the Index.vue UI never offers the
     * action against the acting user's own row either), and the last
     * remaining holder of `users.manage` can never be deleted, under the
     * same lockForUpdate() chokepoint lock syncRoles() uses to close the
     * TOCTOU race between two concurrent removals.
     *
     * Every FK referencing `users.id` elsewhere in the schema
     * (audit_logs.actor_id, equipment_transactions.recorded_by_user_id,
     * isp_speed_tests/isp_subscription_costs.recorded_by_user_id) is
     * nullable + nullOnDelete by design specifically so this is always
     * schema-safe — deleting an account never cascades away the business
     * records or audit history it was once attached to.
     */
    public function deleteUser(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot delete your own account.'),
            ]);
        }

        DB::transaction(function () use ($actor, $target): void {
            Permission::where('name', PermissionEnum::UsersManage->value)->lockForUpdate()->first();

            if ($target->hasPermissionTo(PermissionEnum::UsersManage)) {
                $remainingUsersManageHolders = User::query()
                    ->whereKeyNot($target->id)
                    ->whereHas('roles.permissions', fn ($query) => $query->where('name', PermissionEnum::UsersManage->value))
                    ->count();

                if ($remainingUsersManageHolders === 0) {
                    throw ValidationException::withMessages([
                        'user' => __(
                            'Cannot delete :name: they are the only remaining user who can manage users. '.
                            'Assign another user a role that can manage users first.',
                            ['name' => $target->name],
                        ),
                    ]);
                }
            }

            AuditLog::record('user.deleted', $target, [
                'name' => $target->name,
                'email' => $target->email,
                'roles' => $target->roles()->pluck('name')->sort()->values()->all(),
            ], $actor->id);

            $target->delete();
        });
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(User $actor, User $target, array $roleIds): void
    {
        // Belt-and-suspenders: UserUpdateRequest already blocks this
        // at the HTTP layer (where it can populate the Inertia form's
        // error bag), but this Service is the one place the invariant
        // must hold for every caller, HTTP or otherwise.
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'role_ids' => __('You cannot change your own roles.'),
            ]);
        }

        $roleIds = collect($roleIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $previousRoleIds = $target->roles()->pluck('roles.id')->sort()->values()->all();

        if ($previousRoleIds === $roleIds) {
            return; // No actual change — nothing to enforce, sync, or audit.
        }

        $this->guardAgainstPermissionTierViolation($actor, $roleIds);

        DB::transaction(function () use ($target, $roleIds, $previousRoleIds) {
            // Chokepoint lock (MEDIUM, 2026-07 follow-up review: TOCTOU
            // race). The "would this leave zero users.manage holders"
            // count below used to run as a plain unlocked SELECT BEFORE
            // this transaction opened, so two concurrent requests each
            // demoting a DIFFERENT users.manage holder could each observe
            // "another holder still exists" (neither had committed yet),
            // both pass, and both commit — collectively zeroing out
            // users.manage system-wide. Locking this permission's own row
            // FOR UPDATE serializes any two transactions — in this
            // service or RoleService::guardAgainstLockout(), which locks
            // the same row for the same reason — that could each
            // independently strip the last holder of this permission, so
            // whichever commits second always recounts against the
            // first's already-committed state instead of a stale
            // pre-write snapshot.
            Permission::where('name', PermissionEnum::UsersManage->value)->lockForUpdate()->first();

            $targetCurrentlyGrantsUsersManage = $target->hasPermissionTo(PermissionEnum::UsersManage);

            $newRolesGrantUsersManage = Role::query()
                ->whereIn('id', $roleIds)
                ->whereHas('permissions', fn ($query) => $query->where('name', PermissionEnum::UsersManage->value))
                ->exists();

            if ($targetCurrentlyGrantsUsersManage && ! $newRolesGrantUsersManage) {
                $remainingUsersManageHolders = User::query()
                    ->whereKeyNot($target->id)
                    ->whereHas('roles.permissions', fn ($query) => $query->where('name', PermissionEnum::UsersManage->value))
                    ->count();

                if ($remainingUsersManageHolders === 0) {
                    throw ValidationException::withMessages([
                        'role_ids' => __(
                            'Cannot change these roles: :name is the only remaining user who can manage users. '.
                            'Assign another user a role that can manage users first.',
                            ['name' => $target->name],
                        ),
                    ]);
                }
            }

            $target->roles()->sync($roleIds);
            $target->forgetCachedPermissions();

            $roleNames = fn (array $ids) => Role::query()
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            AuditLog::record('user.roles_changed', $target, [
                'target_user_id' => $target->id,
                'target_user_email' => $target->email,
                'previous_role_ids' => $previousRoleIds,
                'new_role_ids' => $roleIds,
                'previous_roles' => $roleNames($previousRoleIds),
                'new_roles' => $roleNames($roleIds),
            ]);
        });
    }

    /**
     * Permission-tier separation guard (CRITICAL, 2026-07 follow-up
     * review): a users.manage holder may only assign roles whose ENTIRE
     * permission set is already a subset of their own effective
     * permissions. Permission::RolesManage's own doc-comment already
     * states a users.manage holder should not automatically be able to
     * redefine what every role means — this closes the gap where nothing
     * actually enforced that on the assignment side, letting a
     * users.manage-only actor hand any account (including their own,
     * were self-assignment not separately blocked) a role granting
     * roles.manage or any other permission they never held themselves.
     *
     * Checked against the FULL resulting role set, not just newly-added
     * roles, so an actor can't route around this by leaving an
     * over-privileged role untouched while making an unrelated change —
     * every role remaining on the target after this sync must be one the
     * actor could themselves be trusted with.
     *
     * @param  array<int, int>  $roleIds
     */
    private function guardAgainstPermissionTierViolation(User $actor, array $roleIds): void
    {
        if ($roleIds === []) {
            return; // Stripping every role can never grant anything.
        }

        $actorPermissionNames = $actor->permissionNames();

        $exceedingRoles = Role::query()
            ->whereIn('id', $roleIds)
            ->with('permissions:id,name')
            ->get(['id', 'name', 'label'])
            ->filter(fn (Role $role) => $role->permissions
                ->pluck('name')
                ->contains(fn (string $permission) => ! in_array($permission, $actorPermissionNames, true)));

        if ($exceedingRoles->isEmpty()) {
            return;
        }

        $exceedingPermissions = $exceedingRoles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->reject(fn (string $permission) => in_array($permission, $actorPermissionNames, true))
            ->sort()
            ->values()
            ->implode(', ');

        throw ValidationException::withMessages([
            'role_ids' => __(
                'Cannot assign these roles: :roles grant permission(s) you do not hold yourself (:permissions). '.
                'You can only assign roles whose permissions do not exceed your own.',
                [
                    'roles' => $exceedingRoles->pluck('label')->implode(', '),
                    'permissions' => $exceedingPermissions,
                ],
            ),
        ]);
    }
}
