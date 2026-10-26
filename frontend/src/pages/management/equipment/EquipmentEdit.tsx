import { ArrowLeft, Download, Printer, Tag, Trash2, UserRound } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { EquipmentFields, equipmentToForm } from '@/components/equipment/EquipmentFields'
import { TransactionLog } from '@/components/equipment/TransactionLog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DetailSection } from '@/components/ui/DetailSection'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { scopedTitle } from '@/lib/dataScope'
import { extractError, extractFieldErrors, extractLoadError, isNotFound, OUT_OF_REACH_MESSAGE, type FieldErrors } from '@/lib/errors'
import { equipmentService, toEquipmentPayload } from '@/services/equipmentService'
import type { Equipment, EquipmentFormData, EquipmentOptions } from '@/types/equipment'

export function EquipmentEdit() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  const { can, user } = useAuth()
  const listTitle = scopedTitle('equipment', user?.data_scope)
  const canEdit = can('equipment.edit')
  const canDelete = can('equipment.delete')
  const canRecord = can('equipment.transactions.create')

  const [equipment, setEquipment] = useState<Equipment | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [options, setOptions] = useState<EquipmentOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)

  const [form, setForm] = useState<EquipmentFormData | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [success, setSuccess] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)

  // Property no. is null while pending official issuance; never show the literal "null".
  const displayPropertyNo = equipment?.property_no ?? 'Pending property no.'
  usePageTitle(equipment ? `${canEdit ? 'Edit' : 'Equipment'} ${displayPropertyNo}` : listTitle)

  useEffect(() => {
    if (location.state) navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  const load = useCallback(async () => {
    try {
      const record = await equipmentService.show(id!)
      setEquipment(record)
      setForm(equipmentToForm(record))
      setLoadError(null)
    } catch (e) {
      if (isNotFound(e)) {
        setEquipment(null)
        setForm(null)
      }
      setLoadError(extractLoadError(e, 'Unable to load this equipment record.'))
    }
  }, [id])

  useEffect(() => {
    setLoading(true)
    load().finally(() => setLoading(false))
  }, [load])

  useEffect(() => {
    equipmentService
      .options()
      .then(setOptions)
      .catch((e: unknown) => setOptionsError(extractError(e, 'Unable to load the form options. Please reload the page.')))
  }, [])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!equipment || !form) return
    setSaving(true)
    setErrors({})
    setFormError(null)
    setSuccess(null)
    try {
      const response = await equipmentService.update(equipment.id, toEquipmentPayload(form))
      const updated = { ...response.data, transactions: equipment.transactions }
      setEquipment(updated)
      setForm(equipmentToForm(updated))
      setSuccess(response.message)
    } catch (e) {
      setErrors(extractFieldErrors(e))
      setFormError(extractError(e, 'Unable to save this equipment record. Please try again.'))
    } finally {
      setSaving(false)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  async function confirmDelete() {
    if (!equipment) return
    setDeleting(true)
    try {
      await equipmentService.remove(equipment.id)
      navigate('/equipment', { state: { flash: 'Equipment record deleted.' } })
    } catch (e) {
      setFormError(extractError(e, 'Unable to delete this equipment record.'))
      setDeleteOpen(false)
      setDeleting(false)
    }
  }

  async function handleRecorded(message: string) {
    await load()
    setSuccess(message)
  }

  if (loading) return <DetailPageSkeleton />

  if (!equipment || !form) {
    return (
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/equipment')} aria-label={`Back to ${listTitle.toLowerCase()}`}>
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <Alert type="error" className="mt-4">
          {loadError ?? OUT_OF_REACH_MESSAGE}
        </Alert>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <Button variant="ghost" size="sm" onClick={() => navigate('/equipment')} aria-label={`Back to ${listTitle.toLowerCase()}`} className="mt-0.5">
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-bold text-gray-900">
                {canEdit ? 'Edit' : 'Equipment'} {displayPropertyNo}
              </h1>
              {!equipment.property_no && <Badge color="warning">Pending</Badge>}
            </div>
            <p className="text-sm text-gray-600">
              {equipment.item ?? 'Equipment'} — currently held by {equipment.current_accountable_officer?.full_name ?? 'no one on record'}
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

      <div role="status" className="empty:hidden">
        {success && <Alert type="success">{success}</Alert>}
      </div>
      <div role="alert" className="space-y-2 empty:hidden">
        {optionsError && <Alert type="error">{optionsError}</Alert>}
        {formError && <Alert type="error">{formError}</Alert>}
      </div>

      <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
        <QrCodeCard equipmentId={equipment.id} propertyNo={equipment.property_no} displayPropertyNo={displayPropertyNo} />
        <Card className="lg:col-span-2">
          <DetailSection icon={<UserRound className="h-3.5 w-3.5" />} title="Current accountability">
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <SummaryItem label="Accountable officer" value={equipment.current_accountable_officer?.full_name} />
              <SummaryItem label="End user" value={equipment.current_end_user?.full_name} />
            </dl>
          </DetailSection>
          <DetailSection icon={<Tag className="h-3.5 w-3.5" />} title="Status" last>
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <SummaryItem label="Condition" value={equipment.equipment_condition} />
              <SummaryItem label="Disposition" value={equipment.disposition_status} />
              <SummaryItem label="Category" value={equipment.category} />
              <SummaryItem label="Brand / model" value={[equipment.brand_manufacturer, equipment.model].filter(Boolean).join(' — ') || null} />
            </dl>
          </DetailSection>
        </Card>
      </div>

      <form onSubmit={submit} noValidate className="space-y-4">
        {!canEdit && <Alert type="info">You can view this record but not edit it.</Alert>}
        <EquipmentFields value={form} onChange={setForm} options={options?.form ?? null} errors={errors} disabled={!canEdit} current={equipment} />
        {canEdit && (
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => navigate('/equipment')}>
              Cancel
            </Button>
            <Button type="submit" isLoading={saving} disabled={!options}>
              Save changes
            </Button>
          </div>
        )}
      </form>

      <TransactionLog
        equipmentId={equipment.id}
        transactions={equipment.transactions ?? []}
        options={options?.transaction ?? null}
        personnelOptions={options?.personnel ?? []}
        canRecord={canRecord}
        onRecorded={handleRecorded}
      />

      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={(open) => !deleting && setDeleteOpen(open)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete equipment record?"
        description={`This will soft-delete ${
          equipment.property_no ? `property no. ${equipment.property_no}` : 'this pending equipment record'
        }. The record can be restored later if needed, but it will disappear from the equipment list.`}
        confirmText="Delete"
      />
    </div>
  )
}

function SummaryItem({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-gray-500">{label}</dt>
      <dd className="font-medium text-gray-900 [overflow-wrap:anywhere]">{value || '—'}</dd>
    </div>
  )
}

/** Fetches the QR PNG with the session cookie (an `<img src>` to another origin wouldn't send it) and serves it from an object URL. */
function QrCodeCard({ equipmentId, propertyNo, displayPropertyNo }: { equipmentId: number; propertyNo: string | null; displayPropertyNo: string }) {
  const [url, setUrl] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false
    setUrl(null)
    setError(null)
    equipmentService
      .qrCode(equipmentId)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch(() => !cancelled && setError('Unable to load the QR code.'))
    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [equipmentId])

  const fileName = `equipment-${propertyNo ?? equipmentId}-qr.png`
  const alt = `QR code for property no. ${displayPropertyNo}`

  function download() {
    if (!url) return
    const link = document.createElement('a')
    link.href = url
    link.download = fileName
    document.body.appendChild(link)
    link.click()
    link.remove()
  }

  function print() {
    if (!url) return
    const win = window.open('', '_blank', 'width=480,height=600')
    if (!win) {
      setError('Allow pop-ups for this site to print the QR code, or download it instead.')
      return
    }
    const doc = win.document
    doc.title = `QR code — ${displayPropertyNo}`
    doc.body.style.cssText = 'margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:Poppins,sans-serif'
    const image = doc.createElement('img')
    image.alt = alt
    image.style.width = '240px'
    image.style.height = '240px'
    const caption = doc.createElement('p')
    caption.textContent = displayPropertyNo
    caption.style.cssText = 'margin:8px 0 0;font-size:14px;font-weight:600'
    doc.body.append(image, caption)
    image.onload = () => {
      win.focus()
      win.print()
    }
    image.src = url
  }

  return (
    <Card>
      <CardHeader className="py-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-600">QR code</h2>
      </CardHeader>
      <CardContent className="flex flex-col items-center gap-3 text-center">
        {url ? (
          <img src={url} alt={alt} width={128} height={128} className="h-32 w-32 rounded-md border border-gray-200 bg-white p-2" />
        ) : error ? null : (
          <Skeleton className="h-32 w-32" />
        )}
        {error && <p className="text-sm text-error-600">{error}</p>}
        <p className="text-sm text-gray-600">Scan or print this code to identify property no. {displayPropertyNo}.</p>
        <div className="flex flex-wrap justify-center gap-2">
          <Button variant="outline" size="sm" leftIcon={<Printer className="h-4 w-4" />} onClick={print} disabled={!url}>
            Print
          </Button>
          <Button variant="outline" size="sm" leftIcon={<Download className="h-4 w-4" />} onClick={download} disabled={!url}>
            Download
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
