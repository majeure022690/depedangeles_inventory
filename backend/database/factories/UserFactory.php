<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Confirmed 2FA enrollment in the rwatch encoding (`encrypted` secret,
     * `encrypted:array` of bcrypt hashes of upper-cased recovery codes).
     * Written straight to the row after creation so the stored format is
     * right whether or not the User model has those casts yet. Pass a known
     * secret/codes when the test needs to produce a TOTP or use a code.
     *
     * @param  list<string>|null  $recoveryCodes
     */
    public function twoFactorConfirmed(?string $secret = null, ?array $recoveryCodes = null): static
    {
        return $this->afterCreating(function (User $user) use ($secret, $recoveryCodes) {
            $secret ??= app(Google2FA::class)->generateSecretKey();
            $recoveryCodes ??= array_map(fn () => strtoupper(bin2hex(random_bytes(4))), range(1, 8));

            $columns = [
                'two_factor_secret' => Crypt::encryptString($secret),
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(
                    array_map(fn (string $code) => Hash::make(strtoupper($code)), $recoveryCodes),
                    JSON_THROW_ON_ERROR,
                )),
                'two_factor_confirmed_at' => now(),
            ];

            DB::table('users')->where('id', $user->id)->update($columns);
            $user->setRawAttributes(array_merge($user->getAttributes(), $columns), true);
        });
    }

    /**
     * Fortify-encoded 2FA, used only by the Fortify tests Phase 2 deletes.
     * Remove with them; new tests use twoFactorConfirmed().
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
