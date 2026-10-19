import { ArrowLeft, Building2, Trash2 } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import {
  OfficeFields,
  officeDeleteErrorMessage,
  officeFieldErrors,
  officeToForm,
  toOfficePayload,
  type OfficeFieldErrors,
} from '@/components/offices/OfficeFields'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import { officeService } from '@/services/officeService'
import type { Office, OfficeFormData } from '@/types/office'

type Feedback = { type: 'success' | 'error'; message: string }

export function OfficeEdit() {
  const { id } = useParams<{ id: string }>()
  const officeId = Number(id)
  const navigate = useNavigate()
  const { can } = useAuth()
  const canEdit = can('office.edit')

  const [office, setOffice] = useState<Office | null>(null)
  const [form, setForm] = useState<OfficeFormData | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [errors, setErrors] = useState<OfficeFieldErrors>({})
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const [saving, setSaving] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)

  usePageTitle(office ? `${canEdit ? 'Edit' : 'View'} ${office.office_name}` : 'Office')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadError(null)
    officeService
      .show(officeId)
      .then((loaded) => {
        if (cancelled) return
        setOffice(loaded)
        setForm(officeToForm(loaded))
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractError(error, 'Unable to load this office.'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [officeId])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form || !office) return
    setSaving(true)
    setErrors({})
    setFeedback(null)
    try {
      const updated = await officeService.update(office.id, toOfficePayload(form))
      setOffice(updated)
      setForm(officeToForm(updated))
      setFeedback({ type: 'success', message: 'Office details saved.' })
    } catch (error) {
      const fieldErrors = officeFieldErrors(error)
      setErrors(fieldErrors ?? {})
      setFeedback({ type: 'error', message: fieldErrors ? 'Please correct the highlighted fields.' : extractError(error, 'Unable to save this office. Please try again.') })
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!office) return
    setDeleting(true)
    try {
      await officeService.remove(office.id)
      navigate('/portal/offices', { state: { flash: `"${office.office_name}" was deleted.` } })
    } catch (error) {
      setFeedback({ type: 'error', message: officeDeleteErrorMessage(error) })
      setConfirmDelete(false)
      setDeleting(false)
    }
  }

  if (loading) return <DetailPageSkeleton />
  if (loadError || !office || !form) {
    return (
      <div className="space-y-4">
        <Alert type="error">{loadError ?? 'Office not found.'}</Alert>
        <Button variant="outline" leftIcon={<ArrowLeft className="h-4 w-4" />} onClick={() => navigate('/portal/offices')}>
          Back to offices
        </Button>
      </div>
    )
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <Button variant="ghost" size="sm" onClick={() => navigate('/portal/offices')} aria-label="Back to offices" className="mt-1.5">
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <span className="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-700 sm:flex" aria-hidden="true">
            <Building2 className="h-6 w-6" />
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-bold text-gray-900">{office.office_name}</h1>
              <Badge color={office.is_active ? 'success' : 'gray'}>{office.is_active ? 'Active' : 'Inactive'}</Badge>
            </div>
            <p className="text-sm text-gray-600">
              {[office.office_type, office.school_id !== null ? `School ID ${office.school_id}` : null].filter(Boolean).join(' · ') ||
                "Update this office's identification, location, and contact details."}
            </p>
          </div>
        </div>
        {can('office.delete') && (
          <div className="page-actions">
            <Button variant="outline" size="sm" leftIcon={<Trash2 className="h-4 w-4 text-error-600" />} onClick={() => setConfirmDelete(true)}>
              Delete
            </Button>
          </div>
        )}
      </div>

      {!canEdit && (
        <Alert type="info" className="mb-4">
          You can view this office but not change it.
        </Alert>
      )}

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          {feedback.message}
        </Alert>
      )}

      <form onSubmit={handleSubmit} noValidate>
        <OfficeFields
          value={form}
          onChange={(patch) => setForm((current) => (current ? { ...current, ...patch } : current))}
          errors={errors}
          disabled={!canEdit || saving}
        />

        {canEdit && (
          <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => navigate('/portal/offices')} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving}>
              Save changes
            </Button>
          </div>
        )}
      </form>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={(open) => !deleting && setConfirmDelete(open)}
        onConfirm={handleDelete}
        title="Delete office?"
        description={`This permanently deletes "${office.office_name}". If user accounts, stakeholder profiles or connectivity surveys still refer to it, deactivate it instead.`}
        confirmText="Delete"
        loading={deleting}
      />
    </div>
  )
}
