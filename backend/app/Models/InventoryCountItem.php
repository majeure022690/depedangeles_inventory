<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One expected item in a count: `office_id`, `condition_id`, `accountable_officer_id` and `location`
 * are what the Equipment record said when the count opened; the `found_*` columns are what the counter saw.
 */
#[Fillable(['result', 'found_condition_id', 'found_holder_id', 'found_location', 'remarks'])]
class InventoryCountItem extends Model
{
    use LogsActivity;

    public const PENDING = 'pending';

    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const RESULTS = [self::PENDING, self::FOUND, self::NOT_FOUND];

    public const BUCKETS = ['pending', 'matched', 'condition_changed', 'holder_changed', 'not_found'];

    public const UNSERVICEABLE_CONDITIONS = ['Unserviceable', 'For Repair'];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<InventoryCount, $this>
     */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return BelongsTo<EquipmentCondition, $this>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(EquipmentCondition::class, 'condition_id');
    }

    /**
     * @return BelongsTo<EquipmentCondition, $this>
     */
    public function foundCondition(): BelongsTo
    {
        return $this->belongsTo(EquipmentCondition::class, 'found_condition_id');
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function accountableOfficer(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'accountable_officer_id');
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function foundHolder(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'found_holder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }

    public function conditionChanged(): bool
    {
        return $this->result === self::FOUND && $this->found_condition_id !== $this->condition_id;
    }

    public function holderChanged(): bool
    {
        return $this->result === self::FOUND
            && ($this->found_holder_id !== $this->accountable_officer_id || trim((string) $this->found_location) !== trim((string) $this->location));
    }

    public function bucket(): string
    {
        return match (true) {
            $this->result === self::PENDING => 'pending',
            $this->result === self::NOT_FOUND => 'not_found',
            $this->conditionChanged() => 'condition_changed',
            $this->holderChanged() => 'holder_changed',
            default => 'matched',
        };
    }

    /**
     * Items in one comparison bucket; a found item can be in both changed buckets.
     *
     * @param  Builder<InventoryCountItem>  $query
     */
    public function scopeBucket(Builder $query, ?string $bucket): void
    {
        $conditionChanged = 'COALESCE(found_condition_id, 0) <> COALESCE(condition_id, 0)';
        $holderChanged = "(COALESCE(found_holder_id, 0) <> COALESCE(accountable_officer_id, 0) OR TRIM(COALESCE(found_location, '')) <> TRIM(COALESCE(location, '')))";
        $found = $query->qualifyColumn('result');

        match ($bucket) {
            'pending' => $query->where($found, self::PENDING),
            'not_found' => $query->where($found, self::NOT_FOUND),
            'condition_changed' => $query->where($found, self::FOUND)->whereRaw($conditionChanged),
            'holder_changed' => $query->where($found, self::FOUND)->whereRaw($holderChanged),
            'matched' => $query->where($found, self::FOUND)->whereRaw("NOT ({$conditionChanged}) AND NOT {$holderChanged}"),
            'discrepancies' => $query->whereRaw("(result = ? OR (result = ? AND ({$conditionChanged} OR {$holderChanged})))", [self::NOT_FOUND, self::FOUND]),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['result', 'found_condition_id', 'found_holder_id', 'found_location', 'remarks'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "inventory_count_item.{$event}");
    }
}
