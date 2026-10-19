import { ArrowLeft } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { emptyOfficeForm, OfficeFields, officeFieldErrors, toOfficePayload, type OfficeFieldErrors } from '@/components/offices/OfficeFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import { officeService } from '@/services/officeService'
import type { OfficeFormData } from '@/types/office'

export function OfficeCreate() {
  usePageTitle('Add office')
  const navigate = useNavigate()
  const [form, setForm] = useState<OfficeFormData>(emptyOfficeForm)
  const [errors, setErrors] = useState<OfficeFieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const office = await officeService.create(toOfficePayload(form))
      navigate('/portal/offices', { state: { flash: `"${office.office_name}" was added.` } })
    } catch (error) {
      const fieldErrors = officeFieldErrors(error)
      setErrors(fieldErrors ?? {})
      setFormError(fieldErrors ? 'Please correct the highlighted fields.' : extractError(error, 'Unable to save this office. Please try again.'))
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/portal/offices')} aria-label="Back to offices" className="mt-1">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Add office</h1>
          <p className="text-sm text-gray-600">Register a new school or division-level office/unit.</p>
        </div>
      </div>

      {formError && (
        <Alert type="error" className="mb-4">
          {formError}
        </Alert>
      )}

      <form onSubmit={handleSubmit} noValidate>
        <OfficeFields value={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} errors={errors} disabled={saving} />

        <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => navigate('/portal/offices')} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" isLoading={saving}>
            Save office
          </Button>
        </div>
      </form>
    </div>
  )
}
