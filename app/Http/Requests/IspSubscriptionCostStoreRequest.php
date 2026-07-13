<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IspSubscriptionCostStoreRequest extends FormRequest
{
    /**
     * Logging a subscription-cost period authorizes against `update` on
     * the parent IspAccount — see IspSpeedTestStoreRequest's doc-comment
     * for the rationale (no separate permission for these append-only logs).
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ispAccount'));
    }

    /**
     * Every column is nullable per the source data (see the
     * create_isp_subscription_costs_table migration's doc-comment): a
     * budget period can be recorded with only actuals, only projections,
     * or a partial mix — so date-order rules are deliberately NOT cross-
     * validated against their sibling date (start/end pairs can each be
     * absent independently), matching how the rest of the app doesn't
     * enforce start-before-end ordering elsewhere either.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'total_amount_spent' => ['nullable', 'numeric', 'min:0'],

            'contract_projected_start_date' => ['nullable', 'date'],
            'contract_projected_end_date' => ['nullable', 'date'],
            'total_projected_expenditure' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
