<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExternalUserAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetUserPassword extends Command
{
    protected $signature = 'users:set-password {email : Email address of a developer break-glass account (LOCAL_LOGIN_EMAILS)}';

    protected $description = 'Set the local password of a developer break-glass account';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No account with that email address.');

            return self::FAILURE;
        }

        if (! ExternalUserAuthService::allowsLocalPassword($user->email)) {
            $this->error('Only developer break-glass accounts (LOCAL_LOGIN_EMAILS) have a local password. Everyone else changes their password in PRIME.');

            return self::FAILURE;
        }

        $input = [
            'password' => (string) $this->secret('New password'),
            'password_confirmation' => (string) $this->secret('Confirm new password'),
        ];

        $validator = Validator::make($input, ['password' => ['required', 'confirmed', Password::defaults()]]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill(['password' => $input['password']])->save();

        $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();

        activity()
            ->performedOn($user)
            ->byAnonymous()
            ->event('user.password_set')
            ->withProperties(['via' => 'console', 'sessions_revoked' => $sessions])
            ->log('user.password_set');

        Log::channel('security')->info('Password set from console', ['user_id' => $user->id, 'sessions_revoked' => $sessions]);

        $this->info("Password updated for {$user->email}. Signed out of {$sessions} session(s); trusted devices revoked.");

        return self::SUCCESS;
    }
}
