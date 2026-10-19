<?php

namespace App\Actions\Fortify;

use App\Models\TwoFactorTrustedDevice;
use App\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as BaseRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Same as Fortify's stock action, except a request carrying a valid
 * "remember this device" cookie (see TwoFactorTrustedDevice) skips the
 * challenge screen entirely instead of always redirecting to it.
 */
class RedirectIfTwoFactorAuthenticatable extends BaseRedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        $usesTwoFactor = optional($user)->two_factor_secret
            && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user));

        $requiresChallenge = $usesTwoFactor && (
            ! Fortify::confirmsTwoFactorAuthentication() || ! is_null($user->two_factor_confirmed_at)
        );

        if ($requiresChallenge && $user instanceof User && TwoFactorTrustedDevice::isTrusted($user, $request)) {
            $requiresChallenge = false;
        }

        if ($requiresChallenge) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
