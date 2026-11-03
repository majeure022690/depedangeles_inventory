<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * PPE found at station (COA Circular 2020-006): an item found during the count that is not on
 * the count's expected list, either an IRIS record held elsewhere or something with no record at all.
 */
#[Fillable(['office_id', 'equipment_id', 'description', 'property_no', 'serial_no', 'condition_id', 'location', 'remarks'])]
class InventoryCountFoundItem extends Model
{
    use LogsActivity;

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
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<EquipmentCondition, $this>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(EquipmentCondition::class, 'condition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['office_id', 'equipment_id', 'description', 'property_no', 'serial_no', 'condition_id', 'location', 'remarks'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "inventory_count_found_item.{$event}");
    }
}
