<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
 * recent transaction says it is," full stop.
 *
 * Linked peripherals move with their desktop: every transaction recorded
 * on a desktop is copied onto each of its peripherals, so each
 * peripheral's own history still explains its current holder. The bundle
 * moves whole or not at all: if the acting user may not record a
 * transaction on every peripheral, nothing is written.
 */
final class EquipmentAccountabilityService
{
    private const COPIED_FIELDS = [
        'transaction_type', 'accountable_officer_id', 'end_user_id', 'received_by_id',
        'date_assigned_accountable_officer', 'date_assigned_end_user', 'date_received_new_accountable',
        'supporting_documents1', 'or_si_dr_iar_no', 'supporting_documents2', 'par_ics_rrsp_rs_wmr_no',
    ];

    public function __construct(
        private readonly Guard $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordTransaction(Equipment $equipment, array $data): EquipmentTransaction
    {
        $result = DB::transaction(function () use ($equipment, $data) {
            $peripherals = $equipment->children()->with('createdBy:id,office_id')->lockForUpdate()->get();

            $outOfReach = $peripherals->first(
                fn (Equipment $peripheral) => Gate::forUser($this->auth->user())->denies('createTransaction', $peripheral),
            );

            if ($outOfReach !== null) {
                return $outOfReach;
            }

            $transaction = $this->append($equipment, $data);

            $peripherals->each(fn (Equipment $peripheral) => $this->append($peripheral, $data, $transaction));

            return $transaction;
        });

        // Thrown after commit so the authorization.denied audit row survives.
        if ($result instanceof Equipment) {
            throw ValidationException::withMessages([
                'equipment' => __('A linked peripheral (:property_no) is outside your access; ask an administrator.', [
                    'property_no' => $result->property_no ?? "#{$result->id}",
                ]),
            ]);
        }

        return $result;
    }

    /**
     * Copies the desktop's latest transaction onto a peripheral that just
     * joined it. Null when the desktop has no transaction yet.
     */
    public function mirrorLatestTransaction(Equipment $parent, Equipment $peripheral): ?EquipmentTransaction
    {
        $latest = $parent->transactions()->latest('created_at')->latest('id')->first();

        if ($latest === null) {
            return null;
        }

        $data = [];

        foreach (self::COPIED_FIELDS as $field) {
            $data[$field] = $latest->getAttribute($field);
        }

        return DB::transaction(fn () => $this->append($peripheral, $data, $latest));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function append(Equipment $equipment, array $data, ?EquipmentTransaction $copiedFrom = null): EquipmentTransaction
    {
        $transaction = $equipment->transactions()->create([
            ...array_intersect_key($data, array_flip(self::COPIED_FIELDS)),
            'recorded_by_user_id' => $this->auth->id(),
        ]);

        Equipment::withAccountabilitySync(function () use ($equipment, $data) {
            $equipment->update([
                'current_accountable_officer_id' => $data['accountable_officer_id'] ?? null,
                'current_end_user_id' => $data['end_user_id'] ?? null,
            ]);
        });

        activity()
            ->performedOn($equipment)
            ->event('equipment.transaction.recorded')
            ->withProperties([
                'transaction_id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'accountable_officer_id' => $transaction->accountable_officer_id,
                'end_user_id' => $transaction->end_user_id,
                'received_by_id' => $transaction->received_by_id,
                ...($copiedFrom ? [
                    'copied_from_transaction_id' => $copiedFrom->id,
                    'parent_equipment_id' => $copiedFrom->equipment_id,
                ] : []),
            ])
            ->log('equipment.transaction.recorded');

        return $transaction;
    }
}
