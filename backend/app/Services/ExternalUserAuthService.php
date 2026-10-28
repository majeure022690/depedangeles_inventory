<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PDOException;
use stdClass;
use Throwable;

/**
 * PRIME is the only password authority; its password hash is never copied
 * into IRIS. Connection/query failures (QueryException, PDOException)
 * propagate so callers can tell "PRIME unreachable" from a refusal.
 */
final class ExternalUserAuthService
{
    public const ACTIVE_STATUS = 'Active';

    /**
     * Circuit breaker: after a failed PRIME connection/query, guarded reads
     * skip PRIME for this long instead of each waiting out the connect
     * timeout.
     */
    public const UNREACHABLE_SECONDS = 30;

    public const UNREACHABLE_KEY = 'prime.unreachable';

    public static function disabled(): bool
    {
        return ! config('auth.prime_enabled');
    }

    public static function outageReason(): string
    {
        return self::disabled() ? 'prime_disabled' : 'prime_unreachable';
    }

    // Never throws: callers include boot and the never-throwing sync paths, where the cache or log may be unavailable.
    public static function reportDisabledOncePerHour(string $key, string $level, string $message): void
    {
        try {
            if (Cache::add($key, true, now()->addHour())) {
                Log::channel('security')->log($level, $message);
            }
        } catch (Throwable) {
        }
    }

    // Throws when PRIME knows the user but refuses them (or the email is ambiguous), so the caller never falls back to a local password.
    public function authenticate(string $email, string $password): ?User
    {
        $primeUser = $this->primeAccount($email);

        if (! $primeUser) {
            return null;
        }

        $localUser = User::where('email', $primeUser->email_address)->first();

        if ($primeUser->user_status !== self::ACTIVE_STATUS) {
            $localUser?->update(['is_active' => false]);

            throw new InvalidPrimeCredentials;
        }

        if (! Hash::check($password, $primeUser->password)) {
            throw new InvalidPrimeCredentials;
        }

        if (! $localUser) {
            return User::create([
                'name' => $primeUser->full_name,
                'email' => $primeUser->email_address,
                'is_active' => true,
            ]);
        }

        $localUser->update(['name' => $primeUser->full_name]);

        return $localUser;
    }

    /**
     * Re-confirms a password against PRIME: null when PRIME doesn't know the
     * email, false when it refuses (wrong password, inactive, ambiguous).
     */
    public function checkPassword(string $email, string $password): ?bool
    {
        try {
            $primeUser = $this->primeAccount($email);
        } catch (InvalidPrimeCredentials) {
            return false;
        }

        if (! $primeUser) {
            return null;
        }

        return $primeUser->user_status === self::ACTIVE_STATUS
            && Hash::check($password, $primeUser->password);
    }

    /**
     * Developer break-glass accounts (`auth.local_login_emails`) — the only
     * accounts whose local IRIS password is ever checked.
     */
    public static function allowsLocalPassword(string $email): bool
    {
        $allowed = array_map(
            fn (string $allowed): string => mb_strtolower(trim($allowed)),
            (array) config('auth.local_login_emails', []),
        );

        return in_array(mb_strtolower(trim($email)), $allowed, true);
    }

    /**
     * MySQL client errors meaning "no usable connection": can't connect
     * (2002 socket, 2003 TCP, 2005 unknown host), gone away (2006), lost
     * during a query (2013).
     */
    private const CONNECTION_ERROR_CODES = [2002, 2003, 2005, 2006, 2013];

    /**
     * Only a connection-level failure counts as "PRIME unreachable" (the one
     * case allowing a break-glass fallback, and the only one that opens the
     * breaker): the breaker's own PrimeUnreachable, a bare PDOException from
     * connecting, or a QueryException on the PRIME connection caused by a
     * failed/lost connection. Any other PRIME query error (bad data,
     * collation, missing column) is a refusal, so input can't trip the
     * breaker for everyone.
     */
    public static function isUnreachable(Throwable $e): bool
    {
        // QueryException extends PDOException, so it must be checked first or every local query error would count.
        return match (true) {
            $e instanceof PrimeUnreachable => true,
            $e instanceof QueryException => $e->getConnectionName() === 'external_users' && self::lostConnection($e),
            default => $e instanceof PDOException,
        };
    }

    /**
     * A PRIME QueryException that isn't an outage: refused, never retried
     * locally.
     */
    public static function isQueryError(Throwable $e): bool
    {
        return $e instanceof QueryException && $e->getConnectionName() === 'external_users' && ! self::isUnreachable($e);
    }

    private static function lostConnection(QueryException $e): bool
    {
        $pdo = $e->getPrevious() instanceof PDOException ? $e->getPrevious() : $e;
        $sqlState = (string) ($pdo->errorInfo[0] ?? $e->getCode());
        $driverCode = $pdo->errorInfo[1] ?? null;

        return app(LostConnectionDetector::class)->causedByLostConnection($e)
            || str_starts_with($sqlState, '08')
            || in_array($driverCode, self::CONNECTION_ERROR_CODES, true)
            || preg_match('/SQLSTATE\[(08\w{3}|HY000)\] \[(?:'.implode('|', self::CONNECTION_ERROR_CODES).')\]/', $e->getMessage()) === 1;
    }

    /**
     * Runs a PRIME read through the circuit breaker. While PRIME is known to
     * be down it throws PrimeUnreachable without contacting PRIME (unless
     * $skipWhileDown is false, as for the artisan full sync, which should
     * always try and fail loudly). A PRIME connection/query failure opens
     * the breaker for UNREACHABLE_SECONDS; the exception still propagates.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function guard(Closure $read, bool $skipWhileDown = true): mixed
    {
        if (self::disabled()) {
            throw new PrimeUnreachable('PRIME is disabled (PRIME_ENABLED=false).');
        }

        if ($skipWhileDown && Cache::has(self::UNREACHABLE_KEY)) {
            throw new PrimeUnreachable('PRIME was unreachable moments ago; not retrying yet.');
        }

        try {
            return $read();
        } catch (Throwable $e) {
            if (self::isUnreachable($e) && ! $e instanceof PrimeUnreachable) {
                Cache::put(self::UNREACHABLE_KEY, true, self::UNREACHABLE_SECONDS);
            }

            throw $e;
        }
    }

    public static function users(): Builder
    {
        return DB::connection('external_users')->table('users');
    }

    public function testConnection(): bool
    {
        try {
            DB::connection('external_users')->getPdo();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * More than one PRIME row for an email is refused: which one's password
     * applies can't be decided safely.
     */
    private function primeAccount(string $email): ?stdClass
    {
        $rows = self::guard(fn () => self::users()->where('email_address', $email)->limit(2)->get());

        if ($rows->count() > 1) {
            Log::channel('security')->warning('Ambiguous PRIME account: several rows share an email', ['email' => $email]);

            throw new InvalidPrimeCredentials;
        }

        return $rows->first();
    }
}
