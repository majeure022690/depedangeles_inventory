<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreIspSubscriptionCostRequest;
use App\Http\Resources\Management\IspSubscriptionCostResource;
use App\Models\IspAccount;
use Illuminate\Http\JsonResponse;

/**
 * Append-only: isp_subscription_costs has no update/destroy routes by
 * design. Authorized against `update` on the parent account.
 */
class IspSubscriptionCostController extends Controller
{
    public function store(StoreIspSubscriptionCostRequest $request, IspAccount $ispAccount): JsonResponse
    {
        $this->authorize('update', $ispAccount);

        $subscriptionCost = $ispAccount->subscriptionCosts()->create([
            ...$request->validated(),
            'recorded_by_user_id' => $request->user()->id,
        ]);

        activity()
            ->performedOn($ispAccount)
            ->event('isp_account.subscription_cost.recorded')
            ->withProperties([
                'subscription_cost_id' => $subscriptionCost->id,
                'total_amount_spent' => $subscriptionCost->total_amount_spent,
                'contract_start_date' => $subscriptionCost->contract_start_date?->toDateString(),
                'contract_end_date' => $subscriptionCost->contract_end_date?->toDateString(),
            ])
            ->log('isp_account.subscription_cost.recorded');

        return new IspSubscriptionCostResource($subscriptionCost)
            ->additional(['message' => 'Subscription cost recorded.'])
            ->response()
            ->setStatusCode(201);
    }
}
