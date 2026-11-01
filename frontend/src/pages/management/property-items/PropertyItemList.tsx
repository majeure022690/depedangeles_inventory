import { ArrowRight, Download, FileSpreadsheet, Filter, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { RpcppeDownloadDialog } from '@/components/property-items/RpcppeDownloadDialog'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterChips, FilterSearch, FilterSelect, type FilterChip } from '@/components/ui/FilterControls'
import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { hasAllScope } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { propertyItemService } from '@/services/propertyItemService'
import type { PropertyCategory, PropertyItem, PropertyItemFilters, PropertyItemOptions } from '@/types/property-item'

const column = gridColumnHelper<PropertyItem>()

const currency = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
})

/** One tab per RPCPPE sheet, in workbook order. */
const TABS: { value: PropertyCategory; label: string }[] = [
  { value: 'it_equipment', label: 'IT Equipment' },
  { value: 'office_equipment', label: 'Office Equipment' },
  { value: 'furniture_fixtures', label: 'Furniture & Fixtures' },
  { value: 'school_building', label: 'School Buildings' },
  { value: 'other_structure', label: 'Other Structures' },
  { value: 'tle_equipment', label: 'TLE' },
  { value: 'science_math_equipment', label: 'Science & Math' },
  { value: 'sports_music_equipment', label: 'Sports & Music' },
  { value: 'books', label: 'Books' },
]

const TABS_ID = 'school-property'

const EMPTY_FILTERS = { search: '', office_id: '' }

type Filters = typeof EMPTY_FILTERS
type FilterKey = keyof Filters
type Feedback = { type: 'success' | 'error'; message: string } | null

