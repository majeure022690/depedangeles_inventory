import { ArrowLeft } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import {
  EQUIPMENT_FIELD_SECTIONS,
  EquipmentFields,
  emptyEquipmentForm,
  firstSectionWithError,
  isEquipmentFieldSection,
  sectionErrorCount,
} from '@/components/equipment/EquipmentFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { scopedTitle } from '@/lib/dataScope'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { equipmentService, toEquipmentPayload } from '@/services/equipmentService'
import type { EquipmentFormData, EquipmentOptions } from '@/types/equipment'

const TABS_ID = 'equipment-create'

export function EquipmentCreate() {
  usePageTitle('Add Equipment')
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { user } = useAuth()
  const listTitle = scopedTitle('equipment', user?.data_scope)

  const [options, setOptions] = useState<EquipmentOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  useEffect(() => {
    equipmentService
      .options()
      .then(setOptions)
      .catch((e: unknown) => setOptionsError(extractError(e, 'Unable to load the form options. Please reload the page.')))
  }, [])

  const [form, setForm] = useState<EquipmentFormData>(emptyEquipmentForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const requestedTab = searchParams.get('tab')
  const tab = isEquipmentFieldSection(requestedTab) ? requestedTab : EQUIPMENT_FIELD_SECTIONS[0].value

  const selectTab = useCallback(
    (next: string) => {
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === EQUIPMENT_FIELD_SECTIONS[0].value) params.delete('tab')
          else params.set('tab', next)
          return params
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const response = await equipmentService.create(toEquipmentPayload(form))
      navigate(`/equipment/${response.data.id}/edit`, { state: { flash: response.message } })
    } catch (e) {
      const fieldErrors = extractFieldErrors(e)
      setErrors(fieldErrors)
      setFormError(extractError(e, 'Unable to save this equipment record. Please try again.'))
      const errorTab = firstSectionWithError(fieldErrors)
      if (errorTab) selectTab(errorTab)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(false)
    }
  }

  const tabs = EQUIPMENT_FIELD_SECTIONS.map((section) => ({ value: section.value, label: section.label, errorCount: sectionErrorCount(section.value, errors) }))

  return (
    <div className="min-w-0">
      <div className="mb-4 flex items-start gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate('/equipment')} aria-label={`Back to ${listTitle.toLowerCase()}`} className="mt-0.5">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h1 className="text-xl font-bold text-gray-900">Add Equipment</h1>
          <p className="text-sm text-gray-600">Register a new equipment item. Accountability is assigned afterward by logging a transaction.</p>
        </div>
      </div>

      <form onSubmit={submit} noValidate aria-label="New equipment" className="space-y-4">
        <div role="alert" className="space-y-2 empty:hidden">
          {optionsError && <Alert type="error">{optionsError}</Alert>}
          {formError && <Alert type="error">{formError}</Alert>}
        </div>

        <div>
          <Tabs id={TABS_ID} label="Equipment sections" tabs={tabs} value={tab} onChange={selectTab} />
          {EQUIPMENT_FIELD_SECTIONS.map((section) => (
            <TabPanel key={section.value} tabsId={TABS_ID} value={section.value} active={tab === section.value}>
              <EquipmentFields
                section={section.value}
                value={form}
                onChange={setForm}
                options={options?.form ?? null}
                classificationDefaults={options?.classification_defaults}
                errors={errors}
              />
            </TabPanel>
          ))}
        </div>

        <div className="sticky bottom-0 z-10 flex flex-col-reverse gap-2 rounded-t-lg border-t border-gray-200 bg-white/95 px-4 py-3 shadow-[0_-4px_12px_-8px_rgb(0_0_0/0.15)] backdrop-blur sm:flex-row sm:justify-end">
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
