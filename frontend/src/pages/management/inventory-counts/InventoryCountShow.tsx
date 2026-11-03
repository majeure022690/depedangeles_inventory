import { Ban, CheckCircle2, Pencil, PlayCircle, ScanLine, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { CountProgressBar } from '@/components/inventory-counts/CountProgressBar'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { Select } from '@/components/ui/Select'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError, extractLoadError } from '@/lib/errors'
import { cn, formatDate, formatDateTime } from '@/lib/utils'
import { inventoryCountService } from '@/services/inventoryCountService'
import {
  BUCKET_COLORS,
  BUCKET_LABELS,
  STATUS_COLORS,
  STATUS_LABELS,
  type CountBucket,
  type InventoryCount,
  type InventoryCountFoundItem,
  type InventoryCountItem,
} from '@/types/inventory-count'

const itemColumn = gridColumnHelper<InventoryCountItem>()
const foundColumn = gridColumnHelper<InventoryCountFoundItem>()

const BUCKET_ORDER: CountBucket[] = ['not_found', 'condition_changed', 'holder_changed', 'pending', 'matched']

type Action = 'open' | 'close' | 'cancel' | 'delete'

const ACTION_COPY: Record<Action, { title: string; description: string; confirm: string; danger: boolean }> = {
  open: {
    title: 'Open this count?',
    description: 'IRIS will list every item currently held at the selected offices, as the Equipment records stand now. The offices and as-of date can no longer be changed.',
    confirm: 'Open count',
    danger: false,
  },
  close: {
    title: 'Close this count?',
    description: 'Counters can no longer mark items. Items not yet counted stay as "Not yet counted".',
    confirm: 'Close count',
    danger: false,
  },
  cancel: { title: 'Cancel this count?', description: 'The count stops and its results are kept for the record.', confirm: 'Cancel count', danger: true },
  delete: { title: 'Delete this draft?', description: 'The draft count will be deleted.', confirm: 'Delete', danger: true },
}

function Change({ before, after, changed }: { before: string | null; after: string | null; changed: boolean }) {
  if (!changed) return <span>{after ?? before ?? '—'}</span>
  return (
    <span>
      <span className="text-gray-500 line-through">{before ?? '—'}</span> <span className="font-medium text-warning-700">→ {after ?? '—'}</span>
    </span>
  )
}

