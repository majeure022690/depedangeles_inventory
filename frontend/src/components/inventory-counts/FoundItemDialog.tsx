import { Save } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import type { FoundItemInput, InventoryCountLookup } from '@/types/inventory-count'
import type { Option } from '@/types/lookup'

export interface FoundItemPrefill {
  equipment: InventoryCountLookup['equipment']
  code: string
}

/** PPE found at station: an IRIS record held under another office, or an item with no record. */
export function FoundItemDialog({
  prefill,
  officeId,
  officeName,
  conditions,
  onClose,
  save,
}: {
  prefill: FoundItemPrefill | null
  officeId: number | null
  officeName: string
  conditions: Option<number>[]
  onClose: () => void
  save: (input: FoundItemInput) => Promise<void>
}) {
  const empty = { description: '', property_no: '', serial_no: '', condition_id: '', location: '', remarks: '' }
  const [form, setForm] = useState(empty)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setForm({ ...empty, property_no: prefill && !prefill.equipment && !prefill.code.includes('/') ? prefill.code : '' })
    setErrors({})
    setError(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [prefill])

  const equipment = prefill?.equipment ?? null

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (officeId === null) return
    setSaving(true)
    setErrors({})
    setError(null)
    const value = (text: string) => (text.trim() === '' ? null : text.trim())
    try {
      await save({
        office_id: officeId,
        equipment_id: equipment?.id ?? null,
        description: equipment ? null : value(form.description),
        property_no: equipment ? null : value(form.property_no),
        serial_no: equipment ? null : value(form.serial_no),
        condition_id: form.condition_id === '' ? null : Number(form.condition_id),
        location: value(form.location),
        remarks: value(form.remarks),
      })
    } catch (saveError) {
      if (isValidationError(saveError)) setErrors(extractFieldErrors(saveError))
      setError(extractError(saveError, 'Unable to save. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={prefill !== null} onOpenChange={(open) => !open && !saving && onClose()}>
      <DialogContent size="md">
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <DialogHeader>
            <DialogTitle>Found at station</DialogTitle>
            <DialogDescription>
              {equipment ? 'This item is in IRIS but is not on this count’s list for the office.' : 'Record an item that has no IRIS record.'} Found at {officeName}.
            </DialogDescription>
          </DialogHeader>

          {error && (
            <Alert type="error">
              <span role="alert">{error}</span>
            </Alert>
          )}

          {equipment ? (
            <div className="rounded-md bg-gray-50 p-3 text-sm">
              <p className="font-medium text-gray-900">{equipment.property_no ?? 'No property number'}</p>
              <p className="text-gray-600">{equipment.description}</p>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <FormField id="found-description" label="Description" required error={errors.description}>
                  {(aria) => (
                    <Input
                      id="found-description"
                      value={form.description}
                      maxLength={255}
                      placeholder="e.g. Desktop monitor, black"
                      onChange={(event) => setForm({ ...form, description: event.target.value })}
                      {...aria}
                    />
                  )}
                </FormField>
              </div>
              <FormField id="found-property-no" label="Property number (optional)" error={errors.property_no}>
                {(aria) => (
                  <Input id="found-property-no" value={form.property_no} maxLength={100} onChange={(event) => setForm({ ...form, property_no: event.target.value })} {...aria} />
                )}
              </FormField>
              <FormField id="found-serial-no" label="Serial number (optional)" error={errors.serial_no}>
                {(aria) => <Input id="found-serial-no" value={form.serial_no} maxLength={100} onChange={(event) => setForm({ ...form, serial_no: event.target.value })} {...aria} />}
              </FormField>
            </div>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <FormField id="found-condition" label="Condition (optional)" error={errors.condition_id}>
              {(aria) => (
                <Select
                  id="found-condition"
                  value={form.condition_id}
                  onChange={(event) => setForm({ ...form, condition_id: event.target.value })}
                  options={conditions.map((condition) => ({ value: String(condition.value), label: condition.label }))}
                  placeholder="Not set"
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="found-location" label="Location (optional)" error={errors.location}>
              {(aria) => <Input id="found-location" value={form.location} maxLength={1000} onChange={(event) => setForm({ ...form, location: event.target.value })} {...aria} />}
            </FormField>
            <div className="sm:col-span-2">
              <FormField id="found-remarks" label="Remarks (optional)" error={errors.remarks ?? errors.equipment_id}>
                {(aria) => <Textarea id="found-remarks" rows={2} value={form.remarks} maxLength={500} onChange={(event) => setForm({ ...form, remarks: event.target.value })} {...aria} />}
              </FormField>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" leftIcon={<Save className="h-4 w-4" />} isLoading={saving} className="min-h-11">
              Record
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
