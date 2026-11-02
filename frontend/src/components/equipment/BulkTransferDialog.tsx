import { isAxiosError } from 'axios'
import { useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { equipmentService, toTransactionPayload } from '@/services/equipmentService'
import type { EquipmentOptions, EquipmentTransactionFormData } from '@/types/equipment'

const DEFAULT_TYPE = 'Issuance/Transfer'

function emptyTransfer(types: string[]): EquipmentTransactionFormData {
  return {
    transaction_type: types.includes(DEFAULT_TYPE) ? DEFAULT_TYPE : '',
    accountable_officer_id: null,
    end_user_id: null,
    received_by_id: null,
    date_assigned_accountable_officer: new Date().toISOString().slice(0, 10),
    date_assigned_end_user: '',
    date_received_new_accountable: '',
    supporting_documents1: '',
    or_si_dr_iar_no: '',
    supporting_documents2: '',
    par_ics_rrsp_rs_wmr_no: '',
  }
}

interface BulkTransferDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  equipmentIds: number[]
  options: EquipmentOptions
  onTransferred: (message: string) => void
}

/** Records one transaction for every selected item: pick the destination office, then its accountable officer. */
export function BulkTransferDialog({ open, onOpenChange, equipmentIds, options, onTransferred }: BulkTransferDialogProps) {
  const people = options.personnel ?? []
  const types = (options.transaction.transaction_type ?? []).map((option) => String(option.value))
  const offices = [...new Map(people.filter((p) => p.office_id != null).map((p) => [p.office_id as number, p.office_name ?? `Office #${p.office_id}`])).entries()]
    .map(([value, label]) => ({ value, label }))
    .sort((a, b) => a.label.localeCompare(b.label))

  const [officeId, setOfficeId] = useState('')
  const [form, setForm] = useState<EquipmentTransactionFormData>(() => emptyTransfer(types))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [itemProblems, setItemProblems] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const officePeople = people.filter((person) => String(person.office_id) === officeId).map((person) => ({ value: String(person.value), label: person.label }))

  function set<K extends keyof EquipmentTransactionFormData>(key: K, value: EquipmentTransactionFormData[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  function changeOffice(next: string) {
    const heads = people.filter((person) => String(person.office_id) === next && person.is_school_head)
    setOfficeId(next)
    setForm((prev) => ({ ...prev, accountable_officer_id: heads.length === 1 ? heads[0].value : null, end_user_id: null }))
  }

  function close(nextOpen: boolean) {
    if (saving) return
    if (!nextOpen) {
      setOfficeId('')
      setForm(emptyTransfer(types))
      setErrors({})
      setItemProblems([])
      setError(null)
    }
    onOpenChange(nextOpen)
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setItemProblems([])
    setError(null)
    try {
      const response = await equipmentService.bulkTransaction({ equipment_ids: equipmentIds, ...toTransactionPayload(form) })
      onTransferred(response.message)
      close(false)
    } catch (submitError) {
      const problems = isAxiosError(submitError) ? (submitError.response?.data?.errors?.equipment_ids as string[] | undefined) : undefined
      setItemProblems(problems ?? [])
      setErrors(extractFieldErrors(submitError) ?? {})
      setError(extractError(submitError, 'Unable to transfer the selected items. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  function person(key: 'accountable_officer_id' | 'end_user_id', label: string, hint: string, required = false) {
    const id = `bulk-${key}`
    return (
      <FormField id={id} label={label} hint={hint} error={errors[key]} required={required}>
        {(aria) => (
          <SelectPicker
            id={id}
            aria-labelledby={`${id}-label`}
            options={officePeople}
            selectedValue={form[key] === null ? null : String(form[key])}
            onSelect={(option) => set(key, option ? Number(option.value) : null)}
            placeholder={officeId === '' ? 'Choose the office first' : 'Select personnel'}
            disabled={officeId === ''}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent size="lg">
        <DialogTitle>Transfer {equipmentIds.length} item(s) to an office</DialogTitle>
        <DialogDescription>
          One transaction is recorded for each selected item, and bundled monitors, keyboards and mice move with their desktop. If any item can&rsquo;t be
          transferred, nothing is saved.
        </DialogDescription>
        <form onSubmit={submit} noValidate className="mt-4 space-y-4">
          {error && (
            <Alert type="error">
              <span role="alert">{error}</span>
              {itemProblems.length > 0 && (
                <ul className="mt-1 list-disc pl-5">
                  {itemProblems.map((problem) => (
                    <li key={problem}>{problem}</li>
                  ))}
                </ul>
              )}
            </Alert>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="bulk-office" label="Transfer to office" required className="sm:col-span-2">
              {(aria) => (
                <Select id="bulk-office" value={officeId} options={offices} placeholder="Select the office" onChange={(e) => changeOffice(e.target.value)} {...aria} />
              )}
            </FormField>
            {person('accountable_officer_id', 'Accountable officer', 'Personnel of the chosen office; its school head is picked when there is only one.', true)}
            {person('end_user_id', 'End user (optional)', 'The person who uses the items, if different.')}
            <FormField id="bulk-type" label="Transaction type" required error={errors.transaction_type}>
              {(aria) => (
                <Select
                  id="bulk-type"
                  value={form.transaction_type}
                  options={options.transaction.transaction_type ?? []}
                  placeholder="Select a type"
                  onChange={(e) => set('transaction_type', e.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="bulk-date" label="Date assigned" error={errors.date_assigned_accountable_officer}>
              {(aria) => (
                <Input id="bulk-date" type="date" value={form.date_assigned_accountable_officer} onChange={(e) => set('date_assigned_accountable_officer', e.target.value)} {...aria} />
              )}
            </FormField>
            <FormField id="bulk-doc" label="Supporting document (optional)" error={errors.supporting_documents2}>
              {(aria) => (
                <Select
                  id="bulk-doc"
                  value={form.supporting_documents2}
                  options={options.transaction.supporting_document_type ?? []}
                  placeholder="None"
                  onChange={(e) => set('supporting_documents2', e.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="bulk-doc-no" label="PAR/ICS/RRSP/RS/WMR no. (optional)" error={errors.par_ics_rrsp_rs_wmr_no}>
              {(aria) => <Input id="bulk-doc-no" value={form.par_ics_rrsp_rs_wmr_no} onChange={(e) => set('par_ics_rrsp_rs_wmr_no', e.target.value)} {...aria} />}
            </FormField>
          </div>
          <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <Button type="button" variant="outline" onClick={() => close(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving} disabled={officeId === '' || form.accountable_officer_id === null}>
              Transfer {equipmentIds.length} item(s)
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
