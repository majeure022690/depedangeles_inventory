import { ArrowLeft, Download, Info, Printer, Tag, Trash2, Unplug, UserRound } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { BundledPeripherals } from '@/components/equipment/BundledPeripherals'
import { EquipmentAttachments } from '@/components/equipment/EquipmentAttachments'
import {
  EQUIPMENT_FIELD_SECTIONS,
  EquipmentFields,
  emptyAccountability,
  equipmentToForm,
  firstSectionWithError,
  isEquipmentFieldSection,
  sectionErrorCount,
} from '@/components/equipment/EquipmentFields'
import { TransactionLog } from '@/components/equipment/TransactionLog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DetailSection } from '@/components/ui/DetailSection'
import { Skeleton } from '@/components/ui/Skeleton'
import { TabPanel, Tabs, type TabItem } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { scopedTitle } from '@/lib/dataScope'
import { BUNDLE_PARENT_ITEMS, PERIPHERAL_ITEMS } from '@/lib/equipmentBundles'
import { extractError, extractFieldErrors, extractLoadError, isNotFound, OUT_OF_REACH_MESSAGE, type FieldErrors } from '@/lib/errors'
import { formatDate } from '@/lib/utils'
import { equipmentService, toEquipmentPayload } from '@/services/equipmentService'
import type { Equipment, EquipmentFormData, EquipmentOptions, EquipmentParentSummary, EquipmentPeripheral, EquipmentTransactionFormData } from '@/types/equipment'

