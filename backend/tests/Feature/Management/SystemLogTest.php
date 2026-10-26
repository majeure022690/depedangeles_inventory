<?php

namespace Tests\Feature\Management;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SlowQueryLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    /**
     * Real dev log contents, put back after each test.
     *
     * @var array<string, string|null>
     */
    private array $originals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        foreach ([$this->errorLogPath(), SlowQueryLog::path(), $this->dailyLogPath(), $this->securityLogPath(), $this->mysqlSlowLogPath()] as $path) {
            $this->originals[$path] = is_file($path) ? (string) file_get_contents($path) : null;
            @unlink($path);
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->originals as $path => $contents) {
                $contents === null ? @unlink($path) : file_put_contents($path, $contents);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function errorLogPath(): string
    {
        return storage_path('logs/laravel.log');
    }

    private function dailyLogPath(): string
    {
        return storage_path('logs/laravel-1999-12-31.log');
    }

    private function securityLogPath(): string
    {
        return storage_path('logs/security-1999-12-31.log');
    }

    private function mysqlSlowLogPath(): string
    {
        return storage_path('logs/mysql-slow-test.log');
    }

    private function userWithOnly(Permission $permission): User
    {
        $role = Role::create(['name' => 'only-'.$permission->value, 'label' => 'Only '.$permission->label()]);
        $role->givePermissionTo($permission->value);

        $user = User::factory()->create();
        $user->assignRole($role->name);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithOnly(Permission::SecuritySettingsManage);
    }

    private function slowQuery(float $ms): QueryExecuted
    {
        return new QueryExecuted("select *\n  from users where email = ?", ['secret@example.test'], $ms, DB::connection());
    }

    public function test_error_log_is_grouped_into_entries_with_stats(): void
    {
        file_put_contents($this->errorLogPath(), implode("\n", [
            '[2026-01-01 10:00:00] local.ERROR: Something broke {"context":true}',
            '#0 stack trace line',
            '[2026-01-01 10:01:00] local.WARNING: Careful',
            '[2026-01-01 10:02:00] local.INFO: Fine',
        ])."\n");

        $response = $this->actingAs($this->admin())->getJson('/api/settings/system-logs/errors')
            ->assertOk()
            ->assertJsonPath('message', 'Success')
            ->assertJsonPath('data.stats.total_lines', 3)
            ->assertJsonPath('data.stats.error_count', 1)
            ->assertJsonPath('data.stats.warning_count', 1)
            ->assertJsonPath('data.stats.log_path', 'laravel.log')
            ->assertJsonPath('data.logs.0.level', 'ERROR')
            ->assertJsonPath('data.logs.1.level', 'WARNING');

        $this->assertSame('#0 stack trace line', $response->json('data.logs.0.trace'));
        $this->assertNotNull($response->json('data.stats.last_update'));
    }

    public function test_missing_error_log_returns_an_empty_list(): void
    {
        $this->actingAs($this->admin())->getJson('/api/settings/system-logs/errors')
            ->assertOk()
            ->assertJsonPath('data.logs', [])
            ->assertJsonPath('data.stats.total_lines', 0)
            ->assertJsonPath('data.stats.last_update', null)
            ->assertJsonPath('data.stats.log_path', 'laravel.log');
    }

    public function test_secrets_are_redacted_in_the_error_log_view_and_download(): void
    {
        $secrets = [
            'DB_PASSWORD=hunter2-db',
            '{"password":"hunter2-json","email":"a@example.test"}',
            "'api_key' => 'sk_live_abc123'",
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
            'APP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTI=',
            'mysql://root:hunter2-url@db.internal/inventory',
            'remember_token: tok_abcdef',
        ];
        file_put_contents($this->errorLogPath(), '[2026-01-01 10:00:00] local.ERROR: Boom '.$secrets[0]."\n".implode("\n", array_slice($secrets, 1))."\n");

        $admin = $this->admin();
        $view = $this->actingAs($admin)->getJson('/api/settings/system-logs/errors')->assertOk();
        $download = $this->actingAs($admin)->get('/api/settings/system-logs/errors/download')->assertOk();

        foreach ([json_encode($view->json('data.logs')), $download->streamedContent()] as $output) {
            foreach (['hunter2-db', 'hunter2-json', 'sk_live_abc123', 'eyJhbGciOiJIUzI1NiJ9', 'c2VjcmV0c2VjcmV0', 'hunter2-url', 'tok_abcdef'] as $leak) {
                $this->assertStringNotContainsString($leak, (string) $output);
            }
            $this->assertStringContainsString('[REDACTED]', (string) $output);
            $this->assertStringContainsString('a@example.test', (string) $output);
        }
    }

    public function test_error_log_download_is_plain_text_and_not_cached(): void
    {
        file_put_contents($this->errorLogPath(), "[2026-01-01 10:00:00] local.ERROR: Something broke\n");

        $response = $this->actingAs($this->admin())->get('/api/settings/system-logs/errors/download')->assertOk();

        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/attachment; filename=laravel_error_log_\d{4}-\d{2}-\d{2}_\d{6}\.txt/', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Something broke', $response->streamedContent());
    }

    public function test_error_log_download_is_404_when_there_is_no_log(): void
    {
        $this->actingAs($this->admin())->getJson('/api/settings/system-logs/errors/download')->assertNotFound();
    }

    public function test_only_the_tail_of_a_large_error_log_is_read(): void
    {
        $filler = str_repeat('x', 1000);
        $handle = fopen($this->errorLogPath(), 'w');
        $this->assertNotFalse($handle);
        for ($i = 0; $i < 3000; $i++) {
            fwrite($handle, "[2026-01-01 10:00:00] local.INFO: filler {$filler}\n");
        }
        fwrite($handle, "[2026-01-01 11:00:00] local.ERROR: The newest entry\n");
        fclose($handle);

        $response = $this->actingAs($this->admin())->getJson('/api/settings/system-logs/errors')->assertOk();

        $this->assertSame(200, $response->json('data.stats.total_lines'));
        $this->assertStringContainsString('The newest entry', $response->json('data.logs.199.summary'));
    }

    public function test_clearing_the_error_log_truncates_it_and_is_audited(): void
    {
        file_put_contents($this->errorLogPath(), "[2026-01-01 10:00:00] local.ERROR: Something broke\n");
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/errors', ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Error log cleared successfully.');

        $this->assertSame('', file_get_contents($this->errorLogPath()));
        $activity = Activity::query()->where('event', 'system_log.errors_cleared')->sole();
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_the_newest_daily_error_log_is_shown_and_cleared(): void
    {
        file_put_contents($this->errorLogPath(), "[2026-01-01 10:00:00] local.ERROR: Old single log\n");
        touch($this->errorLogPath(), time() - 3600);
        file_put_contents($this->dailyLogPath(), "[2026-01-01 10:00:00] production.ERROR: From the daily log\n");
        touch($this->dailyLogPath(), time() + 60);
        file_put_contents($this->securityLogPath(), "[2026-01-01 10:00:00] production.WARNING: Security event\n");
        touch($this->securityLogPath(), time() + 120);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson('/api/settings/system-logs/errors')
            ->assertOk()
            ->assertJsonPath('data.stats.log_path', 'laravel-1999-12-31.log')
            ->assertJsonPath('data.stats.total_lines', 1);
        $this->assertStringContainsString('From the daily log', $response->json('data.logs.0.summary'));

        $download = $this->actingAs($admin)->get('/api/settings/system-logs/errors/download')->assertOk();
        $this->assertStringContainsString('From the daily log', $download->streamedContent());
        $this->assertStringNotContainsString('Security event', $download->streamedContent());

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/errors', ['current_password' => 'password'])->assertOk();

        $this->assertSame('', file_get_contents($this->dailyLogPath()));
        $this->assertStringContainsString('Old single log', (string) file_get_contents($this->errorLogPath()));
        $this->assertStringContainsString('Security event', (string) file_get_contents($this->securityLogPath()));
    }

    public function test_sql_literals_are_masked_in_the_mysql_slow_log_view_and_download(): void
    {
        config(['database.slow_query_ms' => 0, 'database.slow_query_log_file' => $this->mysqlSlowLogPath()]);
        file_put_contents($this->mysqlSlowLogPath(), implode("\n", [
            '# Time: 2026-10-20T01:00:00.000000Z',
            '# User@Host: inventory[inventory] @ localhost []',
            '# Query_time: 1.500000  Lock_time: 0.000000 Rows_sent: 1  Rows_examined: 1',
            'SET timestamp=1792458000;',
            "select * from `sessions` where `id` = 'SeSsIoNiD0123456789abcdefghijkl' limit 1;",
            '# Query_time: 2.500000  Lock_time: 0.000000 Rows_sent: 0  Rows_examined: 0',
            "insert into `cache` (`key`, `value`, `expiration`) values ('inventory-cache-2fa_setup:42', 's:16:\\\"TOTPSECRETVALUE7\\\";', 1792458600), (\"dq-it's-key\", 'O\'Brien-tok', 1);",
            'update `users` set `remember_token` = 0xDEADBEEFCAFEBABE0123 where `id` = 5;',
        ])."\n");
        $admin = $this->admin();

        $view = $this->actingAs($admin)->getJson('/api/settings/system-logs/slow-queries')
            ->assertOk()
            ->assertJsonPath('data.stats.total_count', 2)
            ->assertJsonPath('data.stats.log_path', 'mysql-slow-test.log');
        $download = $this->actingAs($admin)->get('/api/settings/system-logs/slow-queries/download')->assertOk();

        foreach ([implode("\n", $view->json('data.logs')), $download->streamedContent()] as $output) {
            foreach (['SeSsIoNiD0123456789', '2fa_setup', 'TOTPSECRETVALUE7', 'dq-it', 'Brien', 'DEADBEEF'] as $leak) {
                $this->assertStringNotContainsString($leak, $output);
            }
            $this->assertStringContainsString("select * from `sessions` where `id` = '?' limit 1;", $output);
            $this->assertStringContainsString("values ('?', '?', 1792458600), (\"?\", '?', 1);", $output);
            $this->assertStringContainsString('# Query_time: 2.500000', $output);
        }
    }

    public function test_slow_query_log_lists_downloads_and_clears_the_app_log(): void
    {
        config(['database.slow_query_ms' => 500]);
        SlowQueryLog::record($this->slowQuery(120));
        SlowQueryLog::record($this->slowQuery(2000));
        SlowQueryLog::record($this->slowQuery(1000));
        $admin = $this->admin();

        $log = (string) file_get_contents(SlowQueryLog::path());
        $this->assertSame(2, substr_count($log, '# Query_time:'));
        $this->assertStringContainsString('select * from users where email = ?;', $log);
        $this->assertStringNotContainsString('secret@example.test', $log);

        $this->actingAs($admin)->getJson('/api/settings/system-logs/slow-queries')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.stats.total_count', 2)
            ->assertJsonPath('data.stats.slowest', '2s')
            ->assertJsonPath('data.stats.avg_time', '1.5s')
            ->assertJsonPath('data.stats.log_path', 'slow-queries.log')
            ->assertJsonPath('data.stats.environment', 'testing');

        $download = $this->actingAs($admin)->get('/api/settings/system-logs/slow-queries/download')->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('# Query_time: 2.000000', $download->streamedContent());

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/slow-queries', ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Slow query log cleared successfully.');

        $this->assertSame('', file_get_contents(SlowQueryLog::path()));
        $this->assertSame(1, Activity::query()->where('event', 'system_log.slow_queries_cleared')->count());
    }

    public function test_nothing_is_logged_when_the_threshold_is_zero(): void
    {
        config(['database.slow_query_ms' => 0]);

        SlowQueryLog::record($this->slowQuery(60000));

        $this->assertFileDoesNotExist(SlowQueryLog::path());
    }

    public function test_slow_query_log_reports_empty_when_no_log_file_exists(): void
    {
        config(['database.slow_query_ms' => 0]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/settings/system-logs/slow-queries')
            ->assertOk()
            ->assertJsonPath('data.logs', [])
            ->assertJsonPath('data.stats.total_count', 0)
            ->assertJsonPath('data.stats.log_path', 'Not found');

        $this->actingAs($admin)->getJson('/api/settings/system-logs/slow-queries/download')->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/slow-queries', ['current_password' => 'password'])->assertNotFound();
        $this->assertSame(0, Activity::query()->where('event', 'system_log.slow_queries_cleared')->count());
    }

    public function test_slow_query_status_lists_the_fixed_variables(): void
    {
        config(['database.slow_query_ms' => 750]);

        $this->actingAs($this->admin())->getJson('/api/settings/system-logs/slow-queries/status')
            ->assertOk()
            ->assertJsonStructure(['data' => ['variables' => ['slow_query_log', 'slow_query_log_file', 'long_query_time', 'log_queries_not_using_indexes', 'app_slow_query_threshold_ms']]])
            ->assertJsonPath('data.variables.app_slow_query_threshold_ms', '750');
    }

    public function test_clearing_the_error_log_requires_the_current_password(): void
    {
        file_put_contents($this->errorLogPath(), "[2026-01-01 10:00:00] local.ERROR: Keep me\n");
        $admin = $this->admin();
        $auditRows = Activity::query()->count();

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/errors')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/errors', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertStringContainsString('Keep me', (string) file_get_contents($this->errorLogPath()));
        $this->assertSame($auditRows, Activity::query()->count());

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/errors', ['current_password' => 'password'])->assertOk();
        $this->assertSame('', file_get_contents($this->errorLogPath()));
    }

    public function test_clearing_the_slow_query_log_requires_the_current_password(): void
    {
        config(['database.slow_query_ms' => 500]);
        SlowQueryLog::record($this->slowQuery(2000));
        $admin = $this->admin();
        $auditRows = Activity::query()->count();

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/slow-queries')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/slow-queries', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertStringContainsString('# Query_time:', (string) file_get_contents(SlowQueryLog::path()));
        $this->assertSame($auditRows, Activity::query()->count());

        $this->actingAs($admin)->deleteJson('/api/settings/system-logs/slow-queries', ['current_password' => 'password'])->assertOk();
        $this->assertSame('', file_get_contents(SlowQueryLog::path()));
    }

    public function test_every_system_log_route_requires_security_settings_manage(): void
    {
        file_put_contents($this->errorLogPath(), "[2026-01-01 10:00:00] local.ERROR: Keep me\n");
        $routes = [
            ['getJson', '/api/settings/system-logs/errors'],
            ['getJson', '/api/settings/system-logs/errors/download'],
            ['deleteJson', '/api/settings/system-logs/errors'],
            ['getJson', '/api/settings/system-logs/slow-queries'],
            ['getJson', '/api/settings/system-logs/slow-queries/status'],
            ['getJson', '/api/settings/system-logs/slow-queries/download'],
            ['deleteJson', '/api/settings/system-logs/slow-queries'],
        ];
        $auditLogOnly = $this->userWithOnly(Permission::AuditLogView);
        $everythingElse = $this->userWithAllPermissions([Permission::SecuritySettingsManage]);

        foreach ($routes as [$method, $uri]) {
            $this->actingAs($auditLogOnly)->{$method}($uri)->assertForbidden();
            $this->actingAs($everythingElse)->{$method}($uri)->assertForbidden();
        }

        $this->assertStringContainsString('Keep me', (string) file_get_contents($this->errorLogPath()));
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/settings/system-logs/errors')->assertUnauthorized();
        $this->deleteJson('/api/settings/system-logs/slow-queries')->assertUnauthorized();
    }
}
