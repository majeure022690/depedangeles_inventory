<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

final class PrimeUserSync
{
    private const THROTTLE_SECONDS = 30;

    private const COLUMNS = ['email_address', 'full_name', 'user_status', 'password'];

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function run(): array
    {
        $prime = ExternalUserAuthService::users()
            ->get(self::COLUMNS)
            ->keyBy(fn (stdClass $row) => mb_strtolower($row->email_address));

        $locals = User::query()->get()->keyBy(fn (User $user) => mb_strtolower($user->email));

        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        foreach ($prime as $email => $row) {
            $result = $this->apply($row, $locals->get($email));

            if ($result !== null) {
                $counts[$result]++;
                $counts['updated'] += $result === 'deactivated' ? 1 : 0;
            }
        }

        return $counts;
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
            $row = ExternalUserAuthService::users()->where('email_address', $user->email)->first(self::COLUMNS);
        } catch (Throwable $e) {
            Log::warning('PRIME user sync failed; showing the local copy', ['error' => $e->getMessage()]);

            return;
        }

        if ($row) {
            $this->apply($row, $user);
        }
    }

    /**
     * @return 'created'|'updated'|'deactivated'|null
     */
    private function apply(stdClass $row, ?User $local): ?string
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

        if (! $local->isDirty()) {
            return null;
        }

        $this->save($local, 'updated');

        return $deactivating ? 'deactivated' : 'updated';
    }

    private function save(User $user, string $event): void
    {
        $old = $user->exists ? array_intersect_key($user->getOriginal(), $user->getDirty()) : [];
        $new = array_intersect_key($user->getAttributes(), array_flip(['name', 'email', 'is_active']));
        $new = $user->exists ? array_intersect_key($new, $user->getDirty()) : $new;

        $user->disableLogging()->save();

        activity()
            ->performedOn($user)
            ->byAnonymous()
            ->event($event)
            ->withChanges(array_filter(['attributes' => $new, 'old' => $old]))
            ->log('Synced from PRIME');
    }
}
