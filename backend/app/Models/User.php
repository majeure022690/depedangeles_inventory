<?php

namespace App\Models;

use App\Enums\DataScope;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path
 * @property bool $is_active
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $office_id
 * @property string|null $employee_no
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EloquentCollection<int, Role> $roles
 * @property-read Personnel|null $personnel
 */
#[Fillable(['name', 'email', 'password', 'office_id', 'avatar_path', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Spatie guard for roles/permissions. Pinned because `auth:sanctum`
     * makes `sanctum` the default guard mid-request.
     */
    protected string $guard_name = 'web';

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            $deactivated = $user->wasChanged('is_active') && ! $user->is_active;

            // 'password' is only the local break-glass password: a password change made in PRIME never reaches this hook.
            if ($user->wasChanged(['password', 'two_factor_secret']) || $deactivated) {
                $user->trustedDevices()->delete();
            }

            // Deactivation and an admin 2FA reset end every live session
            // (database driver); EnsureUserIsActive is the backstop.
            if ($deactivated || ($user->wasChanged('two_factor_secret') && $user->two_factor_secret === null)) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
    }

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
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @var list<int>|null */
    private ?array $scopedPersonnelIds = null;

    /**
     * The widest scope among the user's roles; Own when they have none.
     */
    public function dataScope(): DataScope
    {
        return $this->roles
            ->map(fn (Role $role) => $role->data_scope)
            ->sortByDesc(fn (DataScope $scope) => $scope->rank())
            ->first() ?? DataScope::Own;
    }

    /**
     * @return HasOne<Personnel, $this>
     */
    public function personnel(): HasOne
    {
        return $this->hasOne(Personnel::class, 'employee_id', 'employee_no');
    }

    /**
     * Personnel this user's data scope reaches. Null means no restriction.
     *
     * @return list<int>|null
     */
    public function scopedPersonnelIds(): ?array
    {
        $scope = $this->dataScope();

        if ($scope === DataScope::All) {
            return null;
        }

        $employeeNo = filled($this->employee_no) ? $this->employee_no : null;
        $officeId = $scope === DataScope::School ? $this->office_id : null;

        if ($employeeNo === null && $officeId === null) {
            return $this->scopedPersonnelIds ??= [];
        }

        return $this->scopedPersonnelIds ??= array_values(Personnel::query()
            ->where(function (Builder $query) use ($employeeNo, $officeId) {
                if ($employeeNo !== null) {
                    $query->where('employee_id', $employeeNo);
                }

                if ($officeId !== null) {
                    $query->orWhere('office_id', $officeId);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    public function reachesOffice(?int $officeId): bool
    {
        if ($this->dataScope() === DataScope::All) {
            return true;
        }

        return $officeId !== null && $this->office_id !== null && $officeId === $this->office_id;
    }

    /**
     * Accounts the actor's data scope reaches: everyone for All, otherwise
     * the actor's own office, or only the actor when they have no office.
     *
     * @param  Builder<User>  $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if ($actor->dataScope() === DataScope::All) {
            return;
        }

        if ($actor->office_id === null) {
            $query->whereKey($actor->id);

            return;
        }

        $query->where($query->qualifyColumn('office_id'), $actor->office_id);
    }

    /**
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function hasAppAccess(): bool
    {
        return $this->getAllPermissions()->isNotEmpty();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'avatar_path', 'office_id', 'employee_no', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
