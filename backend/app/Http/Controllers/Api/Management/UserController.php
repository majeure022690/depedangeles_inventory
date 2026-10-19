<?php

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ResetTwoFactorRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Admin reset for a lost authenticator: the user enrolls again on
     * their next login (2FA is mandatory). Clearing the secret also
     * revokes their trusted devices (User::booted()).
     */
    public function resetTwoFactor(ResetTwoFactorRequest $request, User $user): JsonResponse
    {
        $this->authorize('resetTwoFactor', $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity()
            ->performedOn($user)
            ->event('user.two_factor_reset')
            ->log('user.two_factor_reset');

        Log::channel('security')->info('Two-factor authentication reset by admin', [
            'user_id' => $user->id,
            'admin_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Two-factor authentication has been reset for this user.']);
    }
}
