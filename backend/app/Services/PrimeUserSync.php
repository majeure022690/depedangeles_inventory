<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * @phpstan-import-type OfficeCounts from PrimeOfficeSync
 *
 * @phpstan-type Assignment array{employee_no: ?string, office_id: ?int}
 * @phpstan-type SyncCounts array{
 *     created: int, updated: int, deactivated: int,
 *     numbers_set: int, numbers_changed: int, numbers_cleared: int,
 *     offices_set: int, offices_changed: int, offices_cleared: int,
 *     office_sync: OfficeCounts
 * }
 */
final class PrimeUserSync
{
    private const COLUMNS = ['user_id', 'email_address', 'full_name', 'user_status', 'department_school_id'];

    private const AUDITED = ['name', 'email', 'is_active', 'employee_no', 'office_id'];

    /** Only what the live Users-list refresh needs: name and status by email, id for the mark. */
    private const LIVE_COLUMNS = ['user_id', 'email_address', 'full_name', 'user_status'];

    /**
     * Highest PRIME `users.user_id` already imported. PRIME ids are
     * auto-increment, so anything above it is an account IRIS hasn't seen.
     */
    public const HIGH_WATER_MARK_KEY = 'prime_users.max_user_id';

    public const DISABLED_NOTICE_KEY = 'prime_users.disabled_notice';

    /** New accounts imported per list load; any rest follow on the next loads. */
    private const IMPORT_BATCH = 100;

    /** users.name is a VARCHAR(255). */
    private const NAME_MAX = 255;

    public function __construct(private readonly PrimeOfficeSync $offices) {}

    /**
     * @return SyncCounts
     */
    public function run(): array
    {
        // Always tries (and fails loudly), but a PRIME failure still opens the breaker.
        return ExternalUserAuthService::guard(fn () => $this->runFullSync(), skipWhileDown: false);
    }

    /**
     * @return SyncCounts
     */
    private function runFullSync(): array
    {
        $directory = new PrimeDirectory;
        $directory->departments();
        $directory->employees();
        $directory->primeUsers();

        $read = self::withEmail(ExternalUserAuthService::users())->get(self::COLUMNS);
        $prime = self::uniqueByEmail($read);

        $officeCounts = $this->offices->run($directory);

        $locals = User::query()->get()->keyBy(fn (User $user) => mb_strtolower($user->email));

        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        foreach ($prime as $email => $row) {
            $result = $this->apply($row, $locals->get($email));

            if ($result !== null) {
                $counts[$result]++;
                $counts['updated'] += $result === 'deactivated' ? 1 : 0;
            }
        }

        $result = [...$counts, ...$this->assignAll($directory), 'office_sync' => $officeCounts];

        // The max this run actually read, so accounts created mid-run are still picked up by importNew().
        Cache::forever(self::HIGH_WATER_MARK_KEY, (int) $read->max('user_id'));

        return $result;
    }

    /**
     * Live pickup of new PRIME accounts for the Users list: one PRIME query
     * for active accounts above the high-water mark. Never throws: PRIME
     * being down leaves the local copy as-is.
     */
    public function importNew(): void
    {
        try {
            // Concurrent list loads would otherwise race to create the same account.
            ExternalUserAuthService::guard(
                fn () => Cache::lock(self::HIGH_WATER_MARK_KEY.'.lock', 30)->get(fn () => $this->importAboveMark()),
            );
        } catch (Throwable $e) {
            self::reportFailure($e);
        }
    }

    /**
     * Refreshes name and deactivation (same semantics and audit trail as
     * the full sync) for exactly these local users, in one PRIME query.
     * Office and employee number stay on login and `users:sync-external`.
     * Never throws: PRIME being down leaves the local copy as-is.
     *
     * @param  Collection<int, User>  $users
     */
    public function syncVisible(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        // PRIME's MySQL collation is case-insensitive; the lowercased copy covers a case-sensitive one.
        $emails = $users->pluck('email')
            ->flatMap(fn (string $email) => [$email, mb_strtolower($email)])
            ->unique()
            ->values()
            ->all();

        try {
            $prime = self::uniqueByEmail(ExternalUserAuthService::guard(fn () => self::withEmail(ExternalUserAuthService::users())
                ->whereIn('email_address', $emails)
                ->get(self::LIVE_COLUMNS)));

            foreach ($users as $user) {
                $row = $prime->get(mb_strtolower($user->email));

                if ($row !== null) {
                    $this->applySafely($row, $user);
                }
            }
        } catch (Throwable $e) {
            self::reportFailure($e);
        }
    }

