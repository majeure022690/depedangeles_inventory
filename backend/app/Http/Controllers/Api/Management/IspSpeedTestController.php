<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreIspSpeedTestRequest;
use App\Http\Resources\Management\IspSpeedTestResource;
use App\Models\IspAccount;
use Illuminate\Http\JsonResponse;

/**
 * Append-only: isp_speed_tests has no update/destroy routes by design.
 * Authorized against `update` on the parent account.
 */
class IspSpeedTestController extends Controller
{
    public function store(StoreIspSpeedTestRequest $request, IspAccount $ispAccount): JsonResponse
    {
        $this->authorize('update', $ispAccount);

        $speedTest = $ispAccount->speedTests()->create([
            ...$request->validated(),
            'recorded_by_user_id' => $request->user()->id,
        ]);

        activity()
            ->performedOn($ispAccount)
            ->event('isp_account.speed_test.recorded')
            ->withProperties([
                'speed_test_id' => $speedTest->id,
                'download_speed' => $speedTest->download_speed,
                'upload_speed' => $speedTest->upload_speed,
                'ping' => $speedTest->ping,
                'signal_quality' => $speedTest->signal_quality,
            ])
            ->log('isp_account.speed_test.recorded');

        return new IspSpeedTestResource($speedTest)
            ->additional(['message' => 'Speed test recorded.'])
            ->response()
            ->setStatusCode(201);
    }
}
