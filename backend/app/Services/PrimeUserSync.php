<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrimeUserSync
{
    private const THROTTLE_SECONDS = 30;

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function run(): array
    {
        $prime = ExternalUserAuthService::users()
            ->get(['email_address', 'full_name', 'user_status', 'password'])
            ->keyBy(fn (object $row) => mb_strtolower($row->email_address));

        $locals = User::query()->get()->keyBy(fn (User $user) => mb_strtolower($user->email));

        $counts = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        foreach ($prime as $email => $row) {
            $active = $row->user_status === ExternalUserAuthService::ACTIVE_STATUS;
            $local = $locals->get($email);

            if (! $local) {
                if ($active) {
                    $this->save(new User([
                        'name' => $row->full_name,
                        'email' => $row->email_address,
                        'password' => $row->password,
                        'is_active' => true,
                    ]), 'created');
                    $counts['created']++;
                }

                continue;
            }

            $local->name = $row->full_name;

            if (! $active && $local->is_active) {
                $local->is_active = false;
                $counts['deactivated']++;
            }

            if ($local->isDirty()) {
                $this->save($local, 'updated');
                $counts['updated']++;
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
