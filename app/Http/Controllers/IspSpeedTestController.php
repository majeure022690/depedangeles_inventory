<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IspSpeedTestStoreRequest;
use App\Models\AuditLog;
use App\Models\IspAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Append-only: isp_speed_tests has no update/destroy routes by design
 * (see the model's doc-comment). This is the sole mutating action —
 * mirrors EquipmentTransactionController's shape.
 */
class IspSpeedTestController extends Controller
{
    public function store(IspSpeedTestStoreRequest $request, IspAccount $ispAccount): RedirectResponse
    {
        $speedTest = $ispAccount->speedTests()->create([
            ...$request->validated(),
            'recorded_by_user_id' => $request->user()->id,
        ]);

        AuditLog::record('isp_account.speed_test.recorded', $ispAccount, [
            'speed_test_id' => $speedTest->id,
            'download_speed' => $speedTest->download_speed,
            'upload_speed' => $speedTest->upload_speed,
            'ping' => $speedTest->ping,
            'signal_quality' => $speedTest->signal_quality,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Speed test recorded.')]);

        return to_route('isp-accounts.edit', $ispAccount);
    }
}
