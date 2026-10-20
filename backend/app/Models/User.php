<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Notifications\ResetPasswordNotification;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $password
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $office_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
     * Personnel whose records this user's data scope reaches: their own
     * record (DepEd email = account email) or everyone at their office.
     * Null means no restriction.
     *
     * @return list<int>|null
     */
    public function scopedPersonnelIds(): ?array
    {
        $scope = $this->dataScope();

        if ($scope === DataScope::All) {
            return null;
        }

        return $this->scopedPersonnelIds ??= Personnel::query()
            ->when(
                $scope === DataScope::School,
                fn ($query) => $query->where('office_id', $this->office_id ?? 0),
                fn ($query) => $query->whereRaw('lower(deped_email) = ?', [mb_strtolower($this->email)]),
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'avatar_path', 'office_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
