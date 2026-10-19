<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Re-encodes Fortify's 2FA columns into the format rwatch's User casts and
 * TwoFactorAuthService read (decoupled-spa-stack-plan.md, Phase 1 #8):
 *
 * - two_factor_secret: Fortify `encrypt()` (serialized) -> `encrypted` cast
 *   (Crypt::encryptString, unserialized).
 * - two_factor_recovery_codes: Fortify's encrypted JSON of plaintext codes
 *   -> `encrypted:array` of bcrypt hashes of the UPPER-CASED codes, since
 *   TwoFactorAuthService::verifyRecoveryCode() upper-cases input before
 *   Hash::check and Fortify's codes are mixed-case.
 *
 * Unconfirmed enrollments are cleared (2FA is now mandatory, so those users
 * enroll fresh); rows that can't be decrypted are cleared and logged, and
 * that user re-enrolls. Must run under the APP_KEY that wrote the data.
 *
 * Irreversible: plaintext recovery codes can't be recovered from hashes, so
 * down() clears the 2FA columns and every user re-enrolls.
 */
return new class extends Migration
{
    private const CLEARED = [
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ];

    public function up(): void
    {
        DB::table('users')
            ->where(fn ($query) => $query
                ->whereNotNull('two_factor_secret')
                ->orWhereNotNull('two_factor_recovery_codes')
                ->orWhereNotNull('two_factor_confirmed_at'))
            ->select(['id', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update($this->convert($user));
                }
            });
    }

    public function down(): void
    {
        DB::table('users')->update(self::CLEARED);
    }

    /**
     * @return array<string, string|null>
     */
    private function convert(stdClass $user): array
    {
        if ($user->two_factor_confirmed_at === null) {
            return self::CLEARED;
        }

        try {
            $secret = $user->two_factor_secret === null ? null : Crypt::decrypt($user->two_factor_secret);
            $codes = $user->two_factor_recovery_codes === null
                ? []
                : json_decode((string) Crypt::decrypt($user->two_factor_recovery_codes), true);

            if (! is_string($secret) || $secret === '' || ! is_array($codes)) {
                throw new DecryptException('Unexpected Fortify two-factor payload shape.');
            }

            $hashed = array_map(fn ($code) => Hash::make(strtoupper((string) $code)), array_values($codes));

            return [
                'two_factor_secret' => Crypt::encryptString($secret),
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode($hashed, JSON_THROW_ON_ERROR)),
            ];
        } catch (DecryptException|ErrorException $e) {
            // ErrorException: unserialize() notice on a payload that isn't Fortify's serialized format.
            Log::channel('security')->warning('2FA enrollment cleared during Fortify-to-rwatch conversion: stored secret could not be decrypted; user must re-enroll.', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return self::CLEARED;
        }
    }
};
