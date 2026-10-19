<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\ValidationException;

class SecuritySettings
{
    private const SETTINGS = [
        'login_rate_limit_per_minute' => [
            'value' => 5,
            'label' => 'Login attempts per minute',
            'description' => 'Maximum login requests allowed for one email address and IP address per minute.',
            'min' => 1,
            'max' => 20,
        ],
        'password_reset_rate_limit_per_hour' => [
            'value' => 3,
            'label' => 'Password reset requests per hour',
            'description' => 'Maximum forgot-password requests allowed for one email address and IP address per hour.',
            'min' => 1,
            'max' => 10,
        ],
        'two_factor_verify_rate_limit_per_minute' => [
            'value' => 5,
            'label' => 'Two-factor code attempts per minute',
            'description' => 'Maximum authenticator code attempts allowed for one login session and IP address per minute.',
            'min' => 1,
            'max' => 20,
        ],
        'trusted_device_days' => [
            'value' => 30,
            'label' => 'Trusted device days',
            'description' => 'How many days "Trust this device" skips the authenticator code at sign-in. Set to 0 to turn the option off. Users who can manage roles or users always need the code.',
            'min' => 0,
            'max' => 30,
        ],
        'idle_timeout_seconds' => [
            'value' => 900,
            'label' => 'Idle sign-out (seconds)',
            'description' => 'Sign staff out of the portal after this many seconds without mouse, keyboard, scroll or touch activity in any open portal tab. A countdown warning appears first. Minimum 10 seconds; 900 is 15 minutes.',
            'min' => 10,
            'max' => 7200,
        ],
    ];

    /**
     * @return array<int, array{key: string, value: int, label: string, description: string, min: int, max: int}>
     */
    public static function all(): array
    {
        return collect(self::SETTINGS)->map(function (array $setting, string $key): array {
            return [
                'key' => $key,
                'value' => (int) SystemSetting::get($key, $setting['value']),
                'label' => $setting['label'],
                'description' => $setting['description'],
                'min' => $setting['min'],
                'max' => $setting['max'],
            ];
        })->values()->all();
    }

    public static function get(string $key): int
    {
        return (int) SystemSetting::get($key, self::SETTINGS[$key]['value']);
    }

    /**
     * @return array{key: string, value: int, label: string, description: string, min: int, max: int}
     */
    public static function update(string $key, int $value): array
    {
        if (! isset(self::SETTINGS[$key])) {
            throw ValidationException::withMessages(['setting_key' => 'Unknown security setting.']);
        }

        $setting = self::SETTINGS[$key];
        if ($value < $setting['min'] || $value > $setting['max']) {
            throw ValidationException::withMessages([
                'value' => ["{$setting['label']} must be between {$setting['min']} and {$setting['max']}."],
            ]);
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => (string) $value,
                'type' => 'integer',
                'description' => $setting['description'],
            ],
        );

        return collect(self::all())->firstWhere('key', $key);
    }
}
