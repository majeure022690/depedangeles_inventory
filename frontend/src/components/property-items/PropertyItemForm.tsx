import { ArrowLeft, Save } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'

import { FormField } from '@/components/forms/FormField'
import { FormSection } from '@/components/forms/FormSection'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SuggestInput } from '@/components/ui/SuggestInput'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import { propertyItemService } from '@/services/propertyItemService'
import type { PropertyItem, PropertyItemFormData, PropertyItemOptions } from '@/types/property-item'

const WITH_GRADE_LEVEL = ['furniture_fixtures', 'books']

/**
 * Shared by the create and edit pages. Books relabel the count fields
 * (received in June / actual in March); furniture adds the grade level
 * used to group the per-teacher attachment of the RPCPPE.
 */
export function PropertyItemForm({
  initial,
  options,
  officeLocked,
  readOnly = false,
  onSaved,
  submit,
}: {
  initial: PropertyItemFormData
  options: PropertyItemOptions
  officeLocked: boolean
  readOnly?: boolean
  onSaved: (item: PropertyItem, message: string) => void
  submit: (form: PropertyItemFormData) => Promise<{ data: PropertyItem; message: string }>
}) {
  const navigate = useNavigate()
  const [form, setForm] = useState(initial)
  const [personnelList, setPersonnelList] = useState<{
    officeId: string
    people: NonNullable<PropertyItemOptions['personnel']>
  } | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (form.office_id === '') return
    let cancelled = false
    const officeId = form.office_id
    propertyItemService
      .options(Number(officeId))
      .then((loaded) => {
        if (!cancelled) setPersonnelList({ officeId, people: loaded.personnel ?? [] })
      })
      .catch(() => {
        if (!cancelled) setPersonnelList({ officeId, people: [] })
      })
    return () => {
      cancelled = true
    }
  }, [form.office_id])

  const personnel = personnelList && personnelList.officeId === form.office_id ? personnelList.people : []
  const category = options.category.find((option) => option.value === form.category)
  const isBooks = form.category === 'books'

  function set<K extends keyof PropertyItemFormData>(field: K, value: PropertyItemFormData[K]) {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  function changeOffice(officeId: string) {
    setForm((prev) => ({
      ...prev,
      office_id: officeId,
      accountable_officer_id: '',
      end_user_id: '',
    }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (readOnly) return
    setSaving(true)
    setErrors({})
    setErrorMessage(null)
    try {
      const response = await submit(form)
      onSaved(response.data, response.message)
    } catch (error) {
      if (isValidationError(error)) {
        setErrors(extractFieldErrors(error))
        setErrorMessage('Please fix the highlighted fields.')
      } else {
        setErrorMessage(extractError(error, 'Unable to save the property item. Please try again.'))
      }
    } finally {
      setSaving(false)
    }
  }

  const personOptions = personnel.map((person) => ({
    value: String(person.value),
    label: person.label,
  }))

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-4">
      {errorMessage && (
        <Alert type="error">
          <span role="alert">{errorMessage}</span>
        </Alert>
      )}

      <fieldset disabled={readOnly || saving} className="space-y-4">
        <FormSection title="Item" columns={4}>
          <FormField
            id="office_id"
            label="Office"
            required
            error={errors.office_id}
            note={officeLocked ? 'Items are recorded under your own office.' : undefined}
          >
            {(aria) => (
              <Select
                id="office_id"
                value={form.office_id}
                placeholder="Select an office"
                options={options.office.map((office) => ({
                  value: String(office.value),
                  label: office.label,
                }))}
                disabled={officeLocked}
                onChange={(event) => changeOffice(event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="category" label="Category" required error={errors.category} hint="Decides which RPCPPE sheet the item is reported on.">
            {(aria) => (
              <Select
                id="category"
                value={form.category}
                placeholder="Select a category"
                options={options.category.map((option) => ({
                  value: option.value,
                  label: option.label,
                }))}
                onChange={(event) => set('category', event.target.value as PropertyItemFormData['category'])}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="article" label={isBooks ? 'Learning area' : 'Article'} required error={errors.article} hint="Pick a suggestion or type your own.">
            {() => (
              <SuggestInput
                value={form.article}
                onChange={(value) => set('article', value)}
                suggestions={category?.articles ?? []}
                maxLength={150}
                placeholder={category ? `e.g. ${category.articles[0]}` : 'Choose a category first'}
                aria-label={isBooks ? 'Learning area' : 'Article'}
              />
            )}
          </FormField>
          <FormField id="description" label={isBooks ? 'Title / material (optional)' : 'Description (optional)'} error={errors.description}>
            {(aria) => (
              <Input id="description" value={form.description} maxLength={500} onChange={(event) => set('description', event.target.value)} {...aria} />
            )}
          </FormField>
          {WITH_GRADE_LEVEL.includes(form.category) && (
            <FormField
              id="grade_level"
              label="Grade level (optional)"
              error={errors.grade_level}
              hint={isBooks ? 'Grade level the material is for.' : 'Grade of the classroom using it; groups the furniture-per-teacher sheet.'}
            >
              {(aria) => (
                <Select
                  id="grade_level"
                  value={form.grade_level}
                  placeholder="None"
                  options={options.grade_level}
                  onChange={(event) => set('grade_level', event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
          )}
          <FormField id="property_no" label="Property number (optional)" error={errors.property_no}>
            {(aria) => (
              <Input id="property_no" value={form.property_no} maxLength={100} onChange={(event) => set('property_no', event.target.value)} {...aria} />
            )}
          </FormField>
        </FormSection>

        <FormSection title="Count and value" columns={4}>
          <FormField id="quantity" label={isBooks ? 'Receipt (June)' : 'Balance per card (quantity)'} required error={errors.quantity}>
            {(aria) => <Input id="quantity" type="number" min={0} value={form.quantity} onChange={(event) => set('quantity', event.target.value)} {...aria} />}
          </FormField>
          <FormField id="on_hand_count" label={isBooks ? 'Actual (March)' : 'On hand per count (quantity)'} required error={errors.on_hand_count}>
            {(aria) => (
              <Input
                id="on_hand_count"
                type="number"
                min={0}
                value={form.on_hand_count}
                onChange={(event) => set('on_hand_count', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="unit_of_measure" label="Unit of measure (optional)" error={errors.unit_of_measure}>
            {() => (
              <SuggestInput
                value={form.unit_of_measure}
                onChange={(value) => set('unit_of_measure', value)}
                suggestions={['pc', 'unit', 'set', 'lot', 'copy', ...options.unit_of_measure.map((option) => option.label)]}
                maxLength={50}
                aria-label="Unit of measure"
              />
            )}
          </FormField>
          <FormField
            id="unit_value"
            label="Unit value (₱, optional)"
            error={errors.unit_value}
            hint="₱50,000 and up goes on the PPE sheet; below that on the semi-expendable sheet."
          >
            {(aria) => (
              <Input
                id="unit_value"
                type="number"
                min={0}
                step="0.01"
                value={form.unit_value}
                onChange={(event) => set('unit_value', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="date_acquired" label="Date acquired (optional)" error={errors.date_acquired}>
            {(aria) => (
              <Input id="date_acquired" type="date" value={form.date_acquired} onChange={(event) => set('date_acquired', event.target.value)} {...aria} />
            )}
          </FormField>
          <FormField id="source" label="Transferred / donated by (optional)" error={errors.source}>
            {(aria) => (
              <Select
                id="source"
                value={form.source}
                placeholder="None"
                options={options.source}
                onChange={(event) => set('source', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
        </FormSection>

        <FormSection title="Condition and accountability" columns={4}>
          <FormField id="condition" label="Condition (optional)" error={errors.condition}>
            {(aria) => (
              <Select
                id="condition"
                value={form.condition}
                placeholder="Not stated"
                options={options.condition}
                onChange={(event) => set('condition', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="with_are" label="With MR / ARE" error={errors.with_are}>
            {(aria) => (
              <Switch id="with_are" checked={form.with_are} onChange={(checked) => set('with_are', checked)} label={form.with_are ? 'Yes' : 'No'} {...aria} />
            )}
          </FormField>
          <FormField id="accountable_officer_id" label="Accountable officer (optional)" error={errors.accountable_officer_id}>
            {(aria) => (
              <Select
                id="accountable_officer_id"
                value={form.accountable_officer_id}
                placeholder={form.office_id === '' ? 'Choose an office first' : 'None'}
                options={personOptions}
                disabled={form.office_id === ''}
                onChange={(event) => set('accountable_officer_id', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField
            id="end_user_id"
            label="End user / teacher (optional)"
            error={errors.end_user_id}
            hint="The teacher or staff member using the item. Listed by name on the furniture-per-teacher sheet."
          >
            {(aria) => (
              <Select
                id="end_user_id"
                value={form.end_user_id}
                placeholder={form.office_id === '' ? 'Choose an office first' : 'None'}
                options={personOptions}
                disabled={form.office_id === ''}
                onChange={(event) => set('end_user_id', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="location" label="Location (optional)" error={errors.location}>
            {(aria) => (
              <Input
                id="location"
                value={form.location}
                maxLength={255}
                placeholder="e.g. Room 12"
                onChange={(event) => set('location', event.target.value)}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="remarks" label="Remarks (optional)" error={errors.remarks} className="sm:col-span-2 lg:col-span-3 2xl:col-span-4">
            {(aria) => (
              <Textarea id="remarks" value={form.remarks} maxLength={500} rows={3} onChange={(event) => set('remarks', event.target.value)} {...aria} />
            )}
          </FormField>
        </FormSection>
      </fieldset>

      <div className="flex justify-between gap-2">
        <Button
          type="button"
          variant="outline"
          leftIcon={<ArrowLeft className="h-4 w-4" />}
          onClick={() => navigate(initial.category ? `/school-property?tab=${initial.category}` : '/school-property')}
        >
          Back to list
        </Button>
        {!readOnly && (
          <Button type="submit" leftIcon={<Save className="h-4 w-4" />} isLoading={saving}>
            Save
          </Button>
        )}
      </div>
    </form>
  )
}