export function PropertyItemList() {
  usePageTitle('School Property')
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { can, user } = useAuth()
  const tab = TABS.find((item) => item.value === searchParams.get('tab'))?.value ?? TABS[0].value
  const tabLabel = TABS.find((item) => item.value === tab)?.label ?? ''
  const [rpcppeOpen, setRpcppeOpen] = useState(false)
  const canCreate = can('property_items.create')
  const canEdit = can('property_items.edit')
  const canDelete = can('property_items.delete')
  const showOffice = hasAllScope(user)

  const [options, setOptions] = useState<PropertyItemOptions | null>(null)
  useEffect(() => {
    propertyItemService
      .options()
      .then(setOptions)
      .catch(() => setOptions(null))
  }, [])

  const [draft, setDraft] = useState<Filters>(EMPTY_FILTERS)
  const [applied, setApplied] = useState<Filters>(EMPTY_FILTERS)
  const query: PropertyItemFilters = useMemo(() => ({ ...applied, category: tab }), [applied, tab])
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => propertyItemService.list(params),
    {
      filters: query,
      waitForPerPage: true,
    },
  )

  const [feedback, setFeedback] = useState<Feedback>(null)
  const [pendingDelete, setPendingDelete] = useState<PropertyItem | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [exporting, setExporting] = useState(false)

  const officeLabel = (value: string) => options?.office.find((option) => String(option.value) === value)?.label ?? 'Office'

  const chips: FilterChip<FilterKey>[] = (
    [
      { key: 'search', label: `Search: ${applied.search}` },
      { key: 'office_id', label: officeLabel(applied.office_id) },
    ] as const
  )
    .filter((chip) => applied[chip.key] !== '')
    .map((chip) => ({ ...chip, removable: true }))

  function removeFilter(key: FilterKey) {
    const next = { ...applied, [key]: '' }
    setApplied(next)
    setDraft(next)
  }

  function resetFilters() {
    setDraft(EMPTY_FILTERS)
    setApplied(EMPTY_FILTERS)
  }

  async function exportList() {
    setExporting(true)
    setFeedback(null)
    try {
      await propertyItemService.export(query)
    } catch (exportError) {
      setFeedback({
        type: 'error',
        message: extractError(exportError, 'Unable to export the list. Please try again.'),
      })
    } finally {
      setExporting(false)
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await propertyItemService.remove(pendingDelete.id)
      setFeedback({ type: 'success', message: 'Property item deleted.' })
      setPendingDelete(null)
      await reload()
    } catch (deleteError) {
      setFeedback({
        type: 'error',
        message: extractError(deleteError, 'Unable to delete the property item. Please try again.'),
      })
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  const columns = useMemo<GridColumn<PropertyItem>[]>(
    () => [
      column.accessor('article', {
        header: 'Article',
        enableHiding: false,
        cell: ({ row }) => (
          <div>
            <span className="font-medium text-gray-900">{row.original.article}</span>
            {row.original.description && <p className="text-xs text-gray-500">{row.original.description}</p>}
          </div>
        ),
      }),
      ...(showOffice
        ? [
            column.display({
              id: 'office',
              header: 'Office',
              cell: ({ row }) => row.original.office?.office_name ?? <span className="text-gray-400">—</span>,
            }),
          ]
        : []),
      column.accessor('property_no', {
        header: 'Property no.',
        cell: (info) => (info.getValue() ? <span className="font-mono text-xs">{info.getValue()}</span> : <span className="text-gray-400">—</span>),
      }),
      column.accessor('quantity', {
        header: 'Qty (card)',
        meta: { align: 'right', className: 'tabular-nums' },
      }),
      column.accessor('on_hand_count', {
        header: 'On hand',
        enableSorting: false,
        meta: { align: 'right', className: 'tabular-nums' },
        cell: ({ row }) => (
          <span className={row.original.on_hand_count < row.original.quantity ? 'font-medium text-error-600' : undefined}>{row.original.on_hand_count}</span>
        ),
      }),
      column.accessor('unit_value', {
        header: 'Unit value',
        meta: { align: 'right', className: 'whitespace-nowrap tabular-nums' },
        cell: (info) => (info.getValue() === null ? '—' : currency.format(info.getValue() as number)),
      }),
      column.accessor('total_value', {
        header: 'Total value',
        enableSorting: false,
        meta: {
          align: 'right',
          className: 'whitespace-nowrap tabular-nums',
          defaultHidden: true,
        },
        cell: (info) => (info.getValue() === null ? '—' : currency.format(info.getValue() as number)),
      }),
      column.accessor('end_user', {
        header: 'End user / teacher',
        enableSorting: false,
        cell: (info) => info.getValue() ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('grade_level', {
        header: 'Grade level',
        enableSorting: false,
        meta: {
          defaultHidden: tab !== 'books' && tab !== 'furniture_fixtures',
        },
      }),
      column.accessor('condition', {
        header: 'Condition',
        enableSorting: false,
        cell: (info) =>
          info.getValue() ? (
            <Badge color={info.getValue() === 'functional' ? 'success' : 'error'}>{info.getValue() === 'functional' ? 'Functional' : 'Unserviceable'}</Badge>
          ) : (
            <span className="text-gray-400">—</span>
          ),
      }),
      column.accessor('date_acquired', {
        header: 'Acquired',
        meta: { defaultHidden: true, className: 'whitespace-nowrap' },
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1">
            {canDelete && (
              <Button
                variant="ghost"
                size="sm"
                aria-label={`Delete ${row.original.article}`}
                title="Delete"
                onClick={(event) => {
                  event.stopPropagation()
                  setPendingDelete(row.original)
                }}
              >
                <Trash2 className="h-4 w-4 text-error-600" />
              </Button>
            )}
            <Button
              variant="ghost"
              size="sm"
              aria-label={`${canEdit ? 'Edit' : 'View'} ${row.original.article}`}
              title={canEdit ? 'Edit' : 'View'}
              onClick={(event) => {
                event.stopPropagation()
                navigate(`/school-property/${row.original.id}/edit`)
              }}
            >
              <ArrowRight className="h-4 w-4 text-primary-600" />
            </Button>
          </div>
        ),
      }),
    ],
    [canDelete, canEdit, navigate, showOffice, tab],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">School Property</h1>
          <p className="text-sm text-gray-600">One tab per RPCPPE sheet. IRIS equipment is added to the IT and Office sheets of the report automatically.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" leftIcon={<Download className="h-4 w-4" />} isLoading={exporting} onClick={exportList}>
            Export this tab
          </Button>
          {can('reports.rpcppe') && (
            <Button variant="outline" size="sm" leftIcon={<FileSpreadsheet className="h-4 w-4 text-green-600" />} onClick={() => setRpcppeOpen(true)}>
              Download RPCPPE
            </Button>
          )}
          {canCreate && (
            <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate(`/school-property/new?category=${tab}`)}>
              Add {tabLabel}
            </Button>
          )}
        </div>
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}

      <form
        className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        role="search"
        aria-label="Filter school property"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied(draft)
        }}
      >
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label={tab === 'books' ? 'Search by learning area, title or property no...' : 'Search by article, description or property no...'}
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={() => setApplied(draft)}
            className={showOffice ? 'sm:col-span-2 lg:col-span-8' : 'sm:col-span-2 lg:col-span-12'}
          />
          {showOffice && (
            <FilterSelect
              label="Office"
              value={draft.office_id}
              onChange={(officeId) => setDraft((d) => ({ ...d, office_id: officeId }))}
              className="lg:col-span-4"
            >
              <option value="">All offices</option>
              {options?.office.map((office) => (
                <option key={office.value} value={office.value}>
                  {office.label}
                </option>
              ))}
            </FilterSelect>
          )}
        </div>

        <div className="mt-3 flex flex-col-reverse gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:items-center sm:justify-between">
          <FilterChips chips={chips} onRemove={removeFilter} />
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
              className="flex-1 sm:flex-none"
              disabled={!dirty && chips.length === 0}
              onClick={resetFilters}
            >
              Reset
            </Button>
            <Button type="submit" size="sm" leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
              Apply Filters
            </Button>
          </div>
        </div>
      </form>

      <Tabs
        id={TABS_ID}
        label="RPCPPE sheets"
        tabs={TABS}
        value={tab}
        onChange={(next) =>
          setSearchParams(
            (prev) => {
              const params = new URLSearchParams(prev)
              params.set('tab', next)
              return params
            },
            { replace: true },
          )
        }
        className="mb-4"
      />
      <TabPanel tabsId={TABS_ID} value={tab} active>
        <DataGrid
          id="school-property"
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
          emptyMessage={`No ${tabLabel} recorded yet.`}
          onRowClick={(row) => navigate(`/school-property/${row.id}/edit`)}
        />
      </TabPanel>

      <RpcppeDownloadDialog open={rpcppeOpen} onOpenChange={setRpcppeOpen} />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete property item?"
        description={
          pendingDelete
            ? `“${pendingDelete.article}” (${pendingDelete.quantity} ${pendingDelete.unit_of_measure ?? 'item(s)'}) will be removed from the list and from future reports.`
            : ''
        }
        confirmText="Delete"
        confirmVariant="danger"
      />
    </div>
  )
}
