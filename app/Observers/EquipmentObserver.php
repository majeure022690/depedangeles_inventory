<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Equipment;

/**
 * Auto-populates Equipment::$qr_code — see the QR CODE note on the
 * Equipment model for why that column is never user-writable.
 *
 * Encodes a URL to the record's edit page (`route('equipment.edit', ...)`),
 * not just the bare property_no: scanning the printed tag in the field
 * should take whoever scans it straight to the live record (current
 * condition, accountable officer, transaction history, ...), not merely
 * echo back an identifier they'd then have to manually search for. The
 * URL is keyed off the immutable `id`, not `property_no` (which CAN be
 * edited later), so once generated it never needs to change — the
 * `updated` hook below only exists to lazily backfill rows that predate
 * this feature (or somehow otherwise ended up without one), not to
 * regenerate an already-correct value.
 */
class EquipmentObserver
{
    public function created(Equipment $equipment): void
    {
        $this->syncQrCode($equipment);
    }

    public function updated(Equipment $equipment): void
    {
        $this->syncQrCode($equipment);
    }

    private function syncQrCode(Equipment $equipment): void
    {
        if (filled($equipment->qr_code)) {
            return;
        }

        // forceFill() bypasses $fillable (qr_code is deliberately absent
        // from it) and saveQuietly() bypasses the model event dispatcher
        // — both the ACCOUNTABILITY SYNC GUARD's `updating` listener
        // (irrelevant here; qr_code isn't one of the guarded columns) and
        // this very observer, which would otherwise re-fire on its own
        // save and recurse.
        $equipment->forceFill([
            'qr_code' => route('equipment.edit', $equipment),
        ])->saveQuietly();
    }
}
