<?php

namespace App\Actions\Fortify;

use App\Models\TwoFactorTrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as BaseTwoFactorLoginResponse;

/**
 * Same as Fortify's stock response, except it also persists a "remember
 * this device" trusted-device cookie when the challenge form's checkbox
 * was checked (see TwoFactorTrustedDevice).
 */
class TwoFactorLoginResponse extends BaseTwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        $user = Auth::user();

        if ($user instanceof User && $request->boolean('remember_device')) {
            TwoFactorTrustedDevice::remember($user, $request);
        }

        return parent::toResponse($request);
    }
}
