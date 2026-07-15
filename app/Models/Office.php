<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Every school and division-level office/unit under this division —
 * imported wholesale from the division's existing "offices" table (see
 * database/seeders/data/offices.json and OfficeSeeder). `school_id` is the
 * DepEd-assigned School ID code (e.g. 107022) and is only populated for
 * rows that are actual schools; division-level offices/units leave it
 * null. `office_type` is free text (matches the source data, which has no
 * fixed vocabulary — "Elementary School", "High School", "Division
 * Office", "Unit", and some rows with none at all).
 */
#[Fillable(['office_name', 'office_type', 'school_id', 'address', 'region', 'division', 'district', 'contact_number', 'email', 'is_active'])]
class Office extends Model
{
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Office>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('office_name');
    }

    /**
     * {value, label} pairs for every active office, in display order —
     * value is the row id (a real FK target), label includes the School ID
     * for actual schools so it's distinguishable from the auto-increment id
     * (e.g. "Angeles ES (107023)"), or just the office name for division-
     * level offices/units that have no school_id.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function activeOptions(): array
    {
        return static::query()
            ->active()
            ->get(['id', 'office_name', 'school_id'])
            ->map(static fn (Office $office): array => [
                'value' => $office->id,
                'label' => $office->school_id
                    ? "{$office->office_name} ({$office->school_id})"
                    : $office->office_name,
            ])
            ->all();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasOne<StakeholderProfile, $this>
     */
    public function stakeholderProfile(): HasOne
    {
        return $this->hasOne(StakeholderProfile::class);
    }

    /**
     * @return HasOne<InternetConnectivitySurvey, $this>
     */
    public function internetConnectivitySurvey(): HasOne
    {
        return $this->hasOne(InternetConnectivitySurvey::class);
    }
}
