<?php

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\LogRedactor;
use App\Services\SlowQueryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Settings > System logs: the Laravel error log and the slow query log
 * (MySQL's own, else the app-side SlowQueryLog). Ported from rwatch with
 * the same response shapes, plus: secrets are redacted in every view and
 * download, only the file basename is exposed as `log_path`, files are
 * tailed by bytes, and the slow-log path must be on a fixed allowlist.
 */
class SystemLogController extends Controller
{
    private const ERROR_TAIL_LINES = 5000;

    private const ERROR_MAX_ENTRIES = 200;

    private const SLOW_TAIL_LINES = 2000;

    private const TAIL_BYTES = 2 * 1024 * 1024;

    private const DOWNLOAD_MAX_BYTES = 10 * 1024 * 1024;

    private const SLOW_QUERY_VARIABLES = ['slow_query_log', 'slow_query_log_file', 'long_query_time', 'log_queries_not_using_indexes'];

    public function errorLogs(): JsonResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $logPath = $this->errorLogPath();

        if (! file_exists($logPath)) {
            return response()->json(['data' => [
                'logs' => [],
                'stats' => ['total_lines' => 0, 'error_count' => 0, 'warning_count' => 0, 'last_update' => null, 'log_path' => basename($logPath)],
            ], 'message' => 'Success']);
        }

        $rawLines = array_map(LogRedactor::redact(...), $this->tailFile($logPath, self::ERROR_TAIL_LINES));
        $entries = array_slice($this->groupLogEntries($rawLines), -self::ERROR_MAX_ENTRIES);

        $errorCount = 0;
        $warningCount = 0;
        foreach ($entries as $entry) {
            if (in_array($entry['level'], ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'], true)) {
                $errorCount++;
            } elseif ($entry['level'] === 'WARNING') {
                $warningCount++;
            }
        }

        return response()->json(['data' => [
            'logs' => $entries,
            'stats' => [
                'total_lines' => count($entries),
                'error_count' => $errorCount,
                'warning_count' => $warningCount,
                'last_update' => $this->lastUpdate($logPath),
                'log_path' => basename($logPath),
            ],
        ], 'message' => 'Success']);
    }

    public function downloadErrorLogs(): StreamedResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $logPath = $this->errorLogPath();
        abort_unless(file_exists($logPath), 404, 'No error log file found.');

        return $this->redactedDownload($logPath, LogRedactor::redact(...), 'laravel_error_log_'.now()->format('Y-m-d_His').'.txt');
    }

    public function clearErrorLogs(Request $request): JsonResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $logPath = $this->errorLogPath();
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        Log::channel('security')->info('Error log cleared', [
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        activity()
            ->event('system_log.errors_cleared')
            ->withProperties(['ip' => $request->ip()])
            ->log('system_log.errors_cleared');

        return response()->json(['message' => 'Error log cleared successfully.']);
    }

    public function slowQueryLogs(): JsonResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $appEnv = app()->environment();
        $logPath = $this->findSlowQueryLogPath($appEnv);

        [$slowLogEnabled, $longQueryTime] = $this->slowQueryLogVariables($logPath);

        if (! $slowLogEnabled || ! $logPath) {
            return response()->json(['data' => [
                'logs' => [],
                'enabled' => $slowLogEnabled,
                'stats' => [
                    'total_count' => 0, 'slowest' => '0s', 'avg_time' => '0s', 'last_update' => null,
                    'log_path' => $logPath ? basename($logPath) : 'Not found', 'long_query_time' => $longQueryTime, 'environment' => $appEnv,
                ],
            ], 'message' => 'Success']);
        }

        $lines = array_map($this->redactSlowLine(...), $this->tailFile($logPath, self::SLOW_TAIL_LINES));
        $queryTimes = [];
        foreach ($lines as $line) {
            if (preg_match('/Query_time:\s+([\d.]+)/', $line, $m)) {
                $queryTimes[] = (float) $m[1];
            }
        }
        $total = count($queryTimes);

        return response()->json(['data' => [
            'logs' => $lines,
            'enabled' => true,
            'stats' => [
                'total_count' => $total,
                'slowest' => ($total ? max($queryTimes) : 0).'s',
                'avg_time' => round($total ? array_sum($queryTimes) / $total : 0, 4).'s',
                'last_update' => $this->lastUpdate($logPath),
                'log_path' => basename($logPath),
                'long_query_time' => $longQueryTime,
                'environment' => $appEnv,
            ],
        ], 'message' => 'Success']);
    }

    public function downloadSlowQueryLogs(): StreamedResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $logPath = $this->findSlowQueryLogPath(app()->environment());
        abort_unless($logPath !== null, 404, 'No slow query log file found.');

        return $this->redactedDownload($logPath, $this->redactSlowLine(...), 'slow_query_log_'.app()->environment().'_'.now()->format('Y-m-d_His').'.txt');
    }

    public function clearSlowQueryLogs(Request $request): JsonResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $logPath = $this->findSlowQueryLogPath(app()->environment());
        if (! $logPath) {
            return response()->json(['message' => 'No slow query log file found.'], 404);
        }

        // MySQL may own the file; FLUSH SLOW LOGS reopens it. Needs RELOAD,
        // which the app user usually lacks, so failure is swallowed.
        if (! is_writable($logPath)) {
            $this->flushSlowLogs();
            clearstatcache(true, $logPath);
        }

        if (! is_writable($logPath)) {
            return response()->json(['message' => 'Log file is not writable.'], 500);
        }

        file_put_contents($logPath, '');
        $this->flushSlowLogs();

        Log::channel('security')->info('Slow query log cleared', [
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        activity()
            ->event('system_log.slow_queries_cleared')
            ->withProperties(['ip' => $request->ip()])
            ->log('system_log.slow_queries_cleared');

        return response()->json(['message' => 'Slow query log cleared successfully.']);
    }

    public function slowQueryStatus(): JsonResponse
    {
        $this->authorize(Permission::SecuritySettingsManage->value);

        $vars = [];
        foreach (self::SLOW_QUERY_VARIABLES as $key) {
            $vars[$key] = (string) ($this->mysqlVariable($key) ?? 'N/A');
        }

        // The file path is server layout; show its basename only.
        if ($vars['slow_query_log_file'] !== 'N/A') {
            $vars['slow_query_log_file'] = basename(str_replace('\\', '/', $vars['slow_query_log_file']));
        }

        $vars['app_slow_query_threshold_ms'] = SlowQueryLog::enabled() ? (string) SlowQueryLog::thresholdMs() : 'OFF';

        return response()->json(['data' => ['variables' => $vars], 'message' => 'Success']);
    }

    private function redactSlowLine(string $line): string
    {
        return LogRedactor::redact(LogRedactor::maskSqlLiterals($line));
    }

    /**
     * The newest of laravel.log and the daily laravel-YYYY-MM-DD.log files
     * (LOG_STACK=daily in production), from a fixed glob; defaults to
     * laravel.log when none exists. Never matches security*.log.
     */
    private function errorLogPath(): string
    {
        $dir = storage_path('logs');
        $candidates = array_filter([
            $dir.DIRECTORY_SEPARATOR.'laravel.log',
            ...(glob($dir.DIRECTORY_SEPARATOR.'laravel-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log') ?: []),
        ], is_file(...));

        $newest = null;
        $newestKey = null;
        foreach ($candidates as $path) {
            $key = [(int) filemtime($path), basename($path)];
            if ($newestKey === null || $key > $newestKey) {
                [$newest, $newestKey] = [$path, $key];
            }
        }

        return $newest ?? $dir.DIRECTORY_SEPARATOR.'laravel.log';
    }

    private function lastUpdate(string $path): ?string
    {
        $mtime = filemtime($path);

        return $mtime === false ? null : date('Y-m-d H:i:s', $mtime);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array{summary: string, trace: string, level: string}>
     */
    private function groupLogEntries(array $lines): array
    {
        $entries = [];
        $current = null;
        $headerPattern = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \S+\.(\w+): /';

        foreach ($lines as $line) {
            if (preg_match($headerPattern, $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['summary' => $line, 'trace' => '', 'level' => strtoupper($m[1])];
            } elseif ($current) {
                $current['trace'] .= ($current['trace'] === '' ? '' : "\n").$line;
            } else {
                $entries[] = ['summary' => $line, 'trace' => '', 'level' => 'INFO'];
            }
        }
        if ($current) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function slowQueryLogVariables(?string $logPath): array
    {
        $appThreshold = SlowQueryLog::enabled() ? SlowQueryLog::thresholdMs() / 1000 : null;

        $enabledValue = $this->mysqlVariable('slow_query_log');

        if ($enabledValue === null) {
            return [(bool) $logPath || $appThreshold !== null, $appThreshold];
        }

        $enabled = in_array(strtolower($enabledValue), ['on', '1'], true);
        $longQueryTime = $this->mysqlVariable('long_query_time');

        return [$enabled || (bool) $logPath || $appThreshold !== null, $enabled ? $longQueryTime : ($appThreshold ?? $longQueryTime)];
    }

    /**
     * The first existing, readable file on a fixed allowlist. MySQL's
     * reported slow_query_log_file is only used when it is itself on the
     * allowlist; nothing here ever comes from request input.
     */
    private function findSlowQueryLogPath(string $appEnv): ?string
    {
        $allowed = array_values(array_filter([
            config('database.slow_query_log_file'),
            ...match ($appEnv) {
                'local' => ['C:/xampp/mysql/data/mysql-slow.log'],
                'staging' => ['/var/log/mysql/staging-slow.log'],
                'production' => ['/var/log/mysql/production-slow.log'],
                default => [],
            },
            '/var/log/mysql/mysql-slow.log',
            '/var/log/mysql/slow.log',
            '/var/lib/mysql/slow.log',
            SlowQueryLog::path(),
        ], fn ($path) => is_string($path) && $path !== ''));

        $reported = $this->mysqlVariable('slow_query_log_file');
        if ($reported !== null && $reported !== '') {
            foreach ($allowed as $path) {
                if ($this->samePath($path, $reported)) {
                    array_unshift($allowed, $path);
                    break;
                }
            }
        }

        foreach ($allowed as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function samePath(string $a, string $b): bool
    {
        $a = str_replace('\\', '/', $a);
        $b = str_replace('\\', '/', $b);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /**
     * @param  value-of<self::SLOW_QUERY_VARIABLES>  $name
     */
    private function mysqlVariable(string $name): ?string
    {
        try {
            $row = DB::selectOne("SHOW VARIABLES LIKE '{$name}'");
        } catch (Throwable) {
            return null;
        }

        return isset($row->Value) ? (string) $row->Value : null;
    }

    private function flushSlowLogs(): void
    {
        try {
            DB::statement('FLUSH SLOW LOGS');
        } catch (Throwable) {
        }
    }

    /**
     * The last $lines non-empty lines from the final TAIL_BYTES of the file.
     *
     * @return array<int, string>
     */
    private function tailFile(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $size = (int) filesize($path);
        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $rows = explode("\n", $chunk);
        if ($offset > 0) {
            array_shift($rows); // partial first line
        }

        $result = [];
        foreach ($rows as $row) {
            $row = rtrim($row);
            if ($row !== '') {
                $result[] = $row;
            }
        }

        return array_slice($result, -$lines);
    }

    /**
     * Streams the last DOWNLOAD_MAX_BYTES of the file, redacted line by line.
     *
     * @param  callable(string): string  $redact
     */
    private function redactedDownload(string $path, callable $redact, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($path, $redact) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }

            $offset = max(0, (int) filesize($path) - self::DOWNLOAD_MAX_BYTES);
            fseek($handle, $offset);
            if ($offset > 0) {
                fgets($handle); // partial first line
            }

            while (($line = fgets($handle)) !== false) {
                echo $redact($line);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
