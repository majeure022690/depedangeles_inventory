<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log — Backend should not expose update/delete routes
 * for this model in normal operation, and owns the Observer/service logic
 * that creates these rows and keeps Equipment's current_* pointers in
 * sync. No factory was requested for this model; add one if Backend
 * needs it for tests.
 */
#[Fillable([
    'equipment_id', 'recorded_by_user_id', 'transaction_type', 'accountable_officer_id',
    'end_user_id', 'received_by_id', 'date_assigned_accountable_officer',
    'date_assigned_end_user', 'date_received_new_accountable',
    'supporting_documents1', 'or_si_dr_iar_no', 'supporting_documents2',
    'par_ics_rrsp_rs_wmr_no',
])]
class EquipmentTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'date_assigned_accountable_officer' => 'date',
            'date_assigned_end_user' => 'date',
            'date_received_new_accountable' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
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
     * @return BelongsTo<Personnel, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'received_by_id');
    }

    /**
     * The logged-in system user who entered this transaction — distinct
     * from the Personnel referenced above, who are the subjects of the
     * transaction, not necessarily the ones who recorded it.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
