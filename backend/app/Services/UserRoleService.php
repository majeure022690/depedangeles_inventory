<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Permission\Models\Permission;

/**
 * The only sanctioned entry point for the users.manage admin screen's
 * full CRUD — creating admin-provisioned accounts (createUser()),
 * updating profile + role set together (updateUser()), removing
 * accounts (deleteUser()), and changing just the role set (syncRoles(),
 * still called on its own by updateUser() and directly by earlier
 * callers). A user can hold several roles (Spatie's model_has_roles), so
 * syncRoles() replaces a user's entire role set in one call.
 *
 * Audit: account CRUD rows come from User's LogsActivity (attributed to
 * the actor via CauserResolver::withCauser()); every role-set change,
 * including the initial set on creation and the removal on deletion, is
 * a `user.roles_changed` activity.
 *
 * Enforces the following requirements from the 2026-07 security review,
 * generalized to multi-role semantics (and extended to createUser()/
 * deleteUser() below):
 *   1. Self-edits can't deactivate the actor or drop their own
 *      roles.manage/users.manage.
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
     * admin holds every permission in the system, so every
     * role is trivially a subset of their own — every role remains
     * assignable to them with no special-casing required, it falls
     * straight out of the subset rule.
     *
     * @return EloquentCollection<int, Role>
     */
    public function assignableRolesFor(User $actor): EloquentCollection
    {
        $actorPermissionNames = $this->permissionNamesOf($actor);

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
     * Target-tier guard: an actor may edit, deactivate, delete, or reset
     * 2FA only for an account whose effective permissions are a subset of
     * their own (the assignableRolesFor() rule applied to the target).
     * Without it a users.manage-only actor could change an admin's email,
     * request a password reset, reset their 2FA and take the account over.
     */
    public function canManage(User $actor, User $target): bool
    {
        return $this->idsExceedingActor($actor, [$target->id]) === [];
    }

    /**
     * Which of the given user ids hold any permission (via a role or
     * directly) the actor lacks. One query, so the users list can flag
     * every row without resolving each user's permission set.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public function idsExceedingActor(User $actor, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $actorPermissionNames = $this->permissionNamesOf($actor);
        $exceeds = fn ($query) => $query->whereNotIn('name', $actorPermissionNames);

        return User::query()
            ->whereKey($userIds)
            ->where(fn ($query) => $query->whereHas('roles.permissions', $exceeds)->orWhereHas('permissions', $exceeds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Admin reset for a lost authenticator: the user enrolls again on
     * their next login. Clearing the secret also revokes trusted devices
     * and live sessions (User::booted()).
     */
    public function resetTwoFactor(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new AuthorizationException(__('You cannot reset your own two-factor authentication.'));
        }

        $this->guardTargetWithinTier($actor, $target);

        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity()
            ->causedBy($actor)
            ->performedOn($target)
            ->event('user.two_factor_reset')
            ->log('user.two_factor_reset');
    }

    /**
     * Creates a new account (accounts are admin-created; there is no
     * self-registration) and assigns its initial role set in one step.
     * Subject to the same permission-tier guard as syncRoles(): an actor
     * can only hand out roles whose entire permission set is already a
     * subset of their own.
     *
     * @param  array{name: string, email: string, password: string, office_id?: int|null, is_active?: bool}  $attributes
     * @param  array<int, int>  $roleIds
     */
    public function createUser(User $actor, array $attributes, array $roleIds): User
    {
        $this->guardAgainstPermissionTierViolation($actor, $roleIds);

        $roleIds = $this->normalizeRoleIds($roleIds);

        return DB::transaction(fn () => app(CauserResolver::class)->withCauser($actor, function () use ($actor, $attributes, $roleIds) {
            $user = User::create($attributes);

            if ($roleIds !== []) {
                $user->syncRoles($roleIds);
                $this->recordRolesChanged($actor, $user, [], $roleIds);
            }

            return $user;
        }));
    }

    /**
     * @param  array{name: string, email: string, office_id?: int|null, is_active?: bool}  $attributes
     * @param  array<int, int>  $roleIds
     */
    public function updateUser(User $actor, User $target, array $attributes, array $roleIds): void
    {
        if ($actor->is($target) && array_key_exists('is_active', $attributes) && ! $attributes['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => __("You can't deactivate your own account."),
            ]);
        }

        $this->guardTargetWithinTier($actor, $target);

        DB::transaction(function () use ($actor, $target, $attributes, $roleIds): void {
            app(CauserResolver::class)->withCauser($actor, fn () => $target->fill($attributes)->save());

            $this->syncRoles($actor, $target, $roleIds);
        });
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
     * (equipment_transactions.recorded_by_user_id,
     * isp_speed_tests/isp_subscription_costs.recorded_by_user_id) is
     * nullable + nullOnDelete, and activity_log.causer_id has no FK at all
     * (plus the actor_snapshot), so deleting an account never cascades
     * away the business records or audit history it was attached to.
     */
    public function deleteUser(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot delete your own account.'),
            ]);
        }

        $this->guardTargetWithinTier($actor, $target);

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

            $previousRoleIds = $this->normalizeRoleIds($target->roles()->pluck('roles.id')->all());

            if ($previousRoleIds !== []) {
                $this->recordRolesChanged($actor, $target, $previousRoleIds, []);
            }

            app(CauserResolver::class)->withCauser($actor, fn () => $target->delete());
        });
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(User $actor, User $target, array $roleIds): void
    {
        $this->guardTargetWithinTier($actor, $target);

        $roleIds = $this->normalizeRoleIds($roleIds);

        $previousRoleIds = $this->normalizeRoleIds($target->roles()->pluck('roles.id')->all());

        if ($previousRoleIds === $roleIds) {
            return; // No actual change — nothing to enforce, sync, or audit.
        }

        $this->guardAgainstPermissionTierViolation($actor, $roleIds);

        DB::transaction(function () use ($actor, $target, $roleIds, $previousRoleIds) {
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

            if ($actor->is($target)) {
                $this->guardAgainstSelfLockout($target, $roleIds);
            }

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

            $target->syncRoles($roleIds);

            $this->recordRolesChanged($actor, $target, $previousRoleIds, $roleIds);
        });
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function guardAgainstSelfLockout(User $self, array $roleIds): void
    {
        foreach ([PermissionEnum::UsersManage, PermissionEnum::RolesManage] as $permission) {
            if (! $self->hasPermissionTo($permission) || $self->hasDirectPermission($permission)) {
                continue;
            }

            $newRolesGrant = Role::query()
                ->whereIn('id', $roleIds)
                ->whereHas('permissions', fn ($query) => $query->where('name', $permission->value))
                ->exists();

            if (! $newRolesGrant) {
                throw ValidationException::withMessages([
                    'role_ids' => __(
                        'You cannot remove :permission from your own account — this change would lock you out of it. '.
                        'Have another :permission holder make this change instead.',
                        ['permission' => $permission->value],
                    ),
                ]);
            }
        }
    }

    /**
     * @param  array<int, int>  $previousRoleIds
     * @param  array<int, int>  $newRoleIds
     */
    private function recordRolesChanged(User $actor, User $target, array $previousRoleIds, array $newRoleIds): void
    {
        $roleNames = fn (array $ids) => Role::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        activity()
            ->causedBy($actor)
            ->performedOn($target)
            ->event('user.roles_changed')
            ->withProperties([
                'target_user_id' => $target->id,
                'target_user_email' => $target->email,
                'previous_role_ids' => $previousRoleIds,
                'new_role_ids' => $newRoleIds,
                'previous_roles' => $roleNames($previousRoleIds),
                'new_roles' => $roleNames($newRoleIds),
            ])
            ->log('user.roles_changed');
    }

    /**
     * @param  array<int, int|string>  $roleIds
     * @return array<int, int>
     */
    private function normalizeRoleIds(array $roleIds): array
    {
        return collect($roleIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * The actor's effective permission names, via roles (and any direct
     * grant, though inventory never makes one).
     *
     * @return array<int, string>
     */
    private function permissionNamesOf(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->all();
    }

    /**
     * Backstop for UserPolicy's target-tier check, for callers that
     * bypass the Policy.
     */
    private function guardTargetWithinTier(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException(__('You cannot manage an account that holds permissions you do not hold yourself.'));
        }
    }

    /**
     * Permission-tier separation guard (CRITICAL, 2026-07 follow-up
     * review): a users.manage holder may only assign roles whose ENTIRE
     * permission set is already a subset of their own effective
     * permissions. Permission::RolesManage's own doc-comment already
     * states a users.manage holder should not automatically be able to
     * redefine what every role means — this closes the gap where nothing
     * actually enforced that on the assignment side, letting a
     * users.manage-only actor hand any account (including their own)
     * a role granting
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

        $actorPermissionNames = $this->permissionNamesOf($actor);

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
