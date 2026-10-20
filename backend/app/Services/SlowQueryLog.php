<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * App-side slow query log, for hosts where MySQL's own slow log is off or
 * unreadable. Writes MySQL slow-log-style entries (SQL only, never the
 * bindings) to storage/logs/slow-queries.log, rotated once at 10 MB.
 * Wired via DB::listen in AppServiceProvider when DB_SLOW_QUERY_MS > 0.
 */
class SlowQueryLog
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public static function path(): string
    {
        return storage_path('logs/slow-queries.log');
    }

    public static function thresholdMs(): int
    {
        return max(0, (int) config('database.slow_query_ms'));
    }

    public static function enabled(): bool
    {
        return self::thresholdMs() > 0;
    }

    public static function record(QueryExecuted $query): void
    {
        if (! self::enabled() || $query->time < self::thresholdMs()) {
            return;
        }

        try {
            $path = self::path();

            if (is_file($path) && filesize($path) > self::MAX_BYTES) {
                @rename($path, $path.'.1');
            }

            $request = app()->runningInConsole()
                ? 'console '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1))
                : request()->method().' /'.ltrim(request()->path(), '/');

            file_put_contents($path, sprintf(
                "# Time: %s\n# Query_time: %.6f  Connection: %s\n# Source: %s\n%s;\n",
                now()->toIso8601ZuluString('microsecond'),
                $query->time / 1000,
                $query->connectionName,
                $request,
                trim((string) preg_replace('/\s+/', ' ', $query->sql)),
            ), FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }
}
