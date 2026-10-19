<?php

namespace App\Services;

use App\Http\Resources\Management\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginSession
{
    /**
     * @return array<string, mixed>
     */
    public static function complete(User $user, Request $request, string $method): array
    {
        Auth::login($user);
        $request->session()->regenerate();

        Log::channel('security')->info('Successful login', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'method' => $method,
        ]);

        return (new UserResource($user->load('roles.permissions', 'permissions', 'office')))
            ->additional(['message' => 'Success'])
            ->response()
            ->getData(true);
    }
}
