<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Pins 2026_10_19_000008: Fortify-encoded 2FA columns must come out in the
 * encoding rwatch's User casts and TwoFactorAuthService read.
 */
class ConvertFortifyTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_19_000008_convert_fortify_two_factor_columns_to_encrypted_casts.php'))->up();
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function userWithRawColumns(array $columns): int
    {
        $id = User::factory()->create()->id;

        if ($columns !== []) {
            DB::table('users')->where('id', $id)->update($columns);
        }

        return $id;
    }

    /**
     * Mirrors rwatch TwoFactorAuthService::verifyRecoveryCode().
     */
    private function recoveryCodeVerifies(int $userId, string $input): bool
    {
        $hashes = json_decode(Crypt::decryptString(DB::table('users')->where('id', $userId)->value('two_factor_recovery_codes')), true);

        foreach ($hashes as $hash) {
            if (Hash::check(strtoupper($input), $hash)) {
                return true;
            }
        }

        return false;
    }

    public function test_confirmed_enrollment_is_reencoded(): void
    {
        $confirmedAt = '2026-07-20 10:00:00';
        $id = $this->userWithRawColumns([
            'two_factor_secret' => encrypt(self::SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode(['AbCdEfGhIj-KlMnOpQrSt', 'zyxwvutsrq-ponmlkjihg'])),
            'two_factor_confirmed_at' => $confirmedAt,
        ]);

        $this->migrate();

        $row = DB::table('users')->where('id', $id)->first();

        // `encrypted` cast format: decryptString, no unserialize.
        $secret = Crypt::decryptString($row->two_factor_secret);
        $this->assertSame(self::SECRET, $secret);

        $google2fa = app(Google2FA::class);
        $this->assertTrue((bool) $google2fa->verifyKey($secret, $google2fa->getCurrentOtp($secret)));

        $this->assertTrue($this->recoveryCodeVerifies($id, 'AbCdEfGhIj-KlMnOpQrSt'));
        $this->assertTrue($this->recoveryCodeVerifies($id, 'abcdefghij-klmnopqrst'));
        $this->assertTrue($this->recoveryCodeVerifies($id, 'zyxwvutsrq-ponmlkjihg'));
        $this->assertFalse($this->recoveryCodeVerifies($id, 'not-a-code'));

        $this->assertStringStartsWith($confirmedAt, (string) $row->two_factor_confirmed_at);
    }

    public function test_unconfirmed_enrollment_is_cleared(): void
    {
        $id = $this->userWithRawColumns([
            'two_factor_secret' => encrypt(self::SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => null,
        ]);

        $this->migrate();

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_recovery_codes);
        $this->assertNull($row->two_factor_confirmed_at);
    }

    public function test_undecryptable_row_is_cleared_and_logged(): void
    {
        Log::spy()->shouldReceive('channel')->andReturnSelf();

        $id = $this->userWithRawColumns([
            'two_factor_secret' => 'eyJpdiI6Im5vdC1yZWFsIn0=',
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $untouched = $this->userWithRawColumns([]);

        $this->migrate();

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_recovery_codes);
        $this->assertNull($row->two_factor_confirmed_at);
        $this->assertNotNull(DB::table('users')->where('id', $untouched)->value('password'));

        Log::shouldHaveReceived('channel')->with('security')->once();
        Log::shouldHaveReceived('warning')->once();
    }
}
