import { Gauge, History } from 'lucide-react'
import { useState, type FormEvent } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/utils'
import { emptySpeedTestForm, ispAccountService } from '@/services/ispAccountService'
import type { IspSpeedTest, IspSpeedTestFormData } from '@/types/isp-account'
import type { Option } from '@/types/lookup'

interface SpeedTestLogProps {
  ispAccountId: number
  speedTests: IspSpeedTest[]
  signalQualityOptions: Option<string>[]
  /** `isp_accounts.edit`; logging is authorized against updating the parent account. */
  canRecord: boolean
  onRecorded: (speedTest: IspSpeedTest) => void
}

type Feedback = { type: 'success' | 'error'; message: string } | null

/** Append-only speed test history for one ISP account, with the "log a test" form for editors. */
export function SpeedTestLog({ ispAccountId, speedTests, signalQualityOptions, canRecord, onRecorded }: SpeedTestLogProps) {
  const [form, setForm] = useState<IspSpeedTestFormData>(emptySpeedTestForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback>(null)

  function field(name: keyof IspSpeedTestFormData) {
    return {
      // Prefixed so the ids can't collide with the account form's own `rate_isp_service`.
      id: `speed_test_${name}`,
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
      const response = await ispAccountService.recordSpeedTest(ispAccountId, form)
      onRecorded(response.data)
      setForm(emptySpeedTestForm())
      setFeedback({ type: 'success', message: response.message })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) setErrors(fieldErrors)
      setFeedback({ type: 'error', message: fieldErrors ? 'Unable to log the speed test. Please review the highlighted fields and try again.' : extractError(error) })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardContent className="p-0">
        {canRecord && (
          <DetailSection icon={<Gauge className="h-4 w-4" />} title="Log a speed test">
            <p className="mb-4 text-sm text-gray-600">
              Record a connection speed test result for this account. Every test is kept as an append-only history entry.
            </p>
            {feedback && (
              <Alert type={feedback.type} className="mb-4">
                <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
              </Alert>
            )}
            <form noValidate onSubmit={handleSubmit} aria-label="Log a speed test">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Input {...field('download_speed')} label="Download speed (Mbps)" type="number" step="0.01" min="0" inputMode="decimal" />
                <Input {...field('upload_speed')} label="Upload speed (Mbps)" type="number" step="0.01" min="0" inputMode="decimal" />
                <Input {...field('ping')} label="Ping (ms) (optional)" type="number" min="0" inputMode="numeric" />
                <Input {...field('tested_at')} label="Tested at" type="datetime-local" />
                <Select {...field('signal_quality')} label="Signal quality" placeholder="Select a signal quality" options={signalQualityOptions} />
                <Input {...field('rate_isp_service')} label="ISP service rating (1–5) (optional)" type="number" min="1" max="5" inputMode="numeric" />
              </div>
              <div className="mt-4 flex justify-end">
                <Button type="submit" isLoading={saving}>
                  Log speed test
                </Button>
              </div>
            </form>
          </DetailSection>
        )}

        <DetailSection icon={<History className="h-4 w-4" />} title="Speed test history" last>
          {speedTests.length === 0 ? (
            <p className="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">No speed tests logged yet.</p>
          ) : (
            <div className="-mx-5 overflow-x-auto">
              <table className="w-full min-w-[640px] text-left text-sm">
                <caption className="sr-only">Speed test history, newest logged first</caption>
                <thead>
                  <tr className="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500">
                    <th scope="col" className="px-5 py-2 font-medium">Download</th>
                    <th scope="col" className="px-3 py-2 font-medium">Upload</th>
                    <th scope="col" className="px-3 py-2 font-medium">Ping</th>
                    <th scope="col" className="px-3 py-2 font-medium">Signal quality</th>
                    <th scope="col" className="px-3 py-2 font-medium">Tested at</th>
                    <th scope="col" className="px-5 py-2 font-medium">Logged</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {speedTests.map((test) => (
                    <tr key={test.id}>
                      <td className="px-5 py-2.5 tabular-nums text-gray-900">{test.download_speed} Mbps</td>
                      <td className="px-3 py-2.5 tabular-nums text-gray-900">{test.upload_speed} Mbps</td>
                      <td className="px-3 py-2.5 tabular-nums text-gray-700">{test.ping ?? '—'}</td>
                      <td className="px-3 py-2.5 text-gray-700">{test.signal_quality}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-xs text-gray-500">{formatDateTime(test.tested_at) || '—'}</td>
                      <td className="whitespace-nowrap px-5 py-2.5 text-xs text-gray-500">{formatDateTime(test.created_at) || '—'}</td>
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
