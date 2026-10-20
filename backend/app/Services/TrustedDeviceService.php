<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class TrustedDeviceService
{
    public const COOKIE = 'dictis_trusted_device';

    public function days(User $user): int
    {
        $days = SecuritySettings::get('trusted_device_days');

        // Holders of the permissions that change anyone's access always
        // need the code.
        if ($days <= 0 || $user->hasAnyPermission([
            Permission::RolesCreate->value,
            Permission::RolesEdit->value,
            Permission::RolesDelete->value,
            Permission::UsersEdit->value,
            Permission::UsersResetTwoFactor->value,
        ])) {
            return 0;
        }

        return $days;
    }

    public function findValid(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '' || $this->days($user) === 0) {
            return null;
        }

        return $user->trustedDevices()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
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
