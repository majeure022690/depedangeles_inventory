<?php

namespace App\Models;

use Database\Factories\IspSubscriptionCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bare-bones structural model — fillable/casts/relationships only.
 * Append-only budget-period history, like IspSpeedTest. Backend owns:
 * any service/controller logic for recording new cost periods and any
 * budget-reporting scopes (e.g. current vs. projected totals, contracts
 * expiring soon). This model intentionally has no business logic.
 */
#[Fillable([
    'isp_account_id', 'recorded_by_user_id', 'contract_start_date', 'contract_end_date',
    'total_amount_spent', 'contract_projected_start_date', 'contract_projected_end_date',
    'total_projected_expenditure',
])]
class IspSubscriptionCost extends Model
{
    /** @use HasFactory<IspSubscriptionCostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'total_amount_spent' => 'decimal:2',
            'contract_projected_start_date' => 'date',
            'contract_projected_end_date' => 'date',
            'total_projected_expenditure' => 'decimal:2',
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
     * The logged-in system user who entered this subscription-cost
     * period — distinct from any Personnel subject; this model has none,
     * but the naming mirrors EquipmentTransaction::recordedBy() for
     * consistency.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
