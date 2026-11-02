<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Enums\PropertyCategory;
use App\Enums\PropertySource;
use Database\Factories\PropertyItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * School IT equipment not tracked as individual Equipment records, counted
 * for the RPCPPE. One row may cover
 * many identical units: `quantity` is the balance per property card and
 * `on_hand_count` the latest physical count.
 *
 * @property PropertyCategory $category
 * @property PropertySource|null $source
 */
#[Fillable([
    'office_id', 'category', 'article', 'description', 'property_no', 'unit_of_measure',
    'date_acquired', 'unit_value', 'quantity', 'on_hand_count', 'source',
    'condition', 'with_are', 'accountable_officer_id', 'end_user_id', 'location', 'remarks',
])]
class PropertyItem extends Model
{
    /** @use HasFactory<PropertyItemFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const CONDITIONS = ['functional', 'unserviceable'];

    protected function casts(): array
    {
        return [
            'category' => PropertyCategory::class,
            'source' => PropertySource::class,
            'date_acquired' => 'date',
            'unit_value' => 'decimal:2',
            'quantity' => 'integer',
            'on_hand_count' => 'integer',
            'with_are' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
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
    public function endUser(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'end_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function totalValue(): ?float
    {
        return $this->unit_value === null ? null : round((float) $this->unit_value * $this->quantity, 2);
    }

    public function isSemiExpendable(): bool
    {
        return $this->unit_value === null || (float) $this->unit_value < Equipment::PPE_THRESHOLD;
    }

    /**
     * @param  Builder<PropertyItem>  $query
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

        $query->where($query->qualifyColumn('office_id'), $user->office_id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->reachesOffice($this->office_id);
    }

    /**
     * @param  Builder<PropertyItem>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('article', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhere('property_no', 'like', "%{$term}%"));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'office_id', 'category', 'article', 'description', 'property_no', 'unit_of_measure',
                'date_acquired', 'unit_value', 'quantity', 'on_hand_count', 'source',
                'condition', 'with_are', 'accountable_officer_id', 'end_user_id', 'location', 'remarks',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "property_item.{$event}");
    }
}
