import { History, Wallet } from 'lucide-react'
import { useState, type FormEvent } from 'react'

import { FormInput, fieldErrorsFrom, type FieldErrors } from '@/components/isp-accounts/IspAccountFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { DetailSection } from '@/components/ui/DetailSection'
import { extractError } from '@/lib/errors'
import { formatDateTime } from '@/lib/utils'
import { emptySubscriptionCostForm, ispAccountService } from '@/services/ispAccountService'
import type { IspSubscriptionCost, IspSubscriptionCostFormData } from '@/types/isp-account'

interface SubscriptionCostLogProps {
  ispAccountId: number
  subscriptionCosts: IspSubscriptionCost[]
  /** `isp_accounts.edit`; logging is authorized against updating the parent account. */
  canRecord: boolean
  onRecorded: (subscriptionCost: IspSubscriptionCost) => void
}

type Feedback = { type: 'success' | 'error'; message: string } | null

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

function formatCurrency(value: number | null): string {
  return value !== null ? currency.format(value) : '—'
}

function period(start: string | null, end: string | null): string {
  return `${start ?? '—'} to ${end ?? '—'}`
}

/** Append-only actual/projected spending history for one ISP account, with the "log a period" form for editors. */
export function SubscriptionCostLog({ ispAccountId, subscriptionCosts, canRecord, onRecorded }: SubscriptionCostLogProps) {
  const [form, setForm] = useState<IspSubscriptionCostFormData>(emptySubscriptionCostForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback>(null)

  function field(name: keyof IspSubscriptionCostFormData) {
    return {
      // Prefixed so the ids can't collide with the account form's own contract dates.
      id: `subscription_cost_${name}`,
      value: form[name],
      error: errors[name],
      disabled: saving,
      onChange: (e: { target: { value: string } }) => setForm((prev) => ({ ...prev, [name]: e.target.value })),
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFeedback(null)
    try {
      const response = await ispAccountService.recordSubscriptionCost(ispAccountId, form)
      onRecorded(response.data)
      setForm(emptySubscriptionCostForm())
      setFeedback({ type: 'success', message: response.message })
    } catch (error) {
      const fieldErrors = fieldErrorsFrom(error)
      if (fieldErrors) setErrors(fieldErrors)
      setFeedback({
        type: 'error',
        message: fieldErrors ? 'Unable to log the subscription cost. Please review the highlighted fields and try again.' : extractError(error),
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardContent className="p-0">
        {canRecord && (
          <DetailSection icon={<Wallet className="h-4 w-4" />} title="Log a subscription cost">
            <p className="mb-4 text-sm text-gray-600">
              Record actual and/or projected spending for a budget period. A period can be logged with only actuals, only projections, or a mix of
              both.
            </p>
            {feedback && (
              <Alert type={feedback.type} className="mb-4">
                <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
              </Alert>
            )}
            <form noValidate onSubmit={handleSubmit} aria-label="Log a subscription cost">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormInput {...field('contract_start_date')} label="Contract start date" type="date" />
                <FormInput {...field('contract_end_date')} label="Contract end date" type="date" />
                <FormInput {...field('total_amount_spent')} label="Total amount spent" type="number" step="0.01" min="0" inputMode="decimal" />
                <FormInput {...field('contract_projected_start_date')} label="Projected start date" type="date" />
                <FormInput {...field('contract_projected_end_date')} label="Projected end date" type="date" />
                <FormInput
                  {...field('total_projected_expenditure')}
                  label="Total projected expenditure"
                  type="number"
                  step="0.01"
                  min="0"
                  inputMode="decimal"
                />
              </div>
              <div className="mt-4 flex justify-end">
                <Button type="submit" isLoading={saving}>
                  Log subscription cost
                </Button>
              </div>
            </form>
          </DetailSection>
        )}

        <DetailSection icon={<History className="h-4 w-4" />} title="Subscription cost history" last>
          {subscriptionCosts.length === 0 ? (
            <p className="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">No subscription costs logged yet.</p>
          ) : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[640px] text-left text-sm">
                <caption className="sr-only">Subscription cost history, newest logged first</caption>
                <thead>
                  <tr className="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500">
                    <th scope="col" className="px-5 py-2 font-medium">Contract period</th>
                    <th scope="col" className="px-3 py-2 font-medium">Amount spent</th>
                    <th scope="col" className="px-3 py-2 font-medium">Projected period</th>
                    <th scope="col" className="px-3 py-2 font-medium">Projected expenditure</th>
                    <th scope="col" className="px-5 py-2 font-medium">Logged</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {subscriptionCosts.map((cost) => (
                    <tr key={cost.id}>
                      <td className="whitespace-nowrap px-5 py-2.5 text-xs text-gray-500">{period(cost.contract_start_date, cost.contract_end_date)}</td>
                      <td className="px-3 py-2.5 tabular-nums text-gray-900">{formatCurrency(cost.total_amount_spent)}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-xs text-gray-500">
                        {period(cost.contract_projected_start_date, cost.contract_projected_end_date)}
                      </td>
                      <td className="px-3 py-2.5 tabular-nums text-gray-900">{formatCurrency(cost.total_projected_expenditure)}</td>
                      <td className="whitespace-nowrap px-5 py-2.5 text-xs text-gray-500">{formatDateTime(cost.created_at) || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </DetailSection>
      </CardContent>
    </Card>
  )
}
