<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Audit trail for the 13 reference-data models (config/reference-data.php).
 * The allowlist is explicit per registry tier (tier 1: name, plus
 * abbreviation and dashboard_group on item types and the default_* codes on equipment
 * classifications; tier 2
 * libraries: type/value; both: description, sort_order, is_active; nothing
 * sensitive), the description keeps the `reference_data.<event>` names the
 * old audit_logs rows used, and every row records which registry table it
 * came from.
 */
trait LogsReferenceDataActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $identity = ($this->referenceDataRegistryEntry()[1]['tier'] ?? 1) === 2
            ? ['type', 'value']
            : ['name'];

        return LogOptions::defaults()
            ->logOnly([
                ...$identity,
                ...($this->isFillable('abbreviation') ? ['abbreviation'] : []),
                ...($this->isFillable('dashboard_group') ? ['dashboard_group'] : []),
                ...($this->isFillable('default_gl_sl_code') ? ['default_gl_sl_code', 'default_uacs', 'default_useful_life'] : []),
                'description', 'sort_order', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "reference_data.{$event}");
    }

    /**
     * Called by spatie/laravel-activitylog just before the row is saved.
     */
    public function beforeActivityLogged(Model $activity, string $event): void
    {
        $entry = $this->referenceDataRegistryEntry();

        if ($entry === null) {
            return;
        }

        $properties = $activity->getAttribute('properties');
        $properties = $properties instanceof Collection ? $properties : new Collection;

        $activity->setAttribute('properties', $properties->put('table', $entry[0]));
    }

    /**
     * @return array{0: string, 1: array<mixed>}|null
     */
    private function referenceDataRegistryEntry(): ?array
    {
        foreach (config()->array('reference-data') as $table => $entry) {
            if (is_array($entry) && ($entry['model'] ?? null) === static::class) {
                return [(string) $table, $entry];
            }
        }

        return null;
    }
}
