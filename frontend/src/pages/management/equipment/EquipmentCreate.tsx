import { ArrowLeft } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { EquipmentFields, emptyEquipmentForm } from '@/components/equipment/EquipmentFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { equipmentService, toEquipmentPayload } from '@/services/equipmentService'
import type { EquipmentFormData, EquipmentFormOptions } from '@/types/equipment'

export function EquipmentCreate() {
  usePageTitle('Add Equipment')
  const navigate = useNavigate()

  const [options, setOptions] = useState<EquipmentFormOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  useEffect(() => {
    equipmentService
      .options()
      .then((data) => setOptions(data.form))
      .catch((e: unknown) => setOptionsError(extractError(e, 'Unable to load the form options. Please reload the page.')))
  }, [])

  const [form, setForm] = useState<EquipmentFormData>(emptyEquipmentForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const response = await equipmentService.create(toEquipmentPayload(form))
      navigate(`/equipment/${response.data.id}/edit`, { state: { flash: response.message } })
    } catch (e) {
      setErrors(extractFieldErrors(e))
      setFormError(extractError(e, 'Unable to save this equipment record. Please try again.'))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/equipment')} aria-label="Back to equipment" className="mt-0.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Add Equipment</h1>
          <p className="text-sm text-gray-600">Register a new equipment item. Accountability is assigned afterward by logging a transaction.</p>
        </div>
      </div>

      <form onSubmit={submit} noValidate className="space-y-4">
        <div role="alert" className="empty:hidden">
          {optionsError && <Alert type="error">{optionsError}</Alert>}
          {formError && <Alert type="error">{formError}</Alert>}
        </div>

        <EquipmentFields value={form} onChange={setForm} options={options} errors={errors} />

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => navigate('/equipment')}>
            Cancel
          </Button>
          <Button type="submit" isLoading={saving} disabled={!options}>
            Save equipment
          </Button>
        </div>
      </form>
    </div>
  )
}
