<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\TrustedDevice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class TrustedDeviceService
{
    public const COOKIE = 'dictis_trusted_device';

    /** @var list<Permission> */
    public const ALWAYS_REQUIRE_CODE = [
        Permission::RolesCreate,
        Permission::RolesEdit,
        Permission::RolesDelete,
        Permission::UsersEdit,
        Permission::UsersResetTwoFactor,
        Permission::SecuritySettingsManage,
        Permission::AuditLogView,
    ];

    public function days(User $user): int
    {
        $days = SecuritySettings::get('trusted_device_days');

        if ($days <= 0 || $user->hasAnyPermission(array_map(fn (Permission $p): string => $p->value, self::ALWAYS_REQUIRE_CODE))) {
            return 0;
        }

        return $days;
    }

    public function findValid(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);
        $days = $this->days($user);

        if (! is_string($token) || $token === '' || $days === 0) {
            return null;
        }

        return $this->honouredQuery($user, $days)->where('token_hash', hash('sha256', $token))->first();
    }

    public function isHonoured(TrustedDevice $device, int $days): bool
    {
        return $days > 0
            && $device->expires_at->isFuture()
            && $device->created_at->greaterThan(now()->subDays($days));
    }

    public function expiresAt(TrustedDevice $device, int $days): CarbonInterface
    {
        if (! $this->isHonoured($device, $days)) {
            return $device->expires_at;
        }

        $cap = $device->created_at->copy()->addDays($days);

        return $device->expires_at->lessThan($cap) ? $device->expires_at : $cap;
    }

    /**
     * @return HasMany<TrustedDevice, User>
     */
    private function honouredQuery(User $user, int $days): HasMany
    {
        return $user->trustedDevices()
            ->where('expires_at', '>', now())
            ->where('created_at', '>', now()->subDays($days));
    }

    public function current(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && $token !== ''
            ? $user->trustedDevices()->where('token_hash', hash('sha256', $token))->first()
            : null;
    }

    public function trust(User $user, Request $request): Cookie
    {
        $days = $this->days($user);
        $token = Str::random(64);

        $user->trustedDevices()->where('expires_at', '<=', now())->delete();
        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
            'last_used_ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        return $this->cookie($token, $days * 24 * 60);
    }

    public function touch(TrustedDevice $device, Request $request): void
    {
        $device->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
    }

    public function forgetCookie(): Cookie
    {
        return $this->cookie('', -2628000);
    }

    private function cookie(string $value, int $minutes): Cookie
    {
        return cookie(
            self::COOKIE,
            $value,
            $minutes,
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }
}
