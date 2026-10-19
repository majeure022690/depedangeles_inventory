<?php

namespace App\Http\Resources\Management;

use App\Models\IspSpeedTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IspSpeedTest
 */
class IspSpeedTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'isp_account_id' => $this->isp_account_id,
            'download_speed' => (float) $this->download_speed,
            'upload_speed' => (float) $this->upload_speed,
            'ping' => $this->ping,
            'tested_at' => $this->tested_at,
            'signal_quality' => $this->signal_quality,
            'rate_isp_service' => $this->rate_isp_service,
            'created_at' => $this->created_at,
        ];
    }
}
