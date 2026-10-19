<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class TwoFactorTrustedDevice extends Model
{
    /**
     * How long a "remember this device" cookie skips the 2FA challenge for.
     */
    private const TRUST_DAYS = 30;

    public const COOKIE_NAME = 'two_factor_remember';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Trust the current device for the given user: persists a hashed token
     * and queues the matching plaintext cookie (Laravel encrypts/decrypts it
     * transparently via the EncryptCookies middleware already on the `web`
     * group, so nothing here handles encryption directly).
     */
    public static function remember(User $user, Request $request): void
    {
        $token = Str::random(60);

        $user->twoFactorTrustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::TRUST_DAYS),
        ]);

        $minutes = self::TRUST_DAYS * 24 * 60;

        Cookie::queue(Cookie::make(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: $minutes,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: config('session.same_site'),
        ));
    }

    /**
     * Whether the request's trusted-device cookie (if any) matches a
     * non-expired record for this specific user.
     */
    public static function isTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE_NAME);

        if (! is_string($token) || $token === '') {
            return false;
        }

        return $user->twoFactorTrustedDevices()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->exists();
    }
}