    private static function reportFailure(Throwable $e): void
    {
        if ($e instanceof PrimeUnreachable && ExternalUserAuthService::disabled()) {
            ExternalUserAuthService::reportDisabledOncePerHour(self::DISABLED_NOTICE_KEY, 'warning', 'PRIME sync disabled by PRIME_ENABLED=false');

            return;
        }

        Log::warning('PRIME user sync failed; showing the local copy', ['error' => $e->getMessage()]);
    }

    private function importAboveMark(): void
    {
        $mark = Cache::get(self::HIGH_WATER_MARK_KEY);

        // First run: start from PRIME's current top instead of importing the
        // whole directory on a page load; users:sync-external does that.
        if (! is_numeric($mark)) {
            Cache::forever(self::HIGH_WATER_MARK_KEY, (int) ExternalUserAuthService::users()->max('user_id'));

            return;
        }

        $rows = self::withEmail(ExternalUserAuthService::users())
            ->where('user_id', '>', (int) $mark)
            ->where('user_status', ExternalUserAuthService::ACTIVE_STATUS)
            ->orderBy('user_id')
            ->limit(self::IMPORT_BATCH)
            ->get(self::LIVE_COLUMNS);

        if ($rows->isEmpty()) {
            // A mark above PRIME's top means PRIME was restored or re-seeded,
            // so ids at or below the old mark may be accounts IRIS never saw:
            // rescan from the start (IMPORT_BATCH per load; apply() only
            // writes what changed). users:sync-external resets it in one go.
            if ((int) ExternalUserAuthService::users()->max('user_id') < (int) $mark) {
                Cache::forever(self::HIGH_WATER_MARK_KEY, 0);
            }

            return;
        }

        // Duplicates are checked across all of PRIME, not just this batch:
        // the twin of a new row may sit below the mark.
        $emails = $rows->pluck('email_address')
            ->flatMap(fn (string $email) => [$email, mb_strtolower($email)])
            ->unique()
            ->values()
            ->all();
        $unique = self::uniqueByEmail(ExternalUserAuthService::users()->whereIn('email_address', $emails)->get(['user_id', 'email_address']));
        $byEmail = $rows->keyBy(fn (stdClass $row) => mb_strtolower($row->email_address))->only($unique->keys()->all());

        $locals = User::query()
            ->whereIn(DB::raw('LOWER(email)'), $byEmail->keys()->all())
            ->get()
            ->keyBy(fn (User $user) => mb_strtolower($user->email));

        foreach ($byEmail as $email => $row) {
            $this->applySafely($row, $locals->get($email));
        }

        // Past every row read, skipped or failed ones included, so one bad
        // PRIME row can never wedge the import.
        Cache::forever(self::HIGH_WATER_MARK_KEY, (int) $rows->max('user_id'));
    }

