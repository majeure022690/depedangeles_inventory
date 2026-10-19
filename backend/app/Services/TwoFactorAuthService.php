<?php

namespace App\Services;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthService
{
    private const ISSUER = 'DICTIS';

    private const WINDOW = 1;

    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function getQrCodeDataUri(string $label, string $secret): string
    {
        $otpauthUrl = $this->google2fa->getQRCodeUrl(self::ISSUER, $label, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(200, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd
        );

        $svg = (new Writer($renderer))->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code, self::WINDOW) === true;
    }

    public function verifyCodeOnce(int $userId, string $secret, string $code): bool
    {
        $key = "2fa_last_timestep:{$userId}";

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, (int) Cache::get($key, 0), self::WINDOW);

        if (! is_int($timestep)) {
            return false;
        }

        Cache::put($key, $timestep, now()->addMinutes(5));

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn ($code) => Hash::make($code), $codes);
    }

    /**
     * @param  array<int, string>  $hashedCodes
     */
    public function verifyRecoveryCode(string $code, array $hashedCodes): ?int
    {
        foreach ($hashedCodes as $index => $hashedCode) {
            if (Hash::check(strtoupper($code), $hashedCode)) {
                return $index;
            }
        }

        return null;
    }
}
