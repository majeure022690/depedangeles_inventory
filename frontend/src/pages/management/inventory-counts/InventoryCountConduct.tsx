import { ArrowLeft, Camera, ChevronRight, PackagePlus, Search } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { CountItemDialog } from '@/components/inventory-counts/CountItemDialog'
import { CountProgressBar } from '@/components/inventory-counts/CountProgressBar'
import { FoundItemDialog, type FoundItemPrefill } from '@/components/inventory-counts/FoundItemDialog'
import { QrScanDialog, useHasCamera } from '@/components/inventory-counts/QrScanDialog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Pagination } from '@/components/ui/Pagination'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError, extractLoadError } from '@/lib/errors'
import { inventoryCountService } from '@/services/inventoryCountService'
import { BUCKET_COLORS, BUCKET_LABELS, type InventoryCount, type InventoryCountItem, type InventoryCountOptions } from '@/types/inventory-count'

type Notice = { type: 'success' | 'error' | 'warning'; message: string; foundAtStation?: FoundItemPrefill } | null

export function InventoryCountConduct() {
  const id = Number(useParams().id)
  const hasCamera = useHasCamera()

  const [count, setCount] = useState<InventoryCount | null>(null)
  const [options, setOptions] = useState<InventoryCountOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [officeId, setOfficeId] = useState('')
  const [show, setShow] = useState<'pending' | ''>('pending')
  const [code, setCode] = useState('')
  const [looking, setLooking] = useState(false)
  const [scanning, setScanning] = useState(false)
  const [notice, setNotice] = useState<Notice>(null)
  const [selected, setSelected] = useState<InventoryCountItem | null>(null)
  const [foundPrefill, setFoundPrefill] = useState<FoundItemPrefill | null>(null)

  usePageTitle(count ? `Count · ${count.name}` : 'Count items')

  const refreshCount = useCallback(() => inventoryCountService.show(id).then(setCount), [id])

  useEffect(() => {
    inventoryCountService
      .show(id)
      .then(async (loaded) => {
        setCount(loaded)
        setOfficeId((current) => current || String(loaded.offices?.[0]?.id ?? ''))
        setOptions(await inventoryCountService.options(loaded.office_ids))
      })
      .catch((error: unknown) => setLoadError(extractLoadError(error, 'Unable to load the count. Please refresh the page.')))
  }, [id])

  const list = useServerTable((params) => inventoryCountService.items(id, params), {
    filters: { office_id: officeId, bucket: show },
    perPage: 20,
  })

  const officeName = count?.offices?.find((office) => String(office.id) === officeId)?.office_name ?? ''
  const progress = count?.summary?.offices.find((office) => String(office.office_id) === officeId)

  async function lookup(value: string) {
    const trimmed = value.trim()
    if (trimmed === '') return
    setLooking(true)
    setNotice(null)
    try {
      const response = await inventoryCountService.lookup(id, trimmed)
      const { item, equipment } = response.data
      if (item) {
        if (item.office && String(item.office.id) !== officeId) {
          setOfficeId(String(item.office.id))
          setNotice({ type: 'warning', message: `This item is listed under ${item.office.office_name}. Switched to that office.` })
        }
        setSelected(item)
        setCode('')
      } else if (equipment) {
        setNotice({
          type: 'warning',
          message: `${equipment.property_no ?? 'This item'} is in IRIS but not on this count's list for ${officeName}.`,
          foundAtStation: { equipment, code: trimmed },
        })
      } else {
        setNotice({ type: 'error', message: 'No matching equipment found.', foundAtStation: { equipment: null, code: trimmed } })
      }
    } catch (lookupError) {
      setNotice({ type: 'error', message: extractError(lookupError, 'Unable to look up the item.') })
    } finally {
      setLooking(false)
    }
  }

  function onSearch(event: FormEvent) {
    event.preventDefault()
    void lookup(code)
  }

  async function saveItem(item: InventoryCountItem, update: Parameters<typeof inventoryCountService.updateItem>[2]) {
    const saved = await inventoryCountService.updateItem(id, item.id, update)
    setSelected(null)
    setNotice({ type: 'success', message: `${saved.equipment.property_no ?? 'Item'}: ${BUCKET_LABELS[saved.bucket]}.` })
    await Promise.all([list.reload(), refreshCount()])
  }

  if (loadError) {
    return (
      <Alert type="error">
        <span role="alert">{loadError}</span>
      </Alert>
    )
  }

  if (!count || !options) return <DetailPageSkeleton />

  if (count.status !== 'open') {
    return (
      <div className="space-y-4">
        <Alert type="info">
          <span role="status">This count is {count.status === 'draft' ? 'not open yet' : count.status}. Items can only be marked while it is open.</span>
        </Alert>
        <Link to={`/inventory-counts/${id}`} className="text-sm font-medium text-primary-700 hover:underline">
          Back to the count
        </Link>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <Link to={`/inventory-counts/${id}`} className="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary-700 hover:underline">
          <ArrowLeft className="h-4 w-4" /> {count.name}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">Count items</h1>
      </div>

      <div className="space-y-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm sm:p-4">
        <Select
          aria-label="Office"
          value={officeId}
          onChange={(event) => {
            setOfficeId(event.target.value)
            setNotice(null)
          }}
          options={(count.offices ?? []).map((office) => ({ value: String(office.id), label: office.office_name }))}
        />
        {progress && <CountProgressBar counted={progress.total - progress.pending} total={progress.total} />}

        <form onSubmit={onSearch} className="flex gap-2" role="search" aria-label="Find an item">
          <Input
            aria-label="Property number, serial number or QR code"
            placeholder="Property no. or serial no."
            value={code}
            onChange={(event) => setCode(event.target.value)}
            className="flex-1"
            inputMode="search"
            autoComplete="off"
          />
          <Button type="submit" size="lg" className="min-h-11" isLoading={looking} aria-label="Find">
            <Search className="h-5 w-5" />
          </Button>
          {hasCamera && (
            <Button variant="outline" size="lg" className="min-h-11" aria-label="Scan QR label" onClick={() => setScanning(true)}>
              <Camera className="h-5 w-5" />
            </Button>
          )}
        </form>

        {notice && (
          <Alert type={notice.type}>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <span role={notice.type === 'error' ? 'alert' : 'status'}>{notice.message}</span>
              {notice.foundAtStation && (
                <Button size="sm" variant="outline" leftIcon={<PackagePlus className="h-4 w-4" />} onClick={() => setFoundPrefill(notice.foundAtStation ?? null)}>
                  Record as found at station
                </Button>
              )}
            </div>
          </Alert>
        )}
      </div>

      <div className="flex items-center justify-between gap-2">
        <div className="inline-flex rounded-md border border-gray-200 bg-white p-0.5" role="group" aria-label="Show">
          {(
            [
              ['pending', 'Not yet counted'],
              ['', 'All items'],
            ] as const
          ).map(([value, label]) => (
            <button
              key={label}
              type="button"
              aria-pressed={show === value}
              onClick={() => setShow(value)}
              className={`min-h-9 rounded px-3 text-sm font-medium ${show === value ? 'bg-primary-600 text-white' : 'text-gray-700 hover:bg-gray-50'}`}
            >
              {label}
            </button>
          ))}
        </div>
        <Button
          variant="ghost"
          size="sm"
          leftIcon={<PackagePlus className="h-4 w-4" />}
          onClick={() => setFoundPrefill({ equipment: null, code: '' })}
          disabled={officeId === ''}
        >
          Item with no record
        </Button>
      </div>

      {list.error && (
        <Alert type="error">
          <span role="alert">{list.error}</span>
        </Alert>
      )}

      {list.loading && list.items.length === 0 ? (
        <div className="space-y-2">
          {[0, 1, 2].map((key) => (
            <Skeleton key={key} className="h-16 w-full" />
          ))}
        </div>
      ) : list.items.length === 0 ? (
        <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
          {show === 'pending' ? 'Every item at this office has been counted.' : 'No items listed for this office.'}
        </p>
      ) : (
        <ul className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white" aria-label={`Items at ${officeName}`}>
          {list.items.map((item) => (
            <li key={item.id}>
              <button
                type="button"
                onClick={() => setSelected(item)}
                className="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 focus-visible:bg-primary-50 focus-visible:outline-none"
              >
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-gray-900">{item.equipment.property_no ?? 'No property no.'}</p>
                  <p className="truncate text-sm text-gray-600">{item.equipment.description}</p>
                  <p className="truncate text-xs text-gray-500">{item.recorded.accountable_officer ?? 'No accountable officer'}</p>
                </div>
                <Badge color={BUCKET_COLORS[item.bucket]}>{BUCKET_LABELS[item.bucket]}</Badge>
                <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {list.meta && list.meta.last_page > 1 && <Pagination page={list.page} lastPage={list.meta.last_page} onPageChange={list.setPage} />}

      <CountItemDialog item={selected} conditions={options.condition} people={options.people} onClose={() => setSelected(null)} save={saveItem} />

      <FoundItemDialog
        prefill={foundPrefill}
        officeId={officeId === '' ? null : Number(officeId)}
        officeName={officeName}
        conditions={options.condition}
        onClose={() => setFoundPrefill(null)}
        save={async (input) => {
          await inventoryCountService.addFoundItem(id, input)
          setFoundPrefill(null)
          setNotice({ type: 'success', message: 'Recorded as found at station.' })
          await refreshCount()
        }}
      />

      <QrScanDialog
        open={scanning}
        onOpenChange={setScanning}
        onScan={(scanned) => {
          setScanning(false)
          void lookup(scanned)
        }}
      />
    </div>
  )
}
