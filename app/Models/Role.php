<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A role is a named, admin-configurable bundle of permissions — never a
 * branch in application code. RolePermissionSeeder seeds four starting
 * roles (pending / viewer / encoder / division-ict-admin), but roles are
 * genuine RBAC data from here on: RoleController/RoleService let a
 * roles.manage holder create new roles, edit which permissions any role
 * grants, and delete roles that are no longer in use. See
 * App\Enums\Permission for the fixed catalog of granular strings roles
 * are composed from — that catalog stays code-defined; only the
 * role-to-permission bundling is dynamic.
 */
#[Fillable(['name', 'label', 'description'])]
class Role extends Model
{
    /**
     * The one role this application cannot function without a specific
     * identity for: Laravel\Fortify's CreateNewUser action hardcodes
     * `Role::where('name', self::PENDING)` as the zero-permission default
     * for every self-registration (deny-by-default — see that action's
     * doc-comment), and the account-approval workflow assumes this role
     * always exists and always grants nothing.
     *
     * Deliberately a single named-constant check, not a generic
     * `is_system` column: exactly one role has baked-in application
     * behavior tied to its identity today. 'viewer'/'encoder'/
     * 'division-ict-admin' are ordinary seeded starting data — editable
     * and deletable like any admin-created role once no user still holds
     * them (see RoleService::delete's "in use" guard, which applies to
     * every role uniformly, seeded or custom). If a second role ever
     * needs this same protection, that's the signal to promote this into
     * a real `is_protected` column instead of hardcoding a second name —
     * not before.
     *
     * RoleService enforces two invariants keyed off this constant:
     *   1. This role can never be deleted (RoleService::delete).
     *   2. This role's `name` can never be changed and its permission set
     *      can never become non-empty (RoleService::update /
     *      RoleUpdateRequest) — granting it any permission would silently
     *      hand every pending self-registration real access the moment
     *      they sign up, defeating deny-by-default.
     */
    public const PENDING = 'pending';

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Whether this role is the protected 'pending' role — see
     * self::PENDING's doc-comment for exactly what that protects against.
     */
    public function isProtected(): bool
    {
        return $this->name === self::PENDING;
    }
}
