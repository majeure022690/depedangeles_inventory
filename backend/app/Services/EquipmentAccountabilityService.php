<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned entry point for changing who is currently
 * accountable for a piece of equipment. Appends an append-only
 * equipment_transactions row and syncs Equipment's current_* pointers to
 * match it, atomically — see the ACCOUNTABILITY SYNC GUARD note on the
 * Equipment model for why direct updates to those columns are blocked.
 *
 * Sync policy: current_accountable_officer_id / current_end_user_id are
 * always overwritten to mirror the new transaction's accountable_officer_id
 * / end_user_id (including being cleared to null when the transaction
 * doesn't name one) — the current holder is defined as "whoever the most
 * recent transaction says it is," full stop. This keeps the mapping
 * trivial to reason about: no implicit "leave it unless told otherwise"
 * branching for particular transaction types.
 */
final class EquipmentAccountabilityService
{
    public function __construct(
        private readonly Guard $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordTransaction(Equipment $equipment, array $data): EquipmentTransaction
    {
        return DB::transaction(function () use ($equipment, $data) {
            $transaction = $equipment->transactions()->create([
                ...$data,
                'recorded_by_user_id' => $this->auth->id(),
            ]);

            Equipment::withAccountabilitySync(function () use ($equipment, $data) {
                $equipment->update([
                    'current_accountable_officer_id' => $data['accountable_officer_id'] ?? null,
                    'current_end_user_id' => $data['end_user_id'] ?? null,
                ]);
            });

            AuditLog::record('equipment.transaction.recorded', $equipment, [
                'transaction_id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'accountable_officer_id' => $transaction->accountable_officer_id,
                'end_user_id' => $transaction->end_user_id,
                'received_by_id' => $transaction->received_by_id,
            ]);

            return $transaction;
        });
    }
}
