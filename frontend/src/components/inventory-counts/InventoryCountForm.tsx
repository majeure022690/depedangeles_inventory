import { Save, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { SuggestInput } from '@/components/ui/SuggestInput'
import { Textarea } from '@/components/ui/Textarea'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { defaultCountName } from '@/services/inventoryCountService'
import type { ApiResponse } from '@/types/api'
import type { InventoryCount, InventoryCountFormData, InventoryCountOptions } from '@/types/inventory-count'

function Chip({ label, onRemove, disabled }: { label: string; onRemove: () => void; disabled?: boolean }) {
  return (
    <li className="inline-flex items-center gap-1 rounded-full bg-primary-50 py-1 pr-1 pl-3 text-sm text-primary-800">
      <span>{label}</span>
      {!disabled && (
        <button
          type="button"
          onClick={onRemove}
          className="inline-flex h-7 w-7 items-center justify-center rounded-full hover:bg-primary-100 focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none"
          aria-label={`Remove ${label}`}
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </li>
  )
}

/**
 * The offices and as-of date lock once the count is open, since its expected list was built from them.
 */
export function InventoryCountForm({
  initial,
  options,
  officesLocked,
  current,
  submit,
  onSaved,
  onCancel,
}: {
  initial: InventoryCountFormData
  options: InventoryCountOptions
  officesLocked: boolean
  current?: Pick<InventoryCount, 'offices'>
  submit: (form: InventoryCountFormData) => Promise<ApiResponse<InventoryCount>>
  onSaved: (count: InventoryCount, message: string) => void
  onCancel: () => void
}) {
  const [form, setForm] = useState(initial)
  const [nameTouched, setNameTouched] = useState(initial.name !== defaultCountName(initial.as_of_date))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const officeLabel = (id: number) =>
    options.office.find((office) => office.value === id)?.label ?? current?.offices?.find((office) => office.id === id)?.office_name ?? `Office #${id}`

  function set<K extends keyof InventoryCountFormData>(key: K, value: InventoryCountFormData[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setError(null)
    try {
      const response = await submit(form)
      onSaved(response.data, response.message)
    } catch (submitError) {
      if (isValidationError(submitError)) setErrors(extractFieldErrors(submitError))
      setError(extractError(submitError, 'Unable to save the count. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  const officeError = errors.office_ids ?? Object.entries(errors).find(([name]) => name.startsWith('office_ids.'))?.[1]

  return (
    <form onSubmit={onSubmit} className="space-y-6" noValidate>
      {error && (
        <Alert type="error">
          <span role="alert">{error}</span>
        </Alert>
      )}

      <FormSection title="Count" columns={2}>
        <FormField id="count-as-of" label="As-of date" required error={errors.as_of_date} note={officesLocked ? 'Fixed once the count is open.' : undefined}>
          {(aria) => (
            <Input
              id="count-as-of"
              type="date"
              value={form.as_of_date}
              disabled={officesLocked}
              onChange={(event) => {
                const asOf = event.target.value
                setForm((prev) => ({ ...prev, as_of_date: asOf, name: nameTouched ? prev.name : defaultCountName(asOf) }))
              }}
              {...aria}
            />
          )}
        </FormField>
        <FormField id="count-name" label="Name" required error={errors.name} hint="Filled in from the as-of date. Pick a name used before or type your own.">
          {() => (
            <SuggestInput
              aria-label="Name"
              value={form.name}
              suggestions={options.name_suggestions}
              maxLength={150}
              onChange={(name) => {
                setNameTouched(true)
                set('name', name)
              }}
            />
          )}
        </FormField>
      </FormSection>

      <FormSection title="Offices" columns={2}>
        <div className="sm:col-span-2">
          <FormField id="count-offices" label="Offices to count" required error={officeError} note={officesLocked ? 'Fixed once the count is open.' : undefined}>
            {(aria) => (
              <div className="space-y-3">
                {!officesLocked && (
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <SelectPicker
                      id="count-offices"
                      aria-labelledby="count-offices-label"
                      className="flex-1"
                      options={options.office
                        .filter((office) => !form.office_ids.includes(office.value))
                        .map((office) => ({ value: String(office.value), label: office.label }))}
                      selectedValue={null}
                      onSelect={(option) => option && set('office_ids', [...form.office_ids, Number(option.value)])}
                      placeholder="Add an office..."
                      {...aria}
                    />
                    <Button
                      variant="outline"
                      className="h-10 shrink-0 whitespace-nowrap"
                      disabled={form.office_ids.length === options.office.length}
                      onClick={() => set('office_ids', options.office.map((office) => office.value))}
                    >
                      Add all ({options.office.length})
                    </Button>
                  </div>
                )}
                {form.office_ids.length === 0 ? (
                  <p className="text-sm text-gray-500">No offices yet.</p>
                ) : (
                  <ul className="flex max-h-60 flex-wrap gap-2 overflow-y-auto" aria-label="Selected offices">
                    {form.office_ids.map((id) => (
                      <Chip
                        key={id}
                        label={officeLabel(id)}
                        disabled={officesLocked}
                        onRemove={() =>
                          set(
                            'office_ids',
                            form.office_ids.filter((officeId) => officeId !== id),
                          )
                        }
                      />
                    ))}
                  </ul>
                )}
              </div>
            )}
          </FormField>
        </div>
      </FormSection>

      <FormSection title="Remarks" columns={2}>
        <div className="sm:col-span-2">
          <FormField id="count-remarks" label="Remarks (optional)" error={errors.remarks}>
            {(aria) => <Textarea id="count-remarks" rows={3} value={form.remarks} maxLength={2000} onChange={(event) => set('remarks', event.target.value)} {...aria} />}
          </FormField>
        </div>
      </FormSection>

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="outline" onClick={onCancel} disabled={saving}>
          Cancel
        </Button>
        <Button type="submit" leftIcon={<Save className="h-4 w-4" />} isLoading={saving}>
          Save
        </Button>
      </div>
    </form>
  )
}
