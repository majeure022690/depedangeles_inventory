import { ArrowLeft, Trash2 } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { PersonnelFields, personnelToForm } from '@/components/personnel/PersonnelFields'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, type FieldErrors } from '@/lib/errors'
import { personnelService, toPersonnelPayload } from '@/services/personnelService'
import type { Personnel, PersonnelFormData, PersonnelOptions } from '@/types/personnel'

export function PersonnelEdit() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { can } = useAuth()
  const canEdit = can('personnel.edit')
  const canDelete = can('personnel.delete')

  const [personnel, setPersonnel] = useState<Personnel | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [options, setOptions] = useState<PersonnelOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)

  const [form, setForm] = useState<PersonnelFormData | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  usePageTitle(personnel ? `${canEdit ? 'Edit' : 'Personnel'} ${personnel.full_name}` : 'Personnel')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    personnelService
      .show(id!)
      .then((record) => {
        if (cancelled) return
        setPersonnel(record)
        setForm(personnelToForm(record))
        setLoadError(null)
      })
      .catch((e: unknown) => !cancelled && setLoadError(extractError(e, 'Unable to load this personnel record.')))
      .finally(() => !cancelled && setLoading(false))
    return () => {
      cancelled = true
    }
  }, [id])

  useEffect(() => {
    personnelService
      .options()
      .then(setOptions)
      .catch((e: unknown) => setOptionsError(extractError(e, 'Unable to load the form options. Please reload the page.')))
  }, [])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!personnel || !form) return
    setSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const response = await personnelService.update(personnel.id, toPersonnelPayload(form))
      navigate('/portal/personnel', { state: { flash: response.message } })
    } catch (e) {
      setErrors(extractFieldErrors(e))
      setFormError(extractError(e, 'Unable to save this personnel record. Please try again.'))
      window.scrollTo({ top: 0, behavior: 'smooth' })
      setSaving(false)
    }
  }

  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  async function confirmDelete() {
    if (!personnel) return
    setDeleting(true)
    try {
      await personnelService.remove(personnel.id)
      navigate('/portal/personnel', { state: { flash: 'Personnel record deleted.' } })
    } catch (e) {
      setFormError(extractError(e, 'Unable to delete this personnel record.'))
      setDeleteOpen(false)
      setDeleting(false)
    }
  }

  if (loading) return <DetailPageSkeleton />

  if (!personnel || !form) {
    return (
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/portal/personnel')} aria-label="Back to personnel">
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <Alert type="error" className="mt-4">
          {loadError ?? 'This personnel record could not be found.'}
        </Alert>
      </div>
    )
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <Button variant="ghost" size="sm" onClick={() => navigate('/portal/personnel')} aria-label="Back to personnel" className="mt-0.5">
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-bold text-gray-900">
                {canEdit ? 'Edit' : 'Personnel'} {personnel.full_name}
              </h1>
              {personnel.inactive ? <Badge color="error">Inactive</Badge> : <Badge color="success">Active</Badge>}
            </div>
            <p className="text-sm text-gray-600">
              Employee ID <span className="font-mono">{personnel.employee_id}</span>
              {canEdit && ' · Update this personnel record.'}
            </p>
          </div>
        </div>
        {canDelete && (
          <div className="page-actions">
            <Button variant="outline" size="sm" leftIcon={<Trash2 className="h-4 w-4 text-error-600" />} onClick={() => setDeleteOpen(true)}>
              Delete
            </Button>
          </div>
        )}
      </div>

      <form onSubmit={submit} noValidate className="space-y-4">
        <div role="alert" className="space-y-2 empty:hidden">
          {optionsError && <Alert type="error">{optionsError}</Alert>}
          {formError && <Alert type="error">{formError}</Alert>}
        </div>
        {!canEdit && <Alert type="info">You can view this record but not edit it.</Alert>}

        <PersonnelFields value={form} onChange={setForm} options={options} errors={errors} disabled={!canEdit} current={personnel} />

        {canEdit && (
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => navigate('/portal/personnel')}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving} disabled={!options}>
              Save changes
            </Button>
          </div>
        )}
      </form>

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={(open) => !deleting && setDeleteOpen(open)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete personnel record?"
        description={`This will soft-delete ${personnel.full_name}. The record can be restored later if needed, but it will disappear from the personnel list.`}
        confirmText="Delete"
      />
    </div>
  )
}
