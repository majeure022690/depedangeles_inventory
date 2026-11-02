import { ClipboardList, History } from 'lucide-react'
import { useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { TransactionDocumentsField } from '@/components/equipment/TransactionDocumentsField'
import { transactionRequirements } from '@/lib/transactionRules'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { equipmentService, toTransactionPayload } from '@/services/equipmentService'
import type { EquipmentOptions, EquipmentTransaction, EquipmentTransactionFormData, EquipmentTransactionOptions } from '@/types/equipment'
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
    documents: [],
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
type TextKey = Exclude<keyof EquipmentTransactionFormData, PersonKey | 'documents'>

interface TransactionLogProps {
  equipmentId: number
  transactions: EquipmentTransaction[]
  options: EquipmentTransactionOptions | null
  personnelOptions: NonNullable<EquipmentOptions['personnel']>
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

  const [officeFilter, setOfficeFilter] = useState('')
  const offices = [...new Map(personnelOptions.filter((p) => p.office_id != null).map((p) => [p.office_id as number, p.office_name ?? `Office #${p.office_id}`])).entries()]
    .map(([value, label]) => ({ value, label }))
    .sort((a, b) => a.label.localeCompare(b.label))
  const people = personnelOptions
    .filter((option) => officeFilter === '' || String(option.office_id) === officeFilter)
    .map((option) => ({ value: String(option.value), label: option.label }))

  function changeOffice(next: string) {
    const heads = personnelOptions.filter((person) => String(person.office_id) === next && person.is_school_head)
    setOfficeFilter(next)
    setForm((current) => ({ ...current, accountable_officer_id: heads.length === 1 ? heads[0].value : null, end_user_id: null, received_by_id: null }))
  }

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
      setOfficeFilter('')
      onRecorded(response.message)
    } catch (error) {
      const fieldErrors = extractFieldErrors(error)
      setErrors(fieldErrors)
      setFormError(
        Object.keys(fieldErrors).length > 1
          ? 'Some fields need attention. Check the messages below.'
          : extractError(error, 'Unable to record the transaction. Please try again.'),
      )
    } finally {
      setSaving(false)
    }
  }

  function textField(key: TextKey, label: string, hint: string, type: 'text' | 'date' = 'text', required = false) {
    return (
      <FormField id={`tx-${key}`} label={required ? label : `${label} (optional)`} hint={hint} error={errors[key]} required={required}>
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
  function personField(key: PersonKey, label: string, hint: string, required = false) {
    const id = `tx-${key}`
    const selected = form[key]
    return (
      <FormField id={id} label={required ? label : `${label} (optional)`} hint={hint} error={errors[key]} required={required}>
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

  const required = transactionRequirements(form.transaction_type)

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
              <div role="alert" className="empty:hidden">
                {formError && <Alert type="error">{formError}</Alert>}
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                {selectField(
                  'transaction_type',
                  'Transaction type',
                  'Select the appropriate Transaction Type from the dropdown list. For the initial transaction, choose \'Beginning Inventory.\' For subsequent transactions, including movements, transfers, or updates, select the Transaction Type that corresponds to the specific activity.',
                  options?.transaction_type,
                  'Select a transaction type',
                  'sm:col-span-2',
                )}
                <FormField
                  id="tx-office"
                  label="Transfer to office (optional)"
                  hint="Narrows the people lists to one office; its school head is picked as accountable officer when there is only one."
                  className="sm:col-span-2"
                >
                  {(aria) => (
                    <Select id="tx-office" value={officeFilter} options={offices} placeholder="All offices" onChange={(e) => changeOffice(e.target.value)} {...aria} />
                  )}
                </FormField>
                {personField('accountable_officer_id', 'Accountable officer', 'Choose the employee accountable for the equipment from the dropdown.', required.officer)}
                {textField(
                  'date_assigned_accountable_officer',
                  'Date assigned',
                  'Enter the date when the equipment was received by the Accountable Officer in the \'MM/DD/YYYY\' format.',
                  'date',
                  required.officer || form.accountable_officer_id !== null,
                )}
                {personField('end_user_id', 'End user', 'Select the Custodian or End User from the dropdown to whom the equipment is assigned.')}
                {textField(
                  'date_assigned_end_user',
                  'Date assigned',
                  'Enter the date when the equipment was received by the Custodian/End User in the \'MM/DD/YYYY\' format.',
                  'date',
                  form.end_user_id !== null,
                )}
                {personField('received_by_id', 'Received by', 'Choose the new Accountable Officer, Custodian, or End User from the dropdown for the newly assigned equipment.', required.receiver)}
                {textField(
                  'date_received_new_accountable',
                  'Date received',
                  'Enter the date when the new Accountable Officer, Custodian, or End User received the equipment in the \'MM/DD/YYYY\' format.',
                  'date',
                  required.receiver || form.received_by_id !== null,
                )}
                <TransactionDocumentsField
                  id="tx-documents"
                  rows={form.documents}
                  onChange={(documents) => set('documents', documents)}
                  documentTypes={options?.supporting_document_type}
                  required={required.documents}
                  needsWmr={required.wmr}
                  errors={errors}
                  errorPrefix="documents"
                  disabled={saving}
                />
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
                      {(transaction.documents ?? []).length === 0
                        ? '—'
                        : transaction.documents?.map((document) => (
                            <div key={document.id}>
                              {[document.document_type, document.document_no].filter(Boolean).join(' · ')}
                              {document.has_file && (
                                <button
                                  type="button"
                                  className="ml-2 font-medium text-primary-700 underline-offset-2 hover:underline"
                                  onClick={() => void equipmentService.downloadTransactionDocument(equipmentId, transaction.id, document)}
                                >
                                  Download
                                </button>
                              )}
                            </div>
                          ))}
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
