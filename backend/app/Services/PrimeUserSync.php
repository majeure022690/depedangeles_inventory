<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Office;
use App\Models\User;
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
    private const THROTTLE_SECONDS = 30;

    private const COLUMNS = ['user_id', 'email_address', 'full_name', 'user_status', 'password', 'department_school_id'];

    private const AUDITED = ['name', 'email', 'is_active', 'employee_no', 'office_id'];

    public function __construct(private readonly PrimeOfficeSync $offices) {}

    /**
     * @return SyncCounts
     */
    public function run(): array
    {
        $directory = new PrimeDirectory;
        $directory->departments();
        $directory->employees();
        $directory->primeUsers();

        $prime = ExternalUserAuthService::users()
            ->get(self::COLUMNS)
            ->keyBy(fn (stdClass $row) => mb_strtolower($row->email_address));

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

        return [...$counts, ...$this->assignAll($directory), 'office_sync' => $officeCounts];
    }

    public function runThrottled(): void
    {
        if (! Cache::add('prime-user-sync', true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            $this->run();
        } catch (Throwable $e) {
            Log::warning('PRIME user sync failed; showing the local copy', ['error' => $e->getMessage()]);
        }
    }

    public function syncUser(User $user): void
    {
        try {
            $rows = ExternalUserAuthService::users()->where('email_address', $user->email)->get(self::COLUMNS);
            $assignment = $rows->count() === 1 ? $this->assignment($rows->sole(), $user) : ['employee_no' => null, 'office_id' => null];
        } catch (Throwable $e) {
            Log::warning('PRIME user sync failed; showing the local copy', ['error' => $e->getMessage()]);

            return;
        }

        if ($rows->isNotEmpty()) {
            $this->apply($rows->first(), $user, $assignment);
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

        if (! $local) {
            if (! $active) {
                return null;
            }

            $this->save(new User([
                'name' => $row->full_name,
                'email' => $row->email_address,
                'password' => $row->password,
                'is_active' => true,
            ]), 'created');

            return 'created';
        }

        $local->name = $row->full_name;
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
