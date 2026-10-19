<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IspSubscriptionCostStoreRequest;
use App\Models\AuditLog;
use App\Models\IspAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Append-only: isp_subscription_costs has no update/destroy routes by
 * design (see the model's doc-comment). This is the sole mutating
 * action — mirrors EquipmentTransactionController's shape.
 */
class IspSubscriptionCostController extends Controller
{
    public function store(IspSubscriptionCostStoreRequest $request, IspAccount $ispAccount): RedirectResponse
    {
        $subscriptionCost = $ispAccount->subscriptionCosts()->create([
            ...$request->validated(),
            'recorded_by_user_id' => $request->user()->id,
        ]);

        AuditLog::record('isp_account.subscription_cost.recorded', $ispAccount, [
            'subscription_cost_id' => $subscriptionCost->id,
            'total_amount_spent' => $subscriptionCost->total_amount_spent,
            'contract_start_date' => $subscriptionCost->contract_start_date?->toDateString(),
            'contract_end_date' => $subscriptionCost->contract_end_date?->toDateString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription cost recorded.')]);

        return to_route('isp-accounts.edit', $ispAccount);
    }
}