const TABS_ID = 'equipment-record'
const PERIPHERALS_TAB = 'peripherals'
const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function EquipmentEdit() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams, setSearchParams] = useSearchParams()
  const { can, user } = useAuth()
  const listTitle = scopedTitle('equipment', user?.data_scope)
  const canEdit = can('equipment.edit')
  const canDelete = can('equipment.delete')
  const canRecord = can('equipment.transactions.create')
  const canCreate = can('equipment.create')

  const [equipment, setEquipment] = useState<Equipment | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [options, setOptions] = useState<EquipmentOptions | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)

  const [form, setForm] = useState<EquipmentFormData | null>(null)
  const [accountability, setAccountability] = useState<EquipmentTransactionFormData>(emptyAccountability())
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [success, setSuccess] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)

  // Property no. is null while pending official issuance; never show the literal "null".
  const displayPropertyNo = equipment?.property_no ?? 'Pending property no.'
  usePageTitle(equipment ? `${canEdit ? 'Edit' : 'Equipment'} ${displayPropertyNo}` : listTitle)

  useEffect(() => {
    if (location.state) navigate({ pathname: location.pathname, search: location.search }, { replace: true, state: null })
  }, [location.pathname, location.search, location.state, navigate])

  const selectTab = useCallback(
    (next: string) => {
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev)
          if (next === 'overview') params.delete('tab')
          else params.set('tab', next)
          return params
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  const load = useCallback(async (keepEdits = false) => {
    try {
      const record = await equipmentService.show(id!)
      setEquipment(record)
      setForm((current) => (keepEdits && current ? current : equipmentToForm(record)))
      
      // Populate accountability from the most recent transaction
      const latestTransaction = record.transactions?.[0]
      if (latestTransaction && !keepEdits) {
        setAccountability({
          transaction_type: latestTransaction.transaction_type,
          accountable_officer_id: latestTransaction.accountable_officer?.id ?? null,
          end_user_id: latestTransaction.end_user?.id ?? null,
          received_by_id: latestTransaction.received_by?.id ?? null,
          date_assigned_accountable_officer: latestTransaction.date_assigned_accountable_officer ?? '',
          date_assigned_end_user: latestTransaction.date_assigned_end_user ?? '',
          date_received_new_accountable: latestTransaction.date_received_new_accountable ?? '',
          supporting_documents1: latestTransaction.supporting_documents1 ?? '',
          or_si_dr_iar_no: latestTransaction.or_si_dr_iar_no ?? '',
          supporting_documents2: latestTransaction.supporting_documents2 ?? '',
          par_ics_rrsp_rs_wmr_no: latestTransaction.par_ics_rrsp_rs_wmr_no ?? '',
        })
      }
      
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
      const updated = {
        ...response.data,
        parent: response.data.parent ?? equipment.parent,
        children: response.data.children ?? equipment.children,
        transactions: equipment.transactions,
      }
      setEquipment(updated)
      setForm(equipmentToForm(updated))
      setSuccess(response.message)
    } catch (e) {
      const fieldErrors = extractFieldErrors(e)
      setErrors(fieldErrors)
      setFormError(extractError(e, 'Unable to save this equipment record. Please try again.'))
      const errorTab = firstSectionWithError(fieldErrors)
      if (errorTab) selectTab(errorTab)
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
    await load(true)
    setSuccess(message)
  }

  async function handlePeripheralsChanged(message: string) {
    setFormError(null)
    await load(true)
    setSuccess(message)
  }

  if (loading) return <DetailPageSkeleton />

  const isPeripheral = equipment?.parent_equipment_id != null
  const originalBundle =
    equipment?.original_bundle && equipment.original_bundle.id !== equipment.id && (isPeripheral || PERIPHERAL_ITEMS.includes(equipment.item ?? ''))
      ? equipment.original_bundle
      : null
  const showBundle = !!equipment && !isPeripheral && (BUNDLE_PARENT_ITEMS.includes(equipment.item ?? '') || (equipment.children?.length ?? 0) > 0)

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

  const tabs: TabItem[] = [
    { value: 'overview', label: 'Overview' },
    ...EQUIPMENT_FIELD_SECTIONS.map((section) => ({ value: section.value, label: section.label, errorCount: sectionErrorCount(section.value, errors) })),
    ...(showBundle ? [{ value: PERIPHERALS_TAB, label: 'Peripherals', count: equipment.children?.length ?? 0 }] : []),
    { value: 'history', label: 'History', count: equipment.transactions?.length ?? 0 },
  ]
  const requestedTab = searchParams.get('tab')
  const tab = tabs.some((item) => item.value === requestedTab) ? requestedTab! : 'overview'
  const fieldTab = isEquipmentFieldSection(tab) ? tab : null

  return (
    <div className="min-w-0 space-y-4">
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

      {(isPeripheral || originalBundle) && (
        <Alert type="info">
          <BundleMembership isPeripheral={isPeripheral} current={equipment.parent ?? null} original={originalBundle} />
        </Alert>
      )}

      <div role="status" className="empty:hidden">
        {success && <Alert type="success">{success}</Alert>}
      </div>
      <div role="alert" className="space-y-2 empty:hidden">
        {optionsError && <Alert type="error">{optionsError}</Alert>}
        {formError && <Alert type="error">{formError}</Alert>}
      </div>

      <div>
        <Tabs id={TABS_ID} label="Equipment record sections" tabs={tabs} value={tab} onChange={selectTab} />

        <TabPanel tabsId={TABS_ID} value="overview" active={tab === 'overview'}>
          <Overview equipment={equipment} displayPropertyNo={displayPropertyNo} showBundle={showBundle} />
        </TabPanel>

        {fieldTab && (
          <form onSubmit={submit} noValidate aria-label="Equipment details">
            {EQUIPMENT_FIELD_SECTIONS.map((section) => (
              <TabPanel key={section.value} tabsId={TABS_ID} value={section.value} active={fieldTab === section.value}>
                <div className="space-y-4">
                  {!canEdit && <Alert type="info">You can view this record but not edit it.</Alert>}
                  <EquipmentFields
                    section={section.value}
                    mode="edit"
                    value={form}
                    onChange={setForm}
                    options={options}
                    errors={errors}
                    disabled={!canEdit}
                    current={equipment}
                    accountability={accountability}
                    onAccountabilityChange={setAccountability}
                  />
                  {section.value === 'documents' && (
                    <EquipmentAttachments
                      equipmentId={equipment.id}
                      attachments={equipment.attachments ?? []}
                      documentTypes={options?.attachment_document_types ?? []}
                      canEdit={canEdit}
                      onChanged={(attachments) => setEquipment((current) => (current ? { ...current, attachments } : current))}
                    />
                  )}
                </div>
              </TabPanel>
            ))}
            {canEdit && (
              <div className="sticky bottom-0 z-10 mt-4 flex flex-col-reverse gap-2 rounded-t-lg border-t border-gray-200 bg-white/95 px-4 py-3 shadow-[0_-4px_12px_-8px_rgb(0_0_0/0.15)] backdrop-blur sm:flex-row sm:justify-end">
                <Button variant="outline" onClick={() => navigate('/equipment')}>
                  Cancel
                </Button>
                <Button type="submit" isLoading={saving} disabled={!options}>
                  Save changes
                </Button>
              </div>
            )}
          </form>
        )}

        {showBundle && (
          <TabPanel tabsId={TABS_ID} value={PERIPHERALS_TAB} active={tab === PERIPHERALS_TAB}>
            <BundledPeripherals
              equipment={equipment}
              options={options?.form ?? null}
              canLink={canEdit && canRecord}
              canAdd={canEdit && canCreate && canRecord}
              canUnlink={canEdit}
              onChanged={handlePeripheralsChanged}
            />
          </TabPanel>
        )}

        <TabPanel tabsId={TABS_ID} value="history" active={tab === 'history'}>
          <TransactionLog
            equipmentId={equipment.id}
            transactions={equipment.transactions ?? []}
            activities={equipment.activities ?? []}
            options={options?.transaction ?? null}
            personnelOptions={options?.personnel ?? []}
            canRecord={canRecord && !isPeripheral}
            onRecorded={handleRecorded}
          />
        </TabPanel>
      </div>

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

function BundleLink({ bundle }: { bundle: EquipmentParentSummary }) {
  return (
    <Link to={`/equipment/${bundle.id}/edit`} className="font-medium underline hover:no-underline">
      {bundle.property_no ?? 'pending property no.'}
    </Link>
  )
}

function BundleMembership({ isPeripheral, current, original }: { isPeripheral: boolean; current: EquipmentParentSummary | null; original: EquipmentParentSummary | null }) {
  if (!isPeripheral) {
    return original ? (
      <>
        Original bundle: desktop <BundleLink bundle={original} />. It is not linked to a desktop now.
      </>
    ) : null
  }
  const moved = original !== null && original.id !== current?.id
  return (
    <>
      {moved ? 'Currently with desktop' : 'Part of desktop'} {current ? <BundleLink bundle={current} /> : 'record'}. Issuance, transfer and return follow the desktop.
      {moved && (
        <>
          {' '}
          Original bundle: <BundleLink bundle={original} />.
        </>
      )}
    </>
  )
}

const PLURALS: Partial<Record<string, string>> = { Monitor: 'Monitors', Keyboard: 'Keyboards', Mouse: 'Mice' }

function bundleSummary(children: EquipmentPeripheral[]): string {
  const counts = new Map<string, number>()
  for (const child of children) {
    const name = child.item ?? 'peripheral(s) outside your access'
    counts.set(name, (counts.get(name) ?? 0) + 1)
  }
  return [...counts].map(([name, count]) => `${count} ${count === 1 || !PLURALS[name] ? name : PLURALS[name]}`).join(', ')
}

function Overview({ equipment, displayPropertyNo, showBundle }: { equipment: Equipment; displayPropertyNo: string; showBundle: boolean }) {
  const children = equipment.children ?? []
  return (
    <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
      <QrCodeCard equipmentId={equipment.id} propertyNo={equipment.property_no} displayPropertyNo={displayPropertyNo} />
      <Card className="min-w-0 lg:col-span-2">
        <DetailSection icon={<UserRound className="h-3.5 w-3.5" />} title="Current accountability">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <SummaryItem label="Accountable officer" value={equipment.current_accountable_officer?.full_name} />
            <SummaryItem label="End user" value={equipment.current_end_user?.full_name} />
          </dl>
        </DetailSection>
        {showBundle && (
          <DetailSection
            icon={<Unplug className="h-3.5 w-3.5" />}
            title="Bundle"
            action={
              <Link to={{ search: `?tab=${PERIPHERALS_TAB}` }} replace className="text-sm font-medium text-primary-700 hover:underline focus-visible:underline">
                {children.length > 0 ? 'View peripherals' : 'Manage peripherals'}
              </Link>
            }
          >
            <p className="text-sm font-medium text-gray-900">{children.length > 0 ? bundleSummary(children) : 'No peripherals linked yet.'}</p>
          </DetailSection>
        )}
        <DetailSection icon={<Tag className="h-3.5 w-3.5" />} title="Status">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <SummaryItem label="Condition" value={equipment.equipment_condition} />
            <SummaryItem label="Disposition" value={equipment.disposition_status} />
          </dl>
        </DetailSection>
        <DetailSection icon={<Info className="h-3.5 w-3.5" />} title="Key facts" last>
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <SummaryItem label="Item" value={equipment.item} />
            <SummaryItem label="Brand / model" value={[equipment.brand_manufacturer, equipment.model].filter(Boolean).join(' — ') || null} />
            <SummaryItem label="Serial number" value={equipment.serial_number} />
            <SummaryItem label="Received date" value={formatDate(equipment.received_date)} />
            <SummaryItem label="Acquisition cost" value={equipment.acquisition_cost !== null ? currency.format(equipment.acquisition_cost) : null} />
            <SummaryItem label="Category" value={equipment.category} />
            <SummaryItem label="Location" value={equipment.equipment_location} />
            <SummaryItem label="Used by" value={equipment.used_by} />
            <SummaryItem label="Warranty" value={<WarrantyBadge equipment={equipment} />} />
          </dl>
        </DetailSection>
      </Card>
    </div>
  )
}

function WarrantyBadge({ equipment }: { equipment: Equipment }) {
  if (equipment.warranty_active) {
    return <Badge color="success">Under warranty until {formatDate(equipment.end_warranty_date)}</Badge>
  }
  if (equipment.warranty_active === false) return <Badge color="gray">Warranty expired</Badge>
  if (equipment.under_warranty) return <Badge color="success">Under warranty</Badge>
  return <>—</>
}

function SummaryItem({ label, value }: { label: string; value: ReactNode }) {
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
