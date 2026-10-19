<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Audit trail for the 13 reference-data models (config/reference-data.php).
 * Their fillable list is the allowlist (name or type/value, description,
 * sort_order, is_active; nothing sensitive), the description keeps the
 * `reference_data.<event>` names the old audit_logs rows used, and every
 * row records which registry table it came from.
 */
trait LogsReferenceDataActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "reference_data.{$event}");
    }

    /**
     * Called by spatie/laravel-activitylog just before the row is saved.
     */
    public function beforeActivityLogged(Model $activity, string $event): void
    {
        foreach (config()->array('reference-data') as $table => $entry) {
            if (is_array($entry) && ($entry['model'] ?? null) === static::class) {
                $properties = $activity->getAttribute('properties');
                $properties = $properties instanceof Collection ? $properties : new Collection;

                $activity->setAttribute('properties', $properties->put('table', $table));

                return;
            }
        }
    }
}
