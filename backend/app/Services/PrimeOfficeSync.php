<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Office;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type OfficeCounts array{linked: int, created: int, renamed: int, rename_skipped: int, ambiguous: int, leftovers: int, create_skipped: int, gone: int}
 */
final class PrimeOfficeSync
{
    private const AUDITED = ['office_name', 'office_type', 'prime_department_id', 'is_active'];

    /**
     * @return OfficeCounts
     */
    public function run(PrimeDirectory $prime): array
    {
        $plan = $prime->officePlan();

        DB::transaction(function () use ($plan): void {
            foreach ($plan['links'] as $link) {
                $this->save(Office::query()->findOrFail((int) $link['office']->id)->forceFill([
                    'prime_department_id' => (int) $link['department']->dept_id,
                ]), 'updated');
            }

            foreach ($plan['renames'] as $rename) {
                $this->save(Office::query()->findOrFail((int) $rename['office']->id)->forceFill([
                    'office_name' => $rename['to'],
                ]), 'updated');
            }

            foreach ($plan['creates'] as $create) {
                $this->save((new Office)->forceFill([
                    'office_name' => PrimeDirectory::displayName($create['department']->department_school),
                    'office_type' => $create['office_type'],
                    'prime_department_id' => (int) $create['department']->dept_id,
                    'is_active' => true,
                ]), 'created');
            }
        });

        $prime->forgetOffices();

        return [
            'linked' => count($plan['links']),
            'created' => count($plan['creates']),
            'renamed' => count($plan['renames']),
            'rename_skipped' => count($plan['rename_collisions']),
            'ambiguous' => count($plan['ambiguous']),
            'leftovers' => count($plan['leftovers']),
            'create_skipped' => count($plan['create_collisions']),
            'gone' => count($plan['gone']),
        ];
    }

    private function save(Office $office, string $event): void
    {
        $allowed = array_flip(self::AUDITED);
        $old = $office->exists ? array_intersect_key($office->getOriginal(), $office->getDirty(), $allowed) : [];
        $new = array_intersect_key($office->exists ? $office->getDirty() : $office->getAttributes(), $allowed);

        $office->disableLogging()->save();

        activity()
            ->performedOn($office)
            ->byAnonymous()
            ->event($event)
            ->withChanges(array_filter(['attributes' => $new, 'old' => $old]))
            ->log('Synced from PRIME');
    }
}
