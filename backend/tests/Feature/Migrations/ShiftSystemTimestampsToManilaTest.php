<?php

namespace Tests\Feature\Migrations;

use App\Models\IspSpeedTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins 2026_10_19_000011: system-written timestamps move +8h (UTC -> Manila),
 * user-entered wall-clock fields and NULLs are left alone, down() reverses.
 */
class ShiftSystemTimestampsToManilaTest extends TestCase
{
    use RefreshDatabase;

    private const UTC = '2026-07-15 04:17:51';

    private const MANILA = '2026-07-15 12:17:51';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_000011_shift_system_timestamps_from_utc_to_manila.php');
    }

    /**
     * @return array{user: int, test: int, activity: int, audit: int}
     */
    private function seedRows(): array
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => self::UTC,
            'updated_at' => self::UTC,
            'email_verified_at' => self::UTC,
            'two_factor_confirmed_at' => null,
        ]);

        $test = IspSpeedTest::factory()->create(['signal_quality' => 'Good']);
        DB::table('isp_speed_tests')->where('id', $test->id)->update([
            'tested_at' => '2026-02-05 08:23:00',
            'created_at' => self::UTC,
            'updated_at' => self::UTC,
        ]);

        $activity = DB::table('activity_log')->insertGetId([
            'log_name' => 'default',
            'description' => 'user.created',
            'created_at' => self::UTC,
            'updated_at' => self::UTC,
        ]);

        $audit = DB::table('audit_logs')->insertGetId([
            'action' => 'user.created',
            'created_at' => self::UTC,
        ]);

        return ['user' => $user->id, 'test' => $test->id, 'activity' => $activity, 'audit' => $audit];
    }

    public function test_up_shifts_system_timestamps_and_leaves_user_entered_and_null_values(): void
    {
        $ids = $this->seedRows();

        $this->migration()->up();

        $user = DB::table('users')->find($ids['user']);
        $this->assertSame(self::MANILA, $user->created_at);
        $this->assertSame(self::MANILA, $user->updated_at);
        $this->assertSame(self::MANILA, $user->email_verified_at);
        $this->assertNull($user->two_factor_confirmed_at);

        $test = DB::table('isp_speed_tests')->find($ids['test']);
        $this->assertSame('2026-02-05 08:23:00', $test->tested_at);
        $this->assertSame(self::MANILA, $test->created_at);

        $this->assertSame(self::MANILA, DB::table('activity_log')->where('id', $ids['activity'])->value('created_at'));
        $this->assertSame(self::MANILA, DB::table('audit_logs')->where('id', $ids['audit'])->value('created_at'));
    }

    public function test_down_reverses_up(): void
    {
        $ids = $this->seedRows();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $user = DB::table('users')->find($ids['user']);
        $this->assertSame(self::UTC, $user->created_at);
        $this->assertSame(self::UTC, $user->email_verified_at);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertSame('2026-02-05 08:23:00', DB::table('isp_speed_tests')->where('id', $ids['test'])->value('tested_at'));
        $this->assertSame(self::UTC, DB::table('activity_log')->where('id', $ids['activity'])->value('created_at'));
    }
}
