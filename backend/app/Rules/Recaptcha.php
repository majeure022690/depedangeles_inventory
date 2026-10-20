<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;

class Recaptcha implements ValidationRule
{
    // Google's published test secret: every token passes, so it must never guard production.
    public const TEST_SECRET_KEY = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly string $action) {}

    /**
     * @param  string  $action  The action name the frontend passes to grecaptcha.execute().
     * @return array<int, mixed>
     */
    public static function rules(string $action): array
    {
        if (app()->environment('testing')) {
            return ['nullable', 'string'];
        }

        return ['required', 'string', new self($action)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('services.recaptcha.secret_key');
        $production = app()->environment('production');

        if (! $secret || $secret === self::TEST_SECRET_KEY) {
            if ($production) {
                $fail('CAPTCHA verification is not configured. Please try again later.');
            }

            return;
        }

        try {
            // The production host's IPv6 route stalls; IPv4 answers immediately.
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withOptions(['force_ip_resolve' => 'v4'])->asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $value,
                'remoteip' => Request::ip(),
            ]);
        } catch (ConnectionException) {
            $fail('CAPTCHA verification is unavailable right now. Please try again.');

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $fail('Please complete the CAPTCHA and try again.');

            return;
        }

        // A token solved for another form or another site must not be replayed here.
        $action = $response->json('action');
        $hostname = $response->json('hostname');
        $expectedHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

        if (($action !== null && $action !== $this->action) || ($production && $hostname !== null && $hostname !== $expectedHost)) {
            $fail('Your request was flagged as suspicious. Please try again.');

            return;
        }

        // v3 returns a score (0.0 = bot, 1.0 = human) — reject below 0.5
        $score = $response->json('score');
        if ($score !== null && $score < 0.5) {
            $fail('Your request was flagged as suspicious. Please try again.');
        }
    }
}
