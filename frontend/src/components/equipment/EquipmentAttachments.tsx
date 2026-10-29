import { Camera, Download, Eye, FileText, Image as ImageIcon, Trash2, Upload } from 'lucide-react'
import { useRef, useState, type KeyboardEvent } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { extractError, extractFieldErrors } from '@/lib/errors'
import { formatDate } from '@/lib/utils'
import { equipmentService } from '@/services/equipmentService'
import type { EquipmentAttachment } from '@/types/equipment'

const MAX_BYTES = 5 * 1024 * 1024
const ACCEPT = 'application/pdf,image/jpeg,image/png,image/webp'

interface EquipmentAttachmentsProps {
  equipmentId: number
  attachments: EquipmentAttachment[]
  documentTypes: string[]
  canEdit: boolean
  onChanged: (attachments: EquipmentAttachment[]) => void
}

function formatSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

export function EquipmentAttachments({ equipmentId, attachments, documentTypes, canEdit, onChanged }: EquipmentAttachmentsProps) {
  const fileInput = useRef<HTMLInputElement>(null)
  const cameraInput = useRef<HTMLInputElement>(null)
  const [documentType, setDocumentType] = useState('')
  const [documentNo, setDocumentNo] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [pendingDelete, setPendingDelete] = useState<EquipmentAttachment | null>(null)
  const [deleting, setDeleting] = useState(false)

  function choose(selected: File | null) {
    setErrors((current) => ({ ...current, file: '' }))
    if (selected && selected.size > MAX_BYTES) {
      setFile(null)
      setErrors((current) => ({ ...current, file: 'The file must be 5 MB or smaller. Scan at a lower quality or in black-and-white and try again.' }))
      return
    }
    setFile(selected)
  }

  async function upload() {
    const nextErrors: Record<string, string> = {}
    if (!documentType) nextErrors.document_type = 'Select the document type.'
    if (!file) nextErrors.file = 'Choose a file or take a photo.'
    setErrors(nextErrors)
    setError(null)
    if (!file || !documentType) return

    setUploading(true)
    try {
      const response = await equipmentService.uploadAttachment(equipmentId, file, documentType, documentNo)
      onChanged([response.data, ...attachments])
      setDocumentNo('')
      setFile(null)
      if (fileInput.current) fileInput.current.value = ''
      if (cameraInput.current) cameraInput.current.value = ''
    } catch (e) {
      const fieldErrors = extractFieldErrors(e)
      setErrors(fieldErrors)
      if (Object.keys(fieldErrors).length === 0) setError(extractError(e, 'Unable to upload the file.'))
    } finally {
      setUploading(false)
    }
  }

  async function open(attachment: EquipmentAttachment, asDownload: boolean) {
    setError(null)
    const viewer = asDownload ? null : window.open('', '_blank')
    try {
      const blob = await equipmentService.attachmentFile(equipmentId, attachment.id)
      const url = URL.createObjectURL(blob)
      if (viewer) {
        viewer.opener = null
        viewer.location.href = url
      } else {
        const link = document.createElement('a')
        link.href = url
        link.download = attachment.original_filename
        link.click()
      }
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (e) {
      viewer?.close()
      setError(extractError(e, 'Unable to open the file.'))
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await equipmentService.removeAttachment(equipmentId, pendingDelete.id)
      onChanged(attachments.filter((a) => a.id !== pendingDelete.id))
      setPendingDelete(null)
    } catch (e) {
      setError(extractError(e, 'Unable to delete the file.'))
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  function blockEnter(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') e.preventDefault()
  }

  return (
    <section aria-labelledby="equipment-attachments-heading" className="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
      <h2 id="equipment-attachments-heading" className="text-base font-semibold text-gray-900">
        Attached files
      </h2>
      <p className="mt-1 text-sm text-gray-600">Scanned documents or photos (PDF, JPG, PNG or WEBP, up to 5 MB each).</p>

      {error && (
        <Alert type="error" className="mt-4">
          {error}
        </Alert>
      )}

      {attachments.length === 0 ? (
        <p className="mt-4 text-sm text-gray-500">No files attached yet.</p>
      ) : (
        <ul className="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200">
          {attachments.map((attachment) => {
            const Icon = attachment.mime_type === 'application/pdf' ? FileText : ImageIcon
            return (
              <li key={attachment.id} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                  <Icon className="mt-0.5 h-5 w-5 shrink-0 text-primary-600" aria-hidden="true" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-900">
                      {attachment.document_type}
                      {attachment.document_no && <span className="font-normal text-gray-600"> · No. {attachment.document_no}</span>}
                    </p>
                    <p className="truncate text-sm text-gray-600">{attachment.original_filename}</p>
                    <p className="text-xs text-gray-500">
                      {formatSize(attachment.file_size)}
                      {attachment.created_at && ` · ${formatDate(attachment.created_at)}`}
                      {attachment.uploaded_by && ` · ${attachment.uploaded_by}`}
                    </p>
                  </div>
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={() => open(attachment, false)} aria-label={`View ${attachment.original_filename}`}>
                    <Eye className="h-4 w-4" aria-hidden="true" />
                    View
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={() => open(attachment, true)} aria-label={`Download ${attachment.original_filename}`}>
                    <Download className="h-4 w-4" aria-hidden="true" />
                    Download
                  </Button>
                  {canEdit && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => setPendingDelete(attachment)} aria-label={`Delete ${attachment.original_filename}`}>
                      <Trash2 className="h-4 w-4 text-error-600" aria-hidden="true" />
                    </Button>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {canEdit && (
        <div className="mt-6 grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
          <Select
            id="attachment-document-type"
            label="Document type"
            placeholder="Select a document type"
            value={documentType}
            onChange={(e) => {
              setDocumentType(e.target.value)
              setErrors((current) => ({ ...current, document_type: '' }))
            }}
            options={documentTypes.map((type) => ({ label: type, value: type }))}
            error={errors.document_type || undefined}
            aria-required="true"
          />
          <Input
            id="attachment-document-no"
            label="Document no. (optional)"
            value={documentNo}
            maxLength={255}
            onChange={(e) => setDocumentNo(e.target.value)}
            onKeyDown={blockEnter}
            error={errors.document_no || undefined}
          />
          <div className="sm:col-span-2">
            <div className="flex flex-wrap items-center gap-2">
              <Button type="button" variant="outline" onClick={() => fileInput.current?.click()}>
                <Upload className="h-4 w-4" aria-hidden="true" />
                Choose file
              </Button>
              <Button type="button" variant="outline" onClick={() => cameraInput.current?.click()}>
                <Camera className="h-4 w-4" aria-hidden="true" />
                Take photo
              </Button>
              <span className="min-w-0 truncate text-sm text-gray-600" aria-live="polite">
                {file ? `${file.name} (${formatSize(file.size)})` : 'No file selected'}
              </span>
            </div>
            <input ref={fileInput} type="file" accept={ACCEPT} className="hidden" tabIndex={-1} onChange={(e) => choose(e.target.files?.[0] ?? null)} />
            <input ref={cameraInput} type="file" accept="image/*" capture="environment" className="hidden" tabIndex={-1} onChange={(e) => choose(e.target.files?.[0] ?? null)} />
            {errors.file && (
              <p className="mt-1 text-sm text-error-600" role="alert">
                {errors.file}
              </p>
            )}
          </div>
          <div className="sm:col-span-2 sm:flex sm:justify-end">
            <Button type="button" onClick={upload} isLoading={uploading}>
              Upload file
            </Button>
          </div>
        </div>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(isOpen) => !isOpen && setPendingDelete(null)}
        onConfirm={confirmDelete}
        title="Delete this file?"
        description={pendingDelete ? `${pendingDelete.original_filename} will be removed from this record. The deletion is recorded in the audit log.` : ''}
        confirmText="Delete file"
        loading={deleting}
      />
    </section>
  )
}
