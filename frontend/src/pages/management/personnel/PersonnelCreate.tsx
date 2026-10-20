import { ArrowLeft } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { PersonnelFields, emptyPersonnelForm } from '@/components/personnel/PersonnelFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { personnelService, toPersonnelPayload } from '@/services/personnelService'
import type { PersonnelFormData, PersonnelOptions } from '@/types/personnel'

export function PersonnelCreate() {
  usePageTitle('Add Personnel')
  const navigate = useNavigate()

  const [options, setOptions] = useState<PersonnelOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  useEffect(() => {
    personnelService
      .options()
      .then(setOptions)
      .catch((e: unknown) => setOptionsError(extractError(e, 'Unable to load the form options. Please reload the page.')))
  }, [])

  const [form, setForm] = useState<PersonnelFormData>(emptyPersonnelForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const response = await personnelService.create(toPersonnelPayload(form))
      navigate('/personnel', { state: { flash: response.message } })
    } catch (e) {
      setErrors(extractFieldErrors(e))
      setFormError(extractError(e, 'Unable to save this personnel record. Please try again.'))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/personnel')} aria-label="Back to personnel" className="mt-0.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Add Personnel</h1>
          <p className="text-sm text-gray-600">Create a new personnel record.</p>
        </div>
      </div>

      <form onSubmit={submit} noValidate className="space-y-4">
        <div role="alert" className="space-y-2 empty:hidden">
          {optionsError && <Alert type="error">{optionsError}</Alert>}
          {formError && <Alert type="error">{formError}</Alert>}
        </div>

        <PersonnelFields value={form} onChange={setForm} options={options} errors={errors} />

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => navigate('/personnel')}>
            Cancel
          </Button>
          <Button type="submit" isLoading={saving} disabled={!options}>
            Save personnel
          </Button>
        </div>
      </form>
    </div>
  )
}
