import { Save, X } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { PersonPicker } from '@/components/forms/PersonPicker'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { SuggestInput } from '@/components/ui/SuggestInput'
import { Textarea } from '@/components/ui/Textarea'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { defaultCountName, inventoryCountService } from '@/services/inventoryCountService'
import type { ApiResponse } from '@/types/api'
import type { InventoryCount, InventoryCountFormData, InventoryCountOptions } from '@/types/inventory-count'
import type { PersonOption } from '@/types/lookup'

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
  current?: Pick<InventoryCount, 'committee_chair' | 'committee_members' | 'offices'>
  submit: (form: InventoryCountFormData) => Promise<ApiResponse<InventoryCount>>
  onSaved: (count: InventoryCount, message: string) => void
  onCancel: () => void
}) {
  const [form, setForm] = useState(initial)
  const [nameTouched, setNameTouched] = useState(initial.name !== defaultCountName(initial.as_of_date))
  const [people, setPeople] = useState<PersonOption[]>(options.people)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const officeKey = form.office_ids.join(',')
  useEffect(() => {
    if (form.office_ids.length === 0) {
      setPeople([])
      return
    }
    let cancelled = false
    inventoryCountService
      .options(form.office_ids)
      .then((loaded) => !cancelled && setPeople(loaded.people))
      .catch(() => !cancelled && setPeople([]))
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [officeKey])

  const officeLabel = (id: number) =>
    options.office.find((office) => office.value === id)?.label ?? current?.offices?.find((office) => office.id === id)?.office_name ?? `Office #${id}`
  const knownPeople = [...people, ...(current?.committee_members ?? []), ...(current?.committee_chair ? [current.committee_chair] : [])]
  const personLabel = (id: number) => knownPeople.find((person) => person.value === id)?.label ?? `Personnel #${id}`

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

  const fieldError = (key: string) => errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1]

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
          <FormField
            id="count-offices"
            label="Offices to count"
            required
            error={fieldError('office_ids')}
            note={officesLocked ? 'Fixed once the count is open.' : undefined}
          >
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
                      size="sm"
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

      <FormSection title="Inventory Committee" columns={2}>
        <PersonPicker
          id="count-chair"
          label="Chair (optional)"
          people={people}
          current={current?.committee_chair ?? null}
          value={form.committee_chair_id}
          onChange={(value) => set('committee_chair_id', value)}
          placeholder={form.office_ids.length === 0 ? 'Add an office first' : 'Select the chair...'}
          disabled={form.office_ids.length === 0}
          error={errors.committee_chair_id}
        />
        <FormField id="count-coa" label="COA representative (optional)" error={errors.coa_representative}>
          {(aria) => (
            <Input
              id="count-coa"
              value={form.coa_representative}
              maxLength={150}
              onChange={(event) => set('coa_representative', event.target.value)}
              {...aria}
            />
          )}
        </FormField>
        <div className="sm:col-span-2">
          <FormField id="count-members" label="Members (optional)" error={fieldError('committee_member_ids')}>
            {(aria) => (
              <div className="space-y-3">
                <SelectPicker
                  id="count-members"
                  aria-labelledby="count-members-label"
                  options={people
                    .filter((person) => !form.committee_member_ids.includes(person.value))
                    .map((person) => ({ value: String(person.value), label: person.label }))}
                  selectedValue={null}
                  onSelect={(option) => option && set('committee_member_ids', [...form.committee_member_ids, Number(option.value)])}
                  placeholder={form.office_ids.length === 0 ? 'Add an office first' : 'Add a member...'}
                  disabled={form.office_ids.length === 0}
                  {...aria}
                />
                {form.committee_member_ids.length > 0 && (
                  <ul className="flex flex-wrap gap-2" aria-label="Committee members">
                    {form.committee_member_ids.map((id) => (
                      <Chip
                        key={id}
                        label={personLabel(id)}
                        onRemove={() =>
                          set(
                            'committee_member_ids',
                            form.committee_member_ids.filter((memberId) => memberId !== id),
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
