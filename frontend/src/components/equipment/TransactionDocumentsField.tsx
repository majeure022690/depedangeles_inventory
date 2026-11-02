import { FileUp, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'

import { Button } from '@/components/ui/Button'
import { extractError } from '@/lib/errors'
import { WASTE_MATERIAL_REPORT } from '@/lib/transactionRules'
import { equipmentService } from '@/services/equipmentService'
import type { TransactionDocumentRow } from '@/types/equipment'
import type { Option } from '@/types/lookup'

const MAX_ROWS = 10

export function emptyDocumentRow(): TransactionDocumentRow {
  return { key: crypto.randomUUID(), document_type: '', document_no: '', upload_id: null, file_name: null }
}

interface TransactionDocumentsFieldProps {
  id: string
  rows: TransactionDocumentRow[]
  onChange: (rows: TransactionDocumentRow[]) => void
  documentTypes: Option<string>[] | undefined
  required: boolean
  needsWmr: boolean
  errors: Partial<Record<string, string>>
  /** Error key prefix, e.g. `documents` or `accountability.documents`. */
  errorPrefix: string
  disabled?: boolean
}

/** Supporting documents as a growable list; each file uploads as soon as it is picked. */
export function TransactionDocumentsField({ id, rows, onChange, documentTypes, required, needsWmr, errors, errorPrefix, disabled = false }: TransactionDocumentsFieldProps) {
  const [uploading, setUploading] = useState<string | null>(null)
  const [uploadErrors, setUploadErrors] = useState<Record<string, string>>({})

  const update = (key: string, patch: Partial<TransactionDocumentRow>) => onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)))

  async function pickFile(key: string, file: File | undefined) {
    if (!file) return
    setUploading(key)
    setUploadErrors((current) => ({ ...current, [key]: '' }))
    update(key, { upload_id: null, file_name: null })
    try {
      const uploaded = await equipmentService.uploadTransactionDocument(file)
      update(key, { upload_id: uploaded.id, file_name: uploaded.original_filename })
    } catch (error) {
      setUploadErrors((current) => ({ ...current, [key]: extractError(error, 'The file could not be uploaded.') }))
    } finally {
      setUploading(null)
    }
  }

  const listError = errors[errorPrefix]

  return (
    <fieldset className="space-y-3 sm:col-span-2" aria-describedby={`${id}-hint`}>
      <legend className="text-sm font-medium text-gray-700">
        Supporting documents {required ? <span className="text-error-600">*</span> : <span className="text-xs font-normal text-error-500">(optional)</span>}
      </legend>
      <p id={`${id}-hint`} className="text-xs text-gray-500">
        Add each document with its number and a scan or photo (PDF, JPG, PNG or WEBP, up to 5 MB).
        {needsWmr && ` A disposal needs a ${WASTE_MATERIAL_REPORT}.`}
      </p>
      {listError && (
        <p className="text-sm text-error-600" role="alert">
          {listError}
        </p>
      )}
      {rows.map((row, index) => {
        const rowError = (field: string) => errors[`${errorPrefix}.${index}.${field}`]
        return (
          <div key={row.key} className="grid gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-12">
            <label className="text-xs font-medium text-gray-600 sm:col-span-4">
              Document type
              <select
                className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                value={row.document_type}
                disabled={disabled}
                aria-invalid={rowError('document_type') ? true : undefined}
                onChange={(e) => update(row.key, { document_type: e.target.value })}
              >
                <option value="">Select a document type</option>
                {documentTypes?.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
              {rowError('document_type') && <span className="mt-1 block text-error-600">{rowError('document_type')}</span>}
            </label>
            <label className="text-xs font-medium text-gray-600 sm:col-span-3">
              Document no. <span className="font-normal text-error-500">(optional)</span>
              <input
                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                value={row.document_no}
                maxLength={255}
                disabled={disabled}
                onChange={(e) => update(row.key, { document_no: e.target.value })}
              />
            </label>
            <div className="text-xs font-medium text-gray-600 sm:col-span-4">
              File
              <label className="mt-1 flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-normal text-gray-700 hover:bg-gray-50">
                <FileUp className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                <span className="truncate">{uploading === row.key ? 'Uploading…' : (row.file_name ?? 'Choose a scan or photo')}</span>
                <input
                  type="file"
                  className="sr-only"
                  accept=".pdf,.jpg,.jpeg,.png,.webp"
                  disabled={disabled || uploading !== null}
                  onChange={(e) => void pickFile(row.key, e.target.files?.[0])}
                />
              </label>
              {(uploadErrors[row.key] || rowError('upload_id')) && <span className="mt-1 block text-error-600">{uploadErrors[row.key] || rowError('upload_id')}</span>}
            </div>
            <div className="flex items-end sm:col-span-1">
              <Button
                variant="ghost"
                size="sm"
                aria-label={`Remove document ${index + 1}`}
                disabled={disabled}
                onClick={() => onChange(rows.filter((other) => other.key !== row.key))}
              >
                <Trash2 className="h-4 w-4 text-error-600" />
              </Button>
            </div>
          </div>
        )
      })}
      {rows.length < MAX_ROWS && (
        <Button variant="outline" size="sm" leftIcon={<Plus className="h-4 w-4" />} disabled={disabled} onClick={() => onChange([...rows, emptyDocumentRow()])}>
          Add document
        </Button>
      )}
    </fieldset>
  )
}
