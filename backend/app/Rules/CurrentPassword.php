<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Services\ExternalUserAuthService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Password re-confirmation, mirroring login: PRIME first. The local IRIS
 * password is checked only for an allowlisted developer account
 * (`auth.local_login_emails`) and only when PRIME is unreachable or doesn't
 * know the email; a PRIME refusal never falls back.
 */
final class CurrentPassword implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->user || ! is_string($value) || $value === '') {
            $fail('validation.current_password')->translate();

            return;
        }

        $localAllowed = ExternalUserAuthService::allowsLocalPassword($this->user->email);
        $localReason = null;

        try {
            $verdict = app(ExternalUserAuthService::class)->checkPassword($this->user->email, $value);
        } catch (Throwable $e) {
            if (! ExternalUserAuthService::isUnreachable($e)) {
                $event = ExternalUserAuthService::isQueryError($e) ? 'PRIME query failed; refusing' : 'PRIME password confirmation failed unexpectedly; refusing';
                Log::channel('security')->error($event, [
                    'user_id' => $this->user->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
                $fail('validation.current_password')->translate();

                return;
            }

            $localReason = ExternalUserAuthService::outageReason();
            Log::warning('PRIME unavailable for password confirmation', ['error' => $e->getMessage(), 'reason' => $localReason]);

            if (! $localAllowed) {
                $fail(__("Your password can't be checked right now because PRIME is unavailable. Please try again later."));

                return;
            }

            $verdict = null;
        }

        if ($verdict === null && $localAllowed) {
            Log::channel('security')->warning('Local break-glass password confirmation', [
                'user_id' => $this->user->id,
                'reason' => $localReason ?? 'not_in_prime',
            ]);
            $verdict = Hash::check($value, (string) $this->user->getAuthPassword());
        }

        if ($verdict !== true) {
            $fail('validation.current_password')->translate();
        }
    }
}
