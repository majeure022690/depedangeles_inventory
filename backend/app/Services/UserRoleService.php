<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataScope;
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
 * The only entry point for changing an account or its role set.
 * syncRoles() replaces a user's entire role set in one call.
 *
 * Audit: account rows come from User's LogsActivity (attributed to the
 * actor via CauserResolver::withCauser()); every role-set change is a
 * `user.roles_changed` activity.
 *
 * Invariants:
 *   1. Self-edits can't deactivate the actor or drop their own
 *      roles.edit/users.edit.
 *   2. Tier separation: an actor can assign only roles, and manage only
 *      accounts, whose permissions are a subset of their own and whose
 *      data scope is no wider than their own.
 *   3. The last remaining users.edit holder can never lose it.
 */
final class UserRoleService
{
    /**
     * Every role whose permission set is a subset of the actor's own and
     * whose data scope is no wider than theirs: the read-side mirror of
     * guardAgainstPermissionTierViolation(), used by UserController::options().
     *
     * @return EloquentCollection<int, Role>
     */
    public function assignableRolesFor(User $actor): EloquentCollection
    {
        $actorPermissionNames = $this->permissionNamesOf($actor);
        $actorScopeRank = $actor->dataScope()->rank();

        return Role::query()
            ->orderBy('label')
            ->with('permissions:id,name')
            ->get(['id', 'name', 'label', 'data_scope'])
            ->filter(fn (Role $role) => $role->data_scope->rank() <= $actorScopeRank
                && $role->permissions
                    ->pluck('name')
                    ->every(fn (string $permission) => in_array($permission, $actorPermissionNames, true)))
            ->values();
    }

    /**
     * Target-tier guard: an actor may edit, deactivate, delete, or reset
     * 2FA only for an account whose effective permissions are a subset of
     * their own and whose data scope is no wider than theirs (the
     * assignableRolesFor() rule applied to the target).
     * Without it a users.edit or users.reset_two_factor holder could reset
     * an admin's 2FA and take the account over.
     */
    public function canManage(User $actor, User $target): bool
    {
        return $this->idsExceedingActor($actor, [$target->id]) === [];
    }

    /**
     * Which of the given user ids hold any permission (via a role or
     * directly) the actor lacks, or a role scoped wider than the actor's
     * data scope. One query, so the users list can flag every row without
     * resolving each user's permission set.
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
        $widerScopes = $this->scopeValuesWiderThan($actor->dataScope());

        return User::query()
            ->whereKey($userIds)
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', $exceeds)
                ->orWhereHas('permissions', $exceeds)
                ->when($widerScopes !== [], fn ($query) => $query->orWhereHas('roles', fn ($roles) => $roles->whereIn('data_scope', $widerScopes))))
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
     * @param  array{name?: string, email?: string, office_id?: int|null, is_active?: bool}  $attributes
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
     * Deletes a user account. An actor can never delete their own account
     * or the last remaining users.edit holder (under the same row lock
     * syncRoles() takes).
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
            Permission::where('name', PermissionEnum::UsersEdit->value)->lockForUpdate()->first();

            if ($target->hasPermissionTo(PermissionEnum::UsersEdit)) {
                $remainingUsersEditHolders = User::query()
                    ->whereKeyNot($target->id)
                    ->whereHas('roles.permissions', fn ($query) => $query->where('name', PermissionEnum::UsersEdit->value))
                    ->count();

                if ($remainingUsersEditHolders === 0) {
                    throw ValidationException::withMessages([
                        'user' => __(
                            'Cannot delete :name: they are the only remaining user who can edit users. '.
                            'Assign another user a role that can edit users first.',
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
            // Serializes concurrent removals of the last users.edit holder
            // (shared with deleteUser() and RoleService::guardAgainstLockout()).
            Permission::where('name', PermissionEnum::UsersEdit->value)->lockForUpdate()->first();

            if ($actor->is($target)) {
                $this->guardAgainstSelfLockout($target, $roleIds);
            }

            $targetCurrentlyGrantsUsersEdit = $target->hasPermissionTo(PermissionEnum::UsersEdit);

            $newRolesGrantUsersEdit = Role::query()
                ->whereIn('id', $roleIds)
                ->whereHas('permissions', fn ($query) => $query->where('name', PermissionEnum::UsersEdit->value))
                ->exists();

            if ($targetCurrentlyGrantsUsersEdit && ! $newRolesGrantUsersEdit) {
                $remainingUsersEditHolders = User::query()
                    ->whereKeyNot($target->id)
                    ->whereHas('roles.permissions', fn ($query) => $query->where('name', PermissionEnum::UsersEdit->value))
                    ->count();

                if ($remainingUsersEditHolders === 0) {
                    throw ValidationException::withMessages([
                        'role_id' => __(
                            'Cannot change these roles: :name is the only remaining user who can edit users. '.
                            'Assign another user a role that can edit users first.',
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
        foreach ([PermissionEnum::UsersEdit, PermissionEnum::RolesEdit] as $permission) {
            if (! $self->hasPermissionTo($permission) || $self->hasDirectPermission($permission)) {
                continue;
            }

            $newRolesGrant = Role::query()
                ->whereIn('id', $roleIds)
                ->whereHas('permissions', fn ($query) => $query->where('name', $permission->value))
                ->exists();

            if (! $newRolesGrant) {
                throw ValidationException::withMessages([
                    'role_id' => __(
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
            throw new AuthorizationException(__('You cannot manage an account that holds permissions or a data scope you do not hold yourself.'));
        }
    }

    /**
     * @return array<int, string>
     */
    private function scopeValuesWiderThan(DataScope $scope): array
    {
        return collect(DataScope::cases())
            ->filter(fn (DataScope $case) => $case->rank() > $scope->rank())
            ->map(fn (DataScope $case) => $case->value)
            ->values()
            ->all();
    }

    /**
     * An actor may only assign roles whose entire permission set is a
     * subset of their own and whose data scope is no wider than theirs.
     * Checked against the full resulting role set, not just newly added
     * roles.
     *
     * @param  array<int, int>  $roleIds
     */
    private function guardAgainstPermissionTierViolation(User $actor, array $roleIds): void
    {
        if ($roleIds === []) {
            return; // Stripping every role can never grant anything.
        }

        $actorPermissionNames = $this->permissionNamesOf($actor);
        $actorScope = $actor->dataScope();

        $roles = Role::query()
            ->whereIn('id', $roleIds)
            ->with('permissions:id,name')
            ->get(['id', 'name', 'label', 'data_scope']);

        $widerScopedRoles = $roles->filter(fn (Role $role) => $role->data_scope->rank() > $actorScope->rank());

        if ($widerScopedRoles->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role_id' => __(
                    'Cannot assign these roles: :roles have a data scope wider than your own (:scope). '.
                    'You can only assign roles whose data scope does not exceed your own.',
                    [
                        'roles' => $widerScopedRoles->pluck('label')->implode(', '),
                        'scope' => $actorScope->value,
                    ],
                ),
            ]);
        }

        $exceedingRoles = $roles
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
            'role_id' => __(
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