    /**
     * One bad PRIME row (e.g. an over-long email) is logged and skipped
     * instead of stopping the rest of the batch.
     */
    private function applySafely(stdClass $row, ?User $local): void
    {
        try {
            $this->apply($row, $local);
        } catch (Throwable $e) {
            Log::warning('PRIME user row skipped', ['prime_user_id' => $row->user_id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * PRIME rows keyed by lowercased email. An email on more than one row is
     * dropped (logged on the security channel, as login does): which row
     * is the real account can't be decided safely.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<string, stdClass>
     */
    private static function uniqueByEmail(Collection $rows): Collection
    {
        return $rows->groupBy(fn (stdClass $row) => mb_strtolower($row->email_address))
            ->filter(function (Collection $group, string $email): bool {
                if ($group->count() > 1) {
                    Log::channel('security')->warning('Ambiguous PRIME account: several rows share an email', ['email' => $email]);

                    return false;
                }

                return true;
            })
            ->map(fn (Collection $group) => $group->sole());
    }

    /**
     * PRIME rows without an email can't be matched to an IRIS account.
     */
    private static function withEmail(Builder $query): Builder
    {
        return $query->whereNotNull('email_address')->where('email_address', '!=', '');
    }

    /**
     * PRIME's full_name, cut to IRIS's column size; null when it's missing
     * or blank (logged with the PRIME id only, no PII).
     */
    private static function primeName(stdClass $row): ?string
    {
        $name = is_string($row->full_name ?? null) ? trim($row->full_name) : '';

        if ($name === '') {
            Log::warning('PRIME user has no name; name not synced', ['prime_user_id' => $row->user_id ?? null]);

            return null;
        }

        return mb_substr($name, 0, self::NAME_MAX);
    }

    public function syncUser(User $user): void
    {
        try {
            [$row, $assignment] = ExternalUserAuthService::guard(function () use ($user): array {
                $row = self::uniqueByEmail(ExternalUserAuthService::users()->where('email_address', $user->email)->get(self::COLUMNS))->first();

                return [$row, $row === null ? null : $this->assignment($row, $user)];
            });
        } catch (Throwable $e) {
            self::reportFailure($e);

            return;
        }

        if ($row !== null) {
            $this->apply($row, $user, $assignment);
        }
    }

    /**
     * @return array{numbers_set: int, numbers_changed: int, numbers_cleared: int, offices_set: int, offices_changed: int, offices_cleared: int}
     */
    private function assignAll(PrimeDirectory $directory): array
    {
        $counts = array_fill_keys(['numbers_set', 'numbers_changed', 'numbers_cleared', 'offices_set', 'offices_changed', 'offices_cleared'], 0);
        $officeByDepartment = $directory->liveDepartmentOffices();
        $wanted = [];

        foreach ($directory->userNumbers() as $id => $result) {
            if ($result['touched']) {
                $department = $result['department_id'];
                $wanted[$id] = ['employee_no' => $result['proposed'], 'office_id' => $department === null ? null : ($officeByDepartment[$department] ?? null)];
            }
        }

        $users = User::query()->whereKey(array_keys($wanted))->get()
            ->filter(fn (User $user) => $user->employee_no !== $wanted[$user->id]['employee_no']
                || self::officeId($user->office_id) !== $wanted[$user->id]['office_id']);

        DB::transaction(function () use ($users, $wanted, &$counts): void {
            $users->filter(fn (User $user) => $user->employee_no !== null && $user->employee_no !== $wanted[$user->id]['employee_no'])
                ->pluck('id')
                ->chunk(500)
                ->each(fn ($ids) => User::query()->whereKey($ids->all())->update(['employee_no' => null]));

            foreach ($users as $user) {
                $old = ['employee_no' => $user->employee_no, 'office_id' => self::officeId($user->office_id)];
                $new = $wanted[$user->id];

                $user->forceFill($new);
                $this->save($user, 'updated');

                foreach (['employee_no' => 'numbers', 'office_id' => 'offices'] as $field => $prefix) {
                    if ($old[$field] !== $new[$field]) {
                        $counts[$prefix.'_'.match (true) {
                            $old[$field] === null => 'set',
                            $new[$field] === null => 'cleared',
                            default => 'changed',
                        }]++;
                    }
                }
            }
        });

        return $counts;
    }

    /**
     * @return Assignment
     */
    private function assignment(stdClass $row, User $user): array
    {
        $directory = new PrimeDirectory;
        $account = $directory->account($row);
        $number = $account['employee_no'];

        if ($number !== null && User::query()->where('employee_no', $number)->whereKeyNot($user->id)->exists()) {
            $number = null;
        }

        $officeId = $account['department_id'] === null ? null
            : Office::query()->where('prime_department_id', $account['department_id'])->value('id');

        return ['employee_no' => $number, 'office_id' => self::officeId($officeId)];
    }

    /**
     * @param  Assignment|null  $assignment
     * @return 'created'|'updated'|'deactivated'|null
     */
    private function apply(stdClass $row, ?User $local, ?array $assignment = null): ?string
    {
        $active = $row->user_status === ExternalUserAuthService::ACTIVE_STATUS;
        $name = self::primeName($row);

        if (! $local) {
            if (! $active || $name === null) {
                return null;
            }

            $this->save(new User([
                'name' => $name,
                'email' => $row->email_address,
                'is_active' => true,
            ]), 'created');

            return 'created';
        }

        // A nameless PRIME row keeps the local name but still deactivates.
        if ($name !== null) {
            $local->name = $name;
        }

        $deactivating = ! $active && $local->is_active;

        if ($deactivating) {
            $local->is_active = false;
        }

        if ($assignment !== null) {
            $local->forceFill($assignment);
        }

        if (! $local->isDirty()) {
            return null;
        }

        $this->save($local, 'updated');

        return $deactivating ? 'deactivated' : 'updated';
    }

    private function save(User $user, string $event): void
    {
        $allowed = array_flip(self::AUDITED);
        $old = $user->exists ? array_intersect_key($user->getOriginal(), $user->getDirty(), $allowed) : [];
        $new = array_intersect_key($user->exists ? $user->getDirty() : $user->getAttributes(), $allowed);

        $user->disableLogging()->save();

        activity()
            ->performedOn($user)
            ->byAnonymous()
            ->event($event)
            ->withChanges(array_filter(['attributes' => $new, 'old' => $old]))
            ->log('Synced from PRIME');
    }

    private static function officeId(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }
}
