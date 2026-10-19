import { ClipboardList, History } from 'lucide-react'
import { useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { equipmentService, toTransactionPayload } from '@/services/equipmentService'
import type { EquipmentTransaction, EquipmentTransactionFormData, EquipmentTransactionOptions } from '@/types/equipment'
import type { Option } from '@/types/lookup'

function emptyTransaction(): EquipmentTransactionFormData {
  return {
    transaction_type: '',
    accountable_officer_id: null,
    end_user_id: null,
    received_by_id: null,
    date_assigned_accountable_officer: '',
    date_assigned_end_user: '',
    date_received_new_accountable: '',
    supporting_documents1: '',
    or_si_dr_iar_no: '',
    supporting_documents2: '',
    par_ics_rrsp_rs_wmr_no: '',
  }
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatTimestamp(value: string | null): string {
  return value ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}

type PersonKey = 'accountable_officer_id' | 'end_user_id' | 'received_by_id'
type TextKey = Exclude<keyof EquipmentTransactionFormData, PersonKey>

interface TransactionLogProps {
  equipmentId: number
  transactions: EquipmentTransaction[]
  options: EquipmentTransactionOptions | null
  personnelOptions: Option<number>[]
  /** Whether to show the record form (`equipment.transactions.create`). */
  canRecord: boolean
  /** Called after a transaction is recorded, so the page can reload the record's accountability. */
  onRecorded: (message: string) => void
}

/**
 * Port of the old `equipment/Partials/TransactionLog.vue`: the append-only
 * accountability history, plus the form that records a new transaction.
 */
export function TransactionLog({ equipmentId, transactions, options, personnelOptions, canRecord, onRecorded }: TransactionLogProps) {
  const [form, setForm] = useState<EquipmentTransactionFormData>(emptyTransaction)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const people = personnelOptions.map((option) => ({ value: String(option.value), label: option.label }))

  function set<K extends keyof EquipmentTransactionFormData>(key: K, next: EquipmentTransactionFormData[K]) {
    setForm((current) => ({ ...current, [key]: next }))
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const response = await equipmentService.recordTransaction(equipmentId, toTransactionPayload(form))
      setForm(emptyTransaction())
      onRecorded(response.message)
    } catch (error) {
      setErrors(extractFieldErrors(error))
      setFormError(extractError(error, 'Unable to record the transaction. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  function textField(key: TextKey, label: string, hint: string, type: 'text' | 'date' = 'text') {
    return (
      <FormField id={`tx-${key}`} label={label} hint={hint} error={errors[key]}>
        {(aria) => (
          <Input
            id={`tx-${key}`}
            type={type}
            value={form[key]}
            onChange={(e) => set(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  function selectField(key: TextKey, label: string, hint: string, list: Option<string>[] | undefined, placeholder: string, className?: string) {
    return (
      <FormField id={`tx-${key}`} label={label} hint={hint} error={errors[key]} className={className}>
        {(aria) => (
          <Select
            id={`tx-${key}`}
            value={form[key]}
            options={list ?? []}
            disabled={!options}
            placeholder={placeholder}
            onChange={(e) => set(key, e.target.value)}
            {...aria}
          />
        )}
      </FormField>
    )
  }

  // SelectPicker (searchable) for the personnel lists, which can be long.
  function personField(key: PersonKey, label: string, hint: string) {
    const id = `tx-${key}`
    const selected = form[key]
    return (
      <FormField id={id} label={`${label} (optional)`} hint={hint} error={errors[key]}>
        {(aria) => (
          <SelectPicker
            id={id}
            aria-labelledby={`${id}-label`}
            options={people}
            selectedValue={selected === null ? null : String(selected)}
            onSelect={(option) => set(key, option ? Number(option.value) : null)}
            placeholder="Select personnel"
            {...aria}
          />
        )}
      </FormField>
    )
  }

  return (
    <div className="space-y-4">
      {canRecord && (
        <Card className="overflow-visible">
          <CardHeader>
            <h2 className="flex items-center gap-2 text-base font-semibold text-gray-900">
              <ClipboardList className="h-4 w-4 text-gray-400" aria-hidden="true" />
              Log a transaction
            </h2>
            <p className="mt-1 text-sm text-gray-600">
              Every change of accountability or end user is recorded as an append-only transaction. Log one now to assign or update the accountable officer
              and/or end user for this item.
            </p>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={submit} noValidate>
              {formError && <Alert type="error">{formError}</Alert>}
              <div className="grid gap-4 sm:grid-cols-2">
                {selectField(
                  'transaction_type',
                  'Transaction type',
                  'Select the appropriate Transaction Type from the dropdown list. For the initial transaction, choose \'Beginning Inventory.\' For subsequent transactions, including movements, transfers, or updates, select the Transaction Type that corresponds to the specific activity.',
                  options?.transaction_type,
                  'Select a transaction type',
                  'sm:col-span-2',
                )}
                {personField('accountable_officer_id', 'Accountable officer', 'Choose the employee accountable for the equipment from the dropdown.')}
                {textField(
                  'date_assigned_accountable_officer',
                  'Date assigned (optional)',
                  'Enter the date when the equipment was received by the Accountable Officer in the \'MM/DD/YYYY\' format.',
                  'date',
                )}
                {personField('end_user_id', 'End user', 'Select the Custodian or End User from the dropdown to whom the equipment is assigned.')}
                {textField(
                  'date_assigned_end_user',
                  'Date assigned (optional)',
                  'Enter the date when the equipment was received by the Custodian/End User in the \'MM/DD/YYYY\' format.',
                  'date',
                )}
                {personField('received_by_id', 'Received by', 'Choose the new Accountable Officer, Custodian, or End User from the dropdown for the newly assigned equipment.')}
                {textField(
                  'date_received_new_accountable',
                  'Date received (optional)',
                  'Enter the date when the new Accountable Officer, Custodian, or End User received the equipment in the \'MM/DD/YYYY\' format.',
                  'date',
                )}
                {selectField(
                  'supporting_documents1',
                  'Supporting document 1 (optional)',
                  'Select the appropriate document type from the dropdown to identify the transaction. This typically pertains to deliveries, inspection reports, and the initial setup of beginning inventory.',
                  options?.supporting_document_type,
                  'Select a document type',
                )}
                {textField(
                  'or_si_dr_iar_no',
                  'OR/SI/DR/IAR no. (optional)',
                  'Enter the document number for the transaction (e.g., delivery, inspection, or transfer) to ensure proper tracking and identification of the equipment.',
                )}
                {selectField(
                  'supporting_documents2',
                  'Supporting document 2 (optional)',
                  'Select the appropriate document type from the dropdown to identify the transaction. This typically applies to Issuances, Transfers, Returns, Stock Reports, or Waste Material.',
                  options?.supporting_document_type,
                  'Select a document type',
                )}
                {textField('par_ics_rrsp_rs_wmr_no', 'PAR/ICS/RRSP/RS/WMR no. (optional)', 'Input the document number to track and properly identify the transaction.')}
              </div>
              <div className="flex justify-end">
                <Button type="submit" isLoading={saving}>
                  Log transaction
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <h2 className="flex items-center gap-2 text-base font-semibold text-gray-900">
            <History className="h-4 w-4 text-gray-400" aria-hidden="true" />
            Transaction history <span className="font-normal text-gray-500">({transactions.length})</span>
          </h2>
        </CardHeader>
        {transactions.length === 0 ? (
          <CardContent>
            <p className="py-4 text-center text-sm text-gray-500">No transactions logged yet.</p>
          </CardContent>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Equipment transaction history, newest first</caption>
              <thead className="border-b border-gray-200 bg-gray-50">
                <tr>
                  {['Type', 'Accountable officer', 'End user', 'Received by', 'Documents', 'Logged'].map((heading) => (
                    <th key={heading} scope="col" className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                      {heading}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {transactions.map((transaction) => (
                  <tr key={transaction.id} className="align-top even:bg-gray-50/60">
                    <td className="px-4 py-3 font-medium text-gray-900">{transaction.transaction_type}</td>
                    <PersonCell name={transaction.accountable_officer?.full_name} date={transaction.date_assigned_accountable_officer} />
                    <PersonCell name={transaction.end_user?.full_name} date={transaction.date_assigned_end_user} />
                    <PersonCell name={transaction.received_by?.full_name} date={transaction.date_received_new_accountable} />
                    <td className="px-4 py-3 text-xs text-gray-600">
                      <DocumentLine type={transaction.supporting_documents1} number={transaction.or_si_dr_iar_no} />
                      <DocumentLine type={transaction.supporting_documents2} number={transaction.par_ics_rrsp_rs_wmr_no} />
                      {!transaction.supporting_documents1 && !transaction.or_si_dr_iar_no && !transaction.supporting_documents2 && !transaction.par_ics_rrsp_rs_wmr_no && '—'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{formatTimestamp(transaction.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}

function PersonCell({ name, date }: { name: string | null | undefined; date: string | null }) {
  return (
    <td className="px-4 py-3 text-gray-700">
      {name ?? '—'}
      <div className="text-xs text-gray-500">{formatDate(date)}</div>
    </td>
  )
}

function DocumentLine({ type, number }: { type: string | null; number: string | null }) {
  if (!type && !number) return null
  return <div>{[type, number].filter(Boolean).join(' · ')}</div>
}
