<?php

namespace App\Models;

use App\Enums\DataScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One physical count round over a set of offices. Opening it copies each in-custody item
 * into `items` with the values on record at that moment, which the counted values are compared against.
 *
 * @property list<int> $office_ids
 * @property list<int>|null $committee_member_ids
 */
#[Fillable(['name', 'as_of_date', 'office_ids', 'committee_chair_id', 'committee_member_ids', 'coa_representative', 'remarks'])]
class InventoryCount extends Model
{
    use LogsActivity;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'office_ids' => 'array',
            'committee_member_ids' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InventoryCountItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }

    /**
     * @return HasMany<InventoryCountFoundItem, $this>
     */
    public function foundItems(): HasMany
    {
        return $this->hasMany(InventoryCountFoundItem::class);
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function committeeChair(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'committee_chair_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * The offices in this count the user's data scope reaches.
     *
     * @return list<int>
     */
    public function officeIdsFor(User $user): array
    {
        return array_values(array_filter($this->office_ids, fn (int $id) => $user->reachesOffice($id)));
    }

    /**
     * @param  Builder<InventoryCount>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->dataScope() === DataScope::All) {
            return;
        }

        if ($user->office_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereJsonContains($query->qualifyColumn('office_ids'), $user->office_id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->officeIdsFor($user) !== [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'as_of_date', 'status', 'office_ids', 'committee_chair_id', 'committee_member_ids',
                'coa_representative', 'remarks', 'opened_at', 'closed_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "inventory_count.{$event}");
    }
}
