<?php

namespace App\Models;

use Database\Factories\IspSpeedTestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bare-bones structural model — fillable/casts/relationships only.
 * Append-only time-series log, like EquipmentTransaction. Backend owns:
 * any service/controller logic for recording new tests and any
 * chart/report scopes (e.g. latest test per account, average speed over
 * a date range). This model intentionally has no business logic.
 *
 * NOTE: min_speed/max_speed (the promised plan bandwidth) are NOT
 * columns on this table — read them off the parent IspAccount. See the
 * migration's SCHEMA DECISION comment for the full rationale.
 */
#[Fillable([
    'isp_account_id', 'recorded_by_user_id', 'download_speed', 'upload_speed', 'ping',
    'tested_at', 'signal_quality', 'rate_isp_service',
])]
class IspSpeedTest extends Model
{
    /** @use HasFactory<IspSpeedTestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'download_speed' => 'decimal:2',
            'upload_speed' => 'decimal:2',
            'ping' => 'integer',
            'tested_at' => 'datetime',
            'rate_isp_service' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IspAccount, $this>
     */
    public function ispAccount(): BelongsTo
    {
        return $this->belongsTo(IspAccount::class);
    }

    /**
     * The logged-in system user who entered this speed test — distinct
     * from any Personnel subject; this model has none, but the naming
     * mirrors EquipmentTransaction::recordedBy() for consistency.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
