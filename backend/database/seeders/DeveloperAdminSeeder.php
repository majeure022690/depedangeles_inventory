<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ExternalUserAuthService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The seeded super administrator. Never sets a known password: PRIME is the
 * only password authority. An allowlisted (LOCAL_LOGIN_EMAILS) developer
 * sets a local break-glass password with `users:set-password`, which
 * re-seeding leaves alone; outside the allowlist (production) any local
 * password is removed. Depends on RolePermissionSeeder.
 */
class DeveloperAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public const EMAIL = 'keithvpolintan@gmail.com';

    public function run(): void
    {
        // firstOrCreate() merges $values over $attributes on key collision,
        // so 'email' must be repeated here too — otherwise raw()'s random
        // fake email silently wins. 'password' overrides UserFactory's
        // weak default.
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            fn () => User::factory()->raw([
                'name' => 'Test User',
                'email' => self::EMAIL,
                'password' => null,
            ]),
        );

        $localLogin = ExternalUserAuthService::allowsLocalPassword(self::EMAIL);

        if (! $localLogin && $user->password !== null) {
            $user->forceFill(['password' => null])->save();
        }

        $user->syncRoles(['super_administrator']);

        if ($localLogin && $user->password === null) {
            $this->command?->info('Set a local break-glass password with: php artisan users:set-password '.self::EMAIL);
        }
    }
}