export function InventoryCountShow() {
  const id = Number(useParams().id)
  const navigate = useNavigate()
  const location = useLocation()
  const { can } = useAuth()
  const canManage = can('inventory_count.manage')
  const canConduct = can('inventory_count.conduct')

  const [count, setCount] = useState<InventoryCount | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(() => {
    const flash = (location.state as { flash?: string } | null)?.flash
    return flash ? { type: 'success', message: flash } : null
  })
  const [pending, setPending] = useState<Action | null>(null)
  const [working, setWorking] = useState(false)
  const [filters, setFilters] = useState<{ bucket: string; office_id: string }>({ bucket: 'discrepancies', office_id: '' })

  usePageTitle(count?.name ?? 'Inventory count')

  const load = useCallback(
    () =>
      inventoryCountService
        .show(id)
        .then(setCount)
        .catch((error: unknown) => setLoadError(extractLoadError(error, 'Unable to load the count. Please refresh the page.'))),
    [id],
  )

  useEffect(() => {
    void load()
  }, [load])

  const opened = count !== null && count.status !== 'draft'

  const items = useServerTable((params) => inventoryCountService.items(id, params), { filters, waitForPerPage: true })
  const found = useServerTable((params) => inventoryCountService.foundItems(id, params), { filters: { office_id: filters.office_id }, perPage: 10 })

  async function confirmAction() {
    if (!pending) return
    setWorking(true)
    try {
      if (pending === 'delete') {
        await inventoryCountService.remove(id)
        navigate('/inventory-counts')
        return
      }
      const response = await inventoryCountService[pending](id)
      setCount(response.data)
      setFeedback({ type: 'success', message: response.message })
      await items.reload()
    } catch (actionError) {
      setFeedback({ type: 'error', message: extractError(actionError, 'Unable to complete the action. Please try again.') })
    } finally {
      setWorking(false)
      setPending(null)
    }
  }

  const officeName = (officeId: number) => count?.offices?.find((office) => office.id === officeId)?.office_name ?? `Office #${officeId}`

  const itemColumns = useMemo<GridColumn<InventoryCountItem>[]>(
    () => [
      itemColumn.display({
        id: 'item',
        header: 'Item',
        enableHiding: false,
        cell: ({ row }) => (
          <div>
            <span className="font-medium text-gray-900">{row.original.equipment.property_no ?? 'No property no.'}</span>
            <p className="text-xs text-gray-500">{row.original.equipment.description}</p>
          </div>
        ),
      }),
      itemColumn.display({
        id: 'office',
        header: 'Office',
        cell: ({ row }) => row.original.office?.office_name ?? '—',
      }),
      itemColumn.display({
        id: 'result',
        header: 'Result',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1">
            <Badge color={BUCKET_COLORS[row.original.bucket]}>{BUCKET_LABELS[row.original.bucket]}</Badge>
            {row.original.unserviceable && <Badge color="error">Unserviceable</Badge>}
          </div>
        ),
      }),
      itemColumn.display({
        id: 'condition',
        header: 'Condition',
        cell: ({ row }) => <Change before={row.original.recorded.condition} after={row.original.found.condition} changed={row.original.condition_changed} />,
      }),
      itemColumn.display({
        id: 'holder',
        header: 'Accountable / location',
        cell: ({ row }) => (
          <div className="space-y-0.5">
            <Change
              before={row.original.recorded.accountable_officer}
              after={row.original.found.holder}
              changed={row.original.holder_changed && row.original.found.holder_id !== row.original.recorded.accountable_officer_id}
            />
            {(row.original.recorded.location || row.original.found.location) && (
              <p className="text-xs">
                <Change
                  before={row.original.recorded.location}
                  after={row.original.found.location}
                  changed={row.original.holder_changed && (row.original.found.location ?? '').trim() !== (row.original.recorded.location ?? '').trim()}
                />
              </p>
            )}
          </div>
        ),
      }),
      itemColumn.display({
        id: 'counted',
        header: 'Counted',
        cell: ({ row }) =>
          row.original.counted_at ? (
            <div className="text-xs text-gray-600">
              {row.original.counted_by}
              <p>{formatDateTime(row.original.counted_at)}</p>
              {row.original.remarks && <p className="text-gray-500 italic">{row.original.remarks}</p>}
            </div>
          ) : (
            <span className="text-gray-400">—</span>
          ),
      }),
    ],
    [],
  )

  const foundColumns = useMemo<GridColumn<InventoryCountFoundItem>[]>(
    () => [
      foundColumn.display({
        id: 'item',
        header: 'Item',
        enableHiding: false,
        cell: ({ row }) => (
          <div>
            <span className="font-medium text-gray-900">{row.original.equipment?.property_no ?? row.original.property_no ?? 'No property no.'}</span>
            <p className="text-xs text-gray-500">{row.original.equipment?.description ?? row.original.description}</p>
            {row.original.equipment && <p className="text-xs text-warning-700">In IRIS under another office</p>}
          </div>
        ),
      }),
      foundColumn.display({ id: 'office', header: 'Found at', cell: ({ row }) => row.original.office?.office_name ?? '—' }),
      foundColumn.display({ id: 'condition', header: 'Condition', cell: ({ row }) => row.original.condition ?? '—' }),
      foundColumn.display({
        id: 'counted',
        header: 'Counted',
        cell: ({ row }) => (
          <div className="text-xs text-gray-600">
            {row.original.counted_by}
            <p>{formatDateTime(row.original.counted_at)}</p>
            {row.original.remarks && <p className="text-gray-500 italic">{row.original.remarks}</p>}
          </div>
        ),
      }),
    ],
    [],
  )

  if (loadError) {
    return (
      <Alert type="error">
        <span role="alert">{loadError}</span>
      </Alert>
    )
  }

  if (!count) return <DetailPageSkeleton />

  const summary = count.summary
  const totalItems = summary ? summary.offices.reduce((sum, office) => sum + office.total, 0) : 0
  const totalCounted = summary ? summary.offices.reduce((sum, office) => sum + office.total - office.pending, 0) : 0
  const asOf = new Date(`${count.as_of_date}T00:00:00`)
  const reportDue = asOf.getMonth() === 11 && asOf.getDate() === 31 ? `January 31, ${asOf.getFullYear() + 1}` : null
  const copy = pending ? ACTION_COPY[pending] : null

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-gray-900">{count.name}</h1>
            <Badge color={STATUS_COLORS[count.status]}>{STATUS_LABELS[count.status]}</Badge>
          </div>
          <p className="text-sm text-gray-600">
            As of {formatDate(count.as_of_date)}
            {reportDue && count.status !== 'cancelled' && <> · RPCPPE due {reportDue}</>}
          </p>
        </div>
        <div className="flex w-full flex-wrap gap-2 sm:w-auto">
          {canConduct && count.status === 'open' && (
            <Button size="sm" className="flex-1 sm:flex-none" leftIcon={<ScanLine className="h-4 w-4" />} onClick={() => navigate(`/inventory-counts/${id}/count`)}>
              Count items
            </Button>
          )}
          {canManage && count.status === 'draft' && (
            <Button size="sm" className="flex-1 sm:flex-none" leftIcon={<PlayCircle className="h-4 w-4" />} onClick={() => setPending('open')}>
              Open count
            </Button>
          )}
          {canManage && (count.status === 'draft' || count.status === 'open') && (
            <Button variant="outline" size="sm" className="flex-1 sm:flex-none" leftIcon={<Pencil className="h-4 w-4" />} onClick={() => navigate(`/inventory-counts/${id}/edit`)}>
              Edit
            </Button>
          )}
          {canManage && count.status === 'open' && (
            <>
              <Button variant="outline" size="sm" className="flex-1 sm:flex-none" leftIcon={<CheckCircle2 className="h-4 w-4" />} onClick={() => setPending('close')}>
                Close
              </Button>
              <Button variant="ghost" size="sm" className="flex-1 sm:flex-none" leftIcon={<Ban className="h-4 w-4 text-error-600" />} onClick={() => setPending('cancel')}>
                Cancel
              </Button>
            </>
          )}
          {canManage && count.status === 'draft' && (
            <Button variant="ghost" size="sm" className="flex-1 sm:flex-none" leftIcon={<Trash2 className="h-4 w-4 text-error-600" />} onClick={() => setPending('delete')}>
              Delete
            </Button>
          )}
        </div>
      </div>

      {feedback && (
        <Alert type={feedback.type}>
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className={count.remarks ? 'lg:col-span-2' : 'lg:col-span-3'}>
          <CardContent className="space-y-3">
            <h2 className="text-base font-semibold text-gray-900">Progress</h2>
            {!opened ? (
              <p className="text-sm text-gray-600">
                Draft. {count.office_ids.length} office{count.office_ids.length === 1 ? '' : 's'} selected. Open the count to build the list of items.
              </p>
            ) : (
              <>
                <CountProgressBar counted={totalCounted} total={totalItems} />
                <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {summary?.offices.map((office) => (
                    <li key={office.office_id} className="rounded-md border border-gray-100 p-3">
                      <p className="truncate text-sm font-medium text-gray-900" title={officeName(office.office_id)}>
                        {officeName(office.office_id)}
                      </p>
                      <CountProgressBar counted={office.total - office.pending} total={office.total} className="mt-1" />
                      <p className="mt-1 text-xs text-gray-500">
                        {office.not_found} not found · {office.found_at_station} found at station
                      </p>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </CardContent>
        </Card>
        {count.remarks && (
          <Card>
            <CardContent className="space-y-2 text-sm">
              <h2 className="text-base font-semibold text-gray-900">Remarks</h2>
              <p className="whitespace-pre-line text-gray-700">{count.remarks}</p>
            </CardContent>
          </Card>
        )}
      </div>

      {opened && summary && (
        <>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" role="group" aria-label="Filter items by result">
            {BUCKET_ORDER.map((bucket) => (
              <button
                key={bucket}
                type="button"
                aria-pressed={filters.bucket === bucket}
                onClick={() => setFilters((prev) => ({ ...prev, bucket: prev.bucket === bucket ? '' : bucket }))}
                className={cn(
                  'rounded-lg border bg-white p-3 text-left shadow-xs transition-colors hover:border-primary-300 focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none',
                  filters.bucket === bucket ? 'border-primary-500 ring-1 ring-primary-500' : 'border-gray-200',
                )}
              >
                <p className="text-2xl font-semibold text-gray-900">{summary.buckets[bucket]}</p>
                <Badge color={BUCKET_COLORS[bucket]}>{BUCKET_LABELS[bucket]}</Badge>
              </button>
            ))}
            <div className="rounded-lg border border-gray-200 bg-white p-3 shadow-xs">
              <p className="text-2xl font-semibold text-gray-900">{summary.found_at_station}</p>
              <Badge color="primary">Found at station</Badge>
            </div>
          </div>

          <section aria-labelledby="count-items-heading" className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
              <h2 id="count-items-heading" className="text-lg font-semibold text-gray-900">
                Items
              </h2>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <Select
                  aria-label="Result"
                  value={filters.bucket}
                  onChange={(event) => setFilters((prev) => ({ ...prev, bucket: event.target.value }))}
                  options={[{ value: 'discrepancies', label: 'Discrepancies only' }, ...BUCKET_ORDER.map((bucket) => ({ value: bucket, label: BUCKET_LABELS[bucket] }))]}
                  placeholder="All results"
                />
                <Select
                  aria-label="Office"
                  value={filters.office_id}
                  onChange={(event) => setFilters((prev) => ({ ...prev, office_id: event.target.value }))}
                  options={(count.offices ?? []).map((office) => ({ value: String(office.id), label: office.office_name }))}
                  placeholder="All offices"
                />
              </div>
            </div>
            <DataGrid
              id="inventory-count-items"
              columns={itemColumns}
              rows={items.items}
              rowKey={(row) => row.id}
              loading={items.loading}
              error={items.error}
              meta={items.meta}
              page={items.page}
              onPageChange={items.setPage}
              perPage={items.perPage}
              onPerPageChange={items.setPerPage}
              emptyMessage={filters.bucket === 'discrepancies' ? 'No discrepancies so far.' : 'No items match.'}
            />
          </section>

          <section aria-labelledby="count-found-heading" className="space-y-3">
            <h2 id="count-found-heading" className="text-lg font-semibold text-gray-900">
              Found at station
            </h2>
            <p className="text-sm text-gray-600">Items found during the count that are not on its list: IRIS records held under another office, or items with no record.</p>
            <DataGrid
              id="inventory-count-found"
              columns={foundColumns}
              rows={found.items}
              rowKey={(row) => row.id}
              loading={found.loading}
              error={found.error}
              meta={found.meta}
              page={found.page}
              onPageChange={found.setPage}
              perPage={found.perPage}
              onPerPageChange={found.setPerPage}
              emptyMessage="Nothing found at station."
            />
          </section>
        </>
      )}

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => !open && !working && setPending(null)}
        onConfirm={confirmAction}
        loading={working}
        title={copy?.title}
        description={copy?.description}
        confirmText={copy?.confirm}
        confirmVariant={copy?.danger ? 'danger' : 'primary'}
      />
    </div>
  )
}
