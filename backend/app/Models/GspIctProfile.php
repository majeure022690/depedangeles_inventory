<?php

namespace App\Models;

use App\Enums\DataScope;
use Database\Factories\GspIctProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One office's Government School Profile Part VI for one school year: the
 * answers IRIS can't derive (radio-based instruction, DICT Free WiFi,
 * service reliability, signatories). Equipment and ISP counts come live
 * from their own records when the workbook is generated.
 *
 * @property array<string, array{literacy: int, numeracy: int}>|null $radio_teachers keyed grade_1..grade_6
 * @property array<int, string>|null $isp_reliability ISP account id => reliability rating
 */
#[Fillable([
    'office_id', 'school_year', 'as_of', 'level', 'radio_teachers', 'dict_free_wifi',
    'dict_free_wifi_installed', 'isp_reliability', 'school_head_name', 'school_head_position',
    'ito_name', 'planning_officer_name',
])]
class GspIctProfile extends Model
{
    /** @use HasFactory<GspIctProfileFactory> */
    use HasFactory, LogsActivity;

    public const RADIO_GRADES = ['grade_1', 'grade_2', 'grade_3', 'grade_4', 'grade_5', 'grade_6'];

    public const DICT_FREE_WIFI = ['with_internet' => 'Yes, with internet connection', 'without_internet' => 'Yes, but without internet connection', 'not_recipient' => 'Not a recipient'];

    public const RELIABILITY = ['Highly Reliable', 'Reliable', 'Unstable', 'Poor'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'radio_teachers' => 'array',
            'isp_reliability' => 'array',
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
     * @param  Builder<GspIctProfile>  $query
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'office_id', 'school_year', 'as_of', 'level', 'radio_teachers', 'dict_free_wifi',
                'dict_free_wifi_installed', 'isp_reliability', 'school_head_name', 'school_head_position',
                'ito_name', 'planning_officer_name',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "gsp_ict_profile.{$event}");
    }
}
