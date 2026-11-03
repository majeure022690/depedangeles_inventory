import { Check, X } from 'lucide-react'
import { useEffect, useState } from 'react'

import { FormField } from '@/components/forms/FormField'
import { PersonPicker } from '@/components/forms/PersonPicker'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { extractError } from '@/lib/errors'
import { cn } from '@/lib/utils'
import { BUCKET_COLORS, BUCKET_LABELS, type CountItemUpdate, type CountResult, type InventoryCountItem } from '@/types/inventory-count'
import type { Option, PersonOption } from '@/types/lookup'

interface Draft {
  result: CountResult
  condition_id: string
  holder_id: string
  location: string
  remarks: string
}

function draftFrom(item: InventoryCountItem): Draft {
  const counted = item.result === 'found'
  return {
    result: item.result,
    condition_id: String((counted ? item.found.condition_id : item.recorded.condition_id) ?? ''),
    holder_id: String((counted ? item.found.holder_id : item.recorded.accountable_officer_id) ?? ''),
    location: (counted ? item.found.location : item.recorded.location) ?? '',
    remarks: item.remarks ?? '',
  }
}

/** Marks one expected item. Condition, holder and location start from the record, so a match is one tap. */
export function CountItemDialog({
  item,
  conditions,
  people,
  onClose,
  save,
}: {
  item: InventoryCountItem | null
  conditions: Option<number>[]
  people: PersonOption[]
  onClose: () => void
  save: (item: InventoryCountItem, update: CountItemUpdate) => Promise<void>
}) {
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setDraft(item ? draftFrom(item) : null)
    setError(null)
  }, [item])

  async function submit(result: CountResult) {
    if (!item || !draft) return
    setSaving(true)
    setError(null)
    try {
      await save(
        item,
        result === 'found'
          ? {
              result,
              found_condition_id: draft.condition_id === '' ? null : Number(draft.condition_id),
              found_holder_id: draft.holder_id === '' ? null : Number(draft.holder_id),
              found_location: draft.location.trim() === '' ? null : draft.location.trim(),
              remarks: draft.remarks.trim() === '' ? null : draft.remarks.trim(),
            }
          : { result, remarks: draft.remarks.trim() === '' ? null : draft.remarks.trim() },
      )
    } catch (saveError) {
      setError(extractError(saveError, 'Unable to save. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  const recordedHolder: PersonOption | null =
    item?.recorded.accountable_officer_id != null ? { value: item.recorded.accountable_officer_id, label: item.recorded.accountable_officer ?? '', position: null } : null

  return (
    <Dialog open={item !== null} onOpenChange={(open) => !open && !saving && onClose()}>
      <DialogContent size="md">
        {item && draft && (
          <>
            <DialogHeader>
              <DialogTitle>{item.equipment.property_no ?? 'No property number'}</DialogTitle>
              <DialogDescription>{item.equipment.description}</DialogDescription>
            </DialogHeader>

            <dl className="grid grid-cols-1 gap-x-4 gap-y-1 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-xs text-gray-500">On record: condition</dt>
                <dd>{item.recorded.condition ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-gray-500">On record: accountable officer</dt>
                <dd>{item.recorded.accountable_officer ?? '—'}</dd>
              </div>
              <div className="sm:col-span-2">
                <dt className="text-xs text-gray-500">On record: location</dt>
                <dd>{item.recorded.location ?? '—'}</dd>
              </div>
              {item.result !== 'pending' && (
                <div className="sm:col-span-2">
                  <dt className="text-xs text-gray-500">Current result</dt>
                  <dd>
                    <Badge color={BUCKET_COLORS[item.bucket]}>{BUCKET_LABELS[item.bucket]}</Badge>
                  </dd>
                </div>
              )}
            </dl>

            {error && (
              <Alert type="error">
                <span role="alert">{error}</span>
              </Alert>
            )}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <FormField id="count-item-condition" label="Condition found">
                {(aria) => (
                  <Select
                    id="count-item-condition"
                    value={draft.condition_id}
                    onChange={(event) => setDraft({ ...draft, condition_id: event.target.value })}
                    options={conditions.map((condition) => ({ value: String(condition.value), label: condition.label }))}
                    placeholder="Not set"
                    {...aria}
                  />
                )}
              </FormField>
              <PersonPicker
                id="count-item-holder"
                label="Found with"
                people={people}
                current={recordedHolder}
                value={draft.holder_id}
                onChange={(holder_id) => setDraft({ ...draft, holder_id })}
                placeholder="Select a person..."
              />
              <div className="sm:col-span-2">
                <FormField id="count-item-location" label="Location found (optional)">
                  {(aria) => (
                    <Input id="count-item-location" value={draft.location} maxLength={1000} onChange={(event) => setDraft({ ...draft, location: event.target.value })} {...aria} />
                  )}
                </FormField>
              </div>
              <div className="sm:col-span-2">
                <FormField id="count-item-remarks" label="Remarks (optional)">
                  {(aria) => (
                    <Textarea id="count-item-remarks" rows={2} value={draft.remarks} maxLength={500} onChange={(event) => setDraft({ ...draft, remarks: event.target.value })} {...aria} />
                  )}
                </FormField>
              </div>
            </div>

            <DialogFooter className="gap-2">
              <Button variant="danger" size="lg" className={cn('min-h-11 w-full sm:w-auto')} leftIcon={<X className="h-5 w-5" />} disabled={saving} onClick={() => void submit('not_found')}>
                Not found
              </Button>
              <Button size="lg" className="min-h-11 w-full sm:w-auto" leftIcon={<Check className="h-5 w-5" />} isLoading={saving} onClick={() => void submit('found')}>
                Found
              </Button>
            </DialogFooter>
            {item.result !== 'pending' && (
              <button type="button" className="justify-self-center text-sm text-gray-500 underline hover:text-gray-700" disabled={saving} onClick={() => void submit('pending')}>
                Reset to not yet counted
              </button>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
