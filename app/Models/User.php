<?php

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Per-request memoized flat permission list. Never read directly —
     * go through permissionNames()/hasPermissionTo() so every caller
     * benefits from the cache regardless of entry point.
     *
     * @var array<int, string>|null
     */
    private ?array $cachedPermissionNames = null;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return HasMany<TwoFactorTrustedDevice, $this>
     */
    public function twoFactorTrustedDevices(): HasMany
    {
        return $this->hasMany(TwoFactorTrustedDevice::class);
    }

    /**
     * Flat, de-duplicated list of permission strings granted by every
     * role this user holds. Memoized per-request/instance so repeated
     * `$user->can(...)`/`hasPermissionTo(...)` calls in the same request
     * don't re-run the join.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->cachedPermissionNames ??= $this->roles()
            ->with('permissions:id,name')
            ->get(['roles.id'])
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The one true authorization check in this application: does this
     * user hold a role granting this granular permission? Every Policy
     * method and Gate::before ultimately bottoms out here — never a role
     * name comparison.
     */
    public function hasPermissionTo(PermissionEnum|string $permission): bool
    {
        $name = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return in_array($name, $this->permissionNames(), true);
    }

    /**
     * Grant this user a role. Idempotent (attaching an already-held role
     * is a no-op) and always audited — every privilege change is a
     * logged event, per this application's authorization decision rules.
     */
    public function assignRole(Role|string $role): void
    {
        $role = $role instanceof Role ? $role : Role::where('name', $role)->firstOrFail();

        if ($this->roles()->where('roles.id', $role->id)->exists()) {
            return;
        }

        $this->roles()->attach($role);
        $this->forgetCachedPermissions();

        AuditLog::record('role.assigned', $this, ['role' => $role->name]);
    }

    /**
     * Revoke a role from this user. Always audited, mirroring
     * assignRole().
     */
    public function removeRole(Role|string $role): void
    {
        $role = $role instanceof Role ? $role : Role::where('name', $role)->firstOrFail();

        $this->roles()->detach($role);
        $this->forgetCachedPermissions();

        AuditLog::record('role.revoked', $this, ['role' => $role->name]);
    }

    /**
     * Clears the per-request memoized permission list so the next
     * permissionNames()/hasPermissionTo() call re-derives it from the
     * roles table. Needed by any code path that mutates this user's
     * role_user rows on an already-loaded instance — assignRole()/
     * removeRole() above, and UserRoleService::syncRoles(), which
     * replaces the whole role set in one call rather than attach/detach.
     */
    public function forgetCachedPermissions(): void
    {
        $this->cachedPermissionNames = null;
    }
}
