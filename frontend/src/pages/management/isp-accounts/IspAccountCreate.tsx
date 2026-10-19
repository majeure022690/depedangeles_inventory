import { ArrowLeft, Wifi } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { IspAccountFields } from '@/components/isp-accounts/IspAccountFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { emptyIspAccountForm, ispAccountService } from '@/services/ispAccountService'
import type { IspAccountFormData, IspAccountOptions } from '@/types/isp-account'

export function IspAccountCreate() {
  usePageTitle('Add ISP account')
  const navigate = useNavigate()

  const [options, setOptions] = useState<IspAccountOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  const [form, setForm] = useState<IspAccountFormData>(emptyIspAccountForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    ispAccountService
      .options()
      .then(setOptions)
      .catch((error: unknown) => setOptionsError(extractError(error, 'Unable to load the form options. Please refresh the page.')))
  }, [])

  function setField<K extends keyof IspAccountFormData>(field: K, value: IspAccountFormData[K]) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setErrorMessage(null)
    try {
      const response = await ispAccountService.create(form)
      navigate(`/portal/isp-accounts/${response.data.id}/edit`, { state: { flash: response.message } })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) setErrors(fieldErrors)
      setErrorMessage(fieldErrors ? 'Unable to save the ISP account. Please review the highlighted fields and try again.' : extractError(error))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex min-w-0 items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/portal/isp-accounts')} aria-label="Back to ISP accounts" className="mt-1.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700" aria-hidden="true">
          <Wifi className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-gray-900">Add ISP account</h1>
          <p className="text-sm text-gray-600">Register a new internet service subscription.</p>
        </div>
      </div>

      {optionsError && (
        <Alert type="error" className="mb-4">
          <span role="alert">{optionsError}</span>
        </Alert>
      )}
      {errorMessage && (
        <Alert type="error" className="mb-4">
          <span role="alert">{errorMessage}</span>
        </Alert>
      )}

      <form noValidate onSubmit={handleSubmit} aria-label="Add ISP account">
        <Card>
          <CardContent className="p-0">
            <IspAccountFields form={form} onChange={setField} options={options?.form ?? null} errors={errors} disabled={saving} />
          </CardContent>
          <div className="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3">
            <Button variant="outline" onClick={() => navigate('/portal/isp-accounts')} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving} disabled={!options}>
              Save ISP account
            </Button>
          </div>
        </Card>
      </form>
    </div>
  )
}
