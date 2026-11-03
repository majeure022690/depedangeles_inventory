import { ArrowRight, Filter, Plus, RotateCcw, ScanLine } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterSearch } from '@/components/ui/FilterControls'
import { Select } from '@/components/ui/Select'
import { CountProgressBar } from '@/components/inventory-counts/CountProgressBar'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { formatDate } from '@/lib/utils'
import { inventoryCountService } from '@/services/inventoryCountService'
import { STATUS_COLORS, STATUS_LABELS, type InventoryCount, type InventoryCountStatus } from '@/types/inventory-count'

const column = gridColumnHelper<InventoryCount>()

const EMPTY_FILTERS = { search: '', status: '' }

const STATUS_OPTIONS = (Object.keys(STATUS_LABELS) as InventoryCountStatus[]).map((status) => ({ value: status, label: STATUS_LABELS[status] }))

export function InventoryCountList() {
  usePageTitle('Inventory Count')
  const navigate = useNavigate()
  const { can } = useAuth()
  const canManage = can('inventory_count.manage')
  const canConduct = can('inventory_count.conduct')

  const [draft, setDraft] = useState(EMPTY_FILTERS)
  const [applied, setApplied] = useState(EMPTY_FILTERS)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange } = useServerTable(
    (params) => inventoryCountService.list(params),
    { filters: applied, waitForPerPage: true },
  )

  const columns = useMemo<GridColumn<InventoryCount>[]>(
    () => [
      column.accessor('name', {
        header: 'Name',
        enableHiding: false,
        cell: (info) => <span className="font-medium text-gray-900">{info.getValue()}</span>,
      }),
      column.accessor('as_of_date', {
        header: 'As of',
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => formatDate(info.getValue()),
      }),
      column.display({
        id: 'offices',
        header: 'Offices',
        cell: ({ row }) => row.original.office_ids.length,
      }),
      column.display({
        id: 'status',
        header: 'Status',
        cell: ({ row }) => <Badge color={STATUS_COLORS[row.original.status]}>{STATUS_LABELS[row.original.status]}</Badge>,
      }),
      column.display({
        id: 'progress',
        header: 'Progress',
        cell: ({ row }) =>
          row.original.status === 'draft' ? (
            <span className="text-xs text-gray-500">Not opened yet</span>
          ) : (
            <CountProgressBar counted={row.original.counted_count ?? 0} total={row.original.items_count ?? 0} />
          ),
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1">
            {canConduct && row.original.status === 'open' && (
              <Button
                variant="outline"
                size="sm"
                leftIcon={<ScanLine className="h-4 w-4" />}
                aria-label={`Count items for ${row.original.name}`}
                onClick={(event) => {
                  event.stopPropagation()
                  navigate(`/inventory-counts/${row.original.id}/count`)
                }}
              >
                Count
              </Button>
            )}
            <Button
              variant="ghost"
              size="sm"
              aria-label={`Open ${row.original.name}`}
              title="Details"
              onClick={(event) => {
                event.stopPropagation()
                navigate(`/inventory-counts/${row.original.id}`)
              }}
            >
              <ArrowRight className="h-4 w-4 text-primary-600" />
            </Button>
          </div>
        ),
      }),
    ],
    [canConduct, navigate],
  )

  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory Count</h1>
          <p className="text-sm text-gray-600">Physical counts of equipment by the Inventory Committee, compared against the Equipment records.</p>
        </div>
        {canManage && (
          <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/inventory-counts/new')}>
            New count
          </Button>
        )}
      </div>

      <form
        className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        role="search"
        aria-label="Filter inventory counts"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied(draft)
        }}
      >
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label="Search by name..."
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={() => setApplied(draft)}
            className="sm:col-span-2 lg:col-span-8"
          />
          <Select
            aria-label="Status"
            placeholder="All statuses"
            options={STATUS_OPTIONS}
            value={draft.status}
            onChange={(event) => setDraft((d) => ({ ...d, status: event.target.value }))}
            className="lg:col-span-4"
          />
        </div>
        <div className="mt-3 flex justify-end gap-2 border-t border-gray-100 pt-3">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
            className="flex-1 sm:flex-none"
            disabled={!dirty && JSON.stringify(applied) === JSON.stringify(EMPTY_FILTERS)}
            onClick={() => {
              setDraft(EMPTY_FILTERS)
              setApplied(EMPTY_FILTERS)
            }}
          >
            Reset
          </Button>
          <Button type="submit" size="sm" leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
            Apply Filters
          </Button>
        </div>
      </form>

      <DataGrid
        id="inventory-counts"
        columns={columns}
        rows={items}
        rowKey={(row) => row.id}
        loading={loading}
        error={error}
        meta={meta}
        page={page}
        onPageChange={setPage}
        perPage={perPage}
        onPerPageChange={setPerPage}
        sortBy={sortBy}
        sortDir={sortDir}
        onSortChange={onSortChange}
        emptyMessage="No inventory counts yet."
        onRowClick={(row) => navigate(`/inventory-counts/${row.id}`)}
      />
    </div>
  )
}
