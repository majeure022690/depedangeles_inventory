<?php

namespace App\Http\Resources\Management;

use App\Models\IspSubscriptionCost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IspSubscriptionCost
 */
class IspSubscriptionCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'isp_account_id' => $this->isp_account_id,
            'contract_start_date' => $this->contract_start_date?->toDateString(),
            'contract_end_date' => $this->contract_end_date?->toDateString(),
            'total_amount_spent' => $this->total_amount_spent !== null ? (float) $this->total_amount_spent : null,
            'contract_projected_start_date' => $this->contract_projected_start_date?->toDateString(),
            'contract_projected_end_date' => $this->contract_projected_end_date?->toDateString(),
            'total_projected_expenditure' => $this->total_projected_expenditure !== null ? (float) $this->total_projected_expenditure : null,
            'created_at' => $this->created_at,
        ];
    }
}
