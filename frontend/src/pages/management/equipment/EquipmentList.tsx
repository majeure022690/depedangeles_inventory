import { ArrowRight, ArrowRightLeft, Filter, PackageCheck, Pencil, Plus, RotateCcw, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'

import { BulkTransferDialog } from '@/components/equipment/BulkTransferDialog'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterChips, FilterSearch, FilterSelect, type FilterChip } from '@/components/ui/FilterControls'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useColumnLinkedFilters } from '@/hooks/useColumnLinkedFilters'
import { useServerTable } from '@/hooks/useServerTable'
import { DASHBOARD_GROUP_LABELS } from '@/lib/dashboardGroups'
import { scopedTitle } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { equipmentService } from '@/services/equipmentService'
import { optionLabel } from '@/services/lookupService'
import type { Equipment, EquipmentFilterOptions, EquipmentOptions } from '@/types/equipment'
import type { Option } from '@/types/lookup'

const column = gridColumnHelper<Equipment>()

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const EMPTY_FILTERS = {
  search: '',
  office_id: '',
  item_type_id: '',
  brand_id: '',
  functional: '',
  condition: '',
  category: '',
  disposition_status: '',
  estimated_useful_life: '',
  transaction_type: '',
  accountable_officer_id: '',
  received_by_id: '',
  used_by: '',
  awaiting_receipt: '',
  gsp_gaps: '',
  pending: '',
  school_level: '',
  warranty_ending: '',
  duplicates: '',
  high_cost: '',
}

/** Filters that belong to a column: hiding the column hides (and clears) them. */
const FILTERS_BY_COLUMN: Record<string, string[]> = {
  office: ['office_id'],
  item: ['item_type_id', 'brand_id'],
  category: ['category'],
  equipment_condition: ['condition'],
  disposition_status: ['disposition_status'],
  accountable_officer: ['accountable_officer_id'],
  old_property_no: ['duplicates'],
  acquisition_cost: ['high_cost'],
}

const FUNCTIONAL_OPTIONS: Option<string>[] = [
  { value: 'functional', label: 'Functional' },
  { value: 'non_functional', label: 'Non-functional' },
]

type FilterKey = keyof typeof EMPTY_FILTERS

type ChipKey = FilterKey | 'dashboard_group' | 'item_type' | 'ids'

export function EquipmentList() {
  const { can, user } = useAuth()
  const title = scopedTitle('equipment', user?.data_scope)
  usePageTitle(title)
  const navigate = useNavigate()
  const location = useLocation()
  const canCreate = can('equipment.create')
  const canEdit = can('equipment.edit')
  const canDelete = can('equipment.delete')
  const canTransfer = can('equipment.transactions.create')

  const [flash, setFlash] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)
  useEffect(() => {
    if (location.state) navigate({ pathname: location.pathname, search: location.search }, { replace: true, state: null })
  }, [location.pathname, location.search, location.state, navigate])

  const [searchParams, setSearchParams] = useSearchParams()
  const requestedGroup = searchParams.get('dashboard_group') ?? ''
  const dashboardGroup = requestedGroup in DASHBOARD_GROUP_LABELS ? requestedGroup : ''
  const itemTypeIds = (searchParams.get('item_type') ?? '')
    .split(',')
    .filter((id) => /^\d+$/.test(id))
    .map(Number)
  const itemTypeFilter = itemTypeIds.join(',')
  const idFilter = (searchParams.get('ids') ?? '')
    .split(',')
    .filter((id) => /^\d+$/.test(id))
    .join(',')

  function clearUrlFilter(key: 'dashboard_group' | 'item_type' | 'used_by' | 'ids') {
    setSearchParams(
      (params) => {
        params.delete(key)
        return params
      },
      { replace: true },
    )
  }

  const [filterOptions, setFilterOptions] = useState<EquipmentFilterOptions | null>(null)
  const [itemTypeOptions, setItemTypeOptions] = useState<Option<number>[]>([])
  const [equipmentOptions, setEquipmentOptions] = useState<EquipmentOptions | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [transferOpen, setTransferOpen] = useState(false)
  const [confirmingReceipt, setConfirmingReceipt] = useState(false)
  useEffect(() => {
    equipmentService
      .options()
      .then((options) => {
        setFilterOptions(options.filters)
        setItemTypeOptions(options.form.item_type)
        setEquipmentOptions(options)
      })
      .catch(() => setFilterOptions(null))
  }, [])

  const urlFilters = () => ({ ...EMPTY_FILTERS, ...Object.fromEntries(Object.keys(EMPTY_FILTERS).flatMap((key) => (searchParams.get(key) ? [[key, searchParams.get(key) as string]] : []))) })
  const [draft, setDraft] = useState(urlFilters)
  const [applied, setApplied] = useState(urlFilters)
  const [syncedSearch, setSyncedSearch] = useState(searchParams.toString())
  if (searchParams.toString() !== syncedSearch) {
    setSyncedSearch(searchParams.toString())
    const next = urlFilters()
    setDraft(next)
    setApplied(next)
  }
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => equipmentService.list(params),
    { filters: { ...applied, dashboard_group: dashboardGroup, item_type: itemTypeFilter, ids: idFilter }, waitForPerPage: true },
  )

  const { filterShown, onHiddenColumnsChange } = useColumnLinkedFilters(FILTERS_BY_COLUMN, (keys) => {
    const cleared = Object.fromEntries(keys.map((key) => [key, '']))
    setDraft((d) => ({ ...d, ...cleared }))
    setApplied((current) => (keys.some((key) => current[key as keyof typeof current] !== '') ? { ...current, ...cleared } : current))
  })

  function applyFilters() {
    setApplied(draft)
  }

  function clearFilters() {
    setDraft(EMPTY_FILTERS)
    setApplied(EMPTY_FILTERS)
    setSearchParams(
      (params) => {
        params.delete('dashboard_group')
        params.delete('item_type')
        params.delete('ids')
        Object.keys(EMPTY_FILTERS).forEach((key) => params.delete(key))
        return params
      },
      { replace: true },
    )
  }

  function removeFilter(key: ChipKey) {
    if (key === 'dashboard_group' || key === 'item_type' || key === 'ids') {
      clearUrlFilter(key)
      return
    }
    if (searchParams.has(key)) {
      setSearchParams(
        (params) => {
          params.delete(key)
          return params
        },
        { replace: true },
      )
    }
    const next = { ...applied, [key]: '' }
    setApplied(next)
    setDraft(next)
  }

  const groupChip: FilterChip<ChipKey>[] = dashboardGroup
    ? [{ key: 'dashboard_group', label: `Showing: ${DASHBOARD_GROUP_LABELS[dashboardGroup]}`, removable: true }]
    : []
  const itemTypeNames = itemTypeOptions.filter((option) => itemTypeIds.includes(option.value)).map((option) => option.label)
  const itemTypeChip: FilterChip<ChipKey>[] = itemTypeFilter
    ? [{ key: 'item_type', label: `Item type: ${itemTypeNames.length > 0 ? itemTypeNames.join(', ') : 'selected'}`, removable: true }]
    : []

  const filterChips: FilterChip<ChipKey>[] = (
    [
      { key: 'search', label: `Search: ${applied.search}` },
      { key: 'office_id', label: `Office: ${optionLabel(filterOptions?.office, Number(applied.office_id))}` },
      { key: 'item_type_id', label: `Item type: ${optionLabel(filterOptions?.item_type, Number(applied.item_type_id))}` },
      { key: 'brand_id', label: `Brand: ${optionLabel(filterOptions?.brand, Number(applied.brand_id))}` },
      { key: 'functional', label: `Status: ${optionLabel(FUNCTIONAL_OPTIONS, applied.functional)}` },
      { key: 'estimated_useful_life', label: `Useful life: ${optionLabel(filterOptions?.estimated_useful_life, Number(applied.estimated_useful_life))}` },
      { key: 'transaction_type', label: `Latest transaction: ${optionLabel(filterOptions?.transaction_type, applied.transaction_type)}` },
      { key: 'accountable_officer_id', label: `Accountable officer: ${optionLabel(filterOptions?.accountable_officer, Number(applied.accountable_officer_id))}` },
      { key: 'received_by_id', label: `Received by: ${optionLabel(filterOptions?.received_by, Number(applied.received_by_id))}` },
      { key: 'used_by', label: `Used by: ${applied.used_by === 'none' ? 'Not set' : optionLabel(filterOptions?.used_by, applied.used_by)}` },
      { key: 'condition', label: `Condition: ${optionLabel(filterOptions?.condition, Number(applied.condition))}` },
      { key: 'category', label: `Category: ${optionLabel(filterOptions?.category, Number(applied.category))}` },
      { key: 'disposition_status', label: `Disposition: ${optionLabel(filterOptions?.disposition_status, applied.disposition_status)}` },
      { key: 'awaiting_receipt', label: 'Awaiting receipt only' },
      { key: 'gsp_gaps', label: 'Left out of GSP Table 1' },
      { key: 'pending', label: 'Waiting for a property number' },
      { key: 'school_level', label: 'No school level' },
      { key: 'warranty_ending', label: 'Warranty ends within 30 days' },
      { key: 'duplicates', label: 'Duplicate old property no. only' },
      { key: 'high_cost', label: 'Cost looks too high' },
    ] as const
  )
    .filter((chip) => applied[chip.key] !== '')
    .map((chip) => ({ ...chip, removable: true }))

  const idChip: FilterChip<ChipKey>[] = idFilter
    ? [{ key: 'ids', label: `Showing ${idFilter.split(',').length} item(s) from the GSP profile`, removable: true }]
    : []

  const chips = [...groupChip, ...itemTypeChip, ...idChip, ...filterChips]

  const [pendingDelete, setPendingDelete] = useState<Equipment | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  async function confirmReceipt() {
    setConfirmingReceipt(true)
    setActionError(null)
    try {
      const response = await equipmentService.confirmReceipt(selected)
      setFlash(response.message)
      setSelected([])
      await reload()
    } catch (error) {
      setActionError(extractError(error, 'Unable to confirm receipt for the selected items.'))
    } finally {
      setConfirmingReceipt(false)
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    setActionError(null)
    try {
      await equipmentService.remove(pendingDelete.id)
      setFlash('Equipment record deleted.')
      setPendingDelete(null)
      await reload()
    } catch (e) {
      setActionError(extractError(e, 'Unable to delete this equipment record.'))
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  const columns: GridColumn<Equipment>[] = (() => {
    const selectable = items.filter((row) => row.parent_equipment_id === null)
    const allOnPageSelected = selectable.length > 0 && selectable.every((row) => selected.includes(row.id))
    const toggleSelected = (id: number, checked: boolean) =>
      setSelected((prev) => (checked ? [...new Set([...prev, id])] : prev.filter((existing) => existing !== id)))
    const togglePage = (checked: boolean) => {
      const ids = selectable.map((row) => row.id)
      setSelected((prev) => (checked ? [...new Set([...prev, ...ids])] : prev.filter((existing) => !ids.includes(existing))))
    }

    return [
      ...(canTransfer
        ? [
            column.display({
              id: 'select',
              header: () => (
                <input
                  type="checkbox"
                  aria-label="Select all items on this page"
                  className="h-4 w-4 rounded border-gray-300 text-primary-600"
                  checked={allOnPageSelected}
                  onChange={(e) => togglePage(e.target.checked)}
                />
              ),
              enableHiding: false,
              enableSorting: false,
              cell: ({ row }) => {
                const peripheral = row.original.parent_equipment_id !== null
                return (
                  <span onClick={(e) => e.stopPropagation()}>
                    <input
                      type="checkbox"
                      aria-label={`Select ${row.original.property_no ?? 'pending equipment record'}`}
                      title={peripheral ? 'Moves with its desktop' : undefined}
                      className="h-4 w-4 rounded border-gray-300 text-primary-600 disabled:opacity-40"
                      disabled={peripheral}
                      checked={selected.includes(row.original.id)}
                      onChange={(e) => toggleSelected(row.original.id, e.target.checked)}
                    />
                  </span>
                )
              },
            }),
          ]
        : []),
      column.accessor('property_no', {
        header: 'Property No.',
        enableHiding: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => {
          const value = info.getValue()
          return (
            <span className="inline-flex flex-wrap items-center gap-1">
              {value ? <span className="font-mono text-xs font-medium text-gray-900">{value}</span> : <Badge color="warning">Pending</Badge>}
              {info.row.original.awaiting_receipt_since && <Badge color="warning">Awaiting receipt</Badge>}
            </span>
          )
        },
      }),
      column.accessor('old_property_no', {
        header: 'Old Property No.',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => {
          const value = info.getValue()
          if (!value) return <span className="text-gray-400">—</span>
          return (
            <span className="inline-flex items-center gap-2">
              <span className="font-mono text-xs text-gray-700">{value}</span>
              {info.row.original.old_property_no_duplicated && (
                <span title="Another record you can see uses this old property number">
                  <Badge color="warning">Duplicate</Badge>
                </span>
              )}
            </span>
          )
        },
      }),
      column.display({
        id: 'item',
        header: 'Item',
        enableSorting: false,
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="font-medium text-gray-900">{row.original.item ?? '—'}</p>
            <p className="text-xs text-gray-500">
              {row.original.brand_manufacturer}
              {row.original.model && ` — ${row.original.model}`}
            </p>
          </div>
        ),
      }),
      column.accessor('serial_number', {
        header: 'Serial No.',
        meta: { className: 'whitespace-nowrap', defaultHidden: true },
        cell: (info) => info.getValue() ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('category', {
        header: 'Category',
        enableSorting: false,
        meta: { defaultHidden: true },
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('equipment_condition', {
        header: 'Condition',
        enableSorting: false,
        cell: (info) => <Badge color="gray">{info.getValue() ?? '—'}</Badge>,
      }),
      column.accessor('disposition_status', {
        header: 'Disposition',
        cell: (info) => info.getValue(),
      }),
      column.display({
        id: 'accountable_officer',
        header: 'Accountable Officer',
        enableSorting: false,
        cell: ({ row }) => row.original.current_accountable_officer?.full_name ?? <span className="text-gray-400">—</span>,
      }),
      column.display({
        id: 'office',
        header: 'Office',
        enableSorting: false,
        cell: ({ row }) => row.original.office?.office_name ?? <span className="text-gray-400">—</span>,
      }),
      column.display({
        id: 'end_user',
        header: 'End User',
        enableSorting: false,
        cell: ({ row }) => row.original.current_end_user?.full_name ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('acquisition_cost', {
        header: 'Cost',
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: (info) => {
          const value = info.getValue()
          if (value === null) return <span className="text-gray-400">—</span>
          return (
            <span className="inline-flex items-center gap-2">
              {info.row.original.cost_looks_high && (
                <span title="Far above what this item type usually costs. It may be a package total entered on each item.">
                  <Badge color="warning">Check cost</Badge>
                </span>
              )}
              {currency.format(value)}
            </span>
          )
        },
      }),
      column.accessor('received_date', {
        header: 'Received',
        meta: { className: 'whitespace-nowrap', defaultHidden: true },
        cell: (info) => {
          const value = info.getValue()
          return value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—'
        },
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => {
          const label = row.original.property_no ?? 'pending equipment record'
          return (
            <div className="inline-flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
              <Button
                variant="ghost"
                size="sm"
                aria-label={`${canEdit ? 'Edit' : 'View'} ${label}`}
                title={canEdit ? 'Edit' : 'View'}
                onClick={() => navigate(`/equipment/${row.original.id}/edit`)}
              >
                {canEdit ? <Pencil className="h-4 w-4 text-primary-600" /> : <ArrowRight className="h-4 w-4 text-primary-600" />}
              </Button>
              {canDelete && (
                <Button variant="ghost" size="sm" aria-label={`Delete ${label}`} title="Delete" onClick={() => setPendingDelete(row.original)}>
                  <Trash2 className="h-4 w-4 text-error-600" />
                </Button>
              )}
            </div>
          )
        },
      }),
    ]
  })()

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">{title}</h1>
          <p className="text-sm text-gray-600">Manage ICT equipment and asset records.</p>
        </div>
        {canCreate && (
          <div className="page-actions">
            <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/equipment/new')}>
              Add Equipment
            </Button>
          </div>
        )}
      </div>

      {flash && (
        <Alert type="success" className="mb-4">
          {flash}
        </Alert>
      )}
      {actionError && (
        <Alert type="error" className="mb-4">
          {actionError}
        </Alert>
      )}

      <div className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label="Property no., item, brand, serial no., or officer"
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={applyFilters}
            className="sm:col-span-2 lg:col-span-4"
          />
          {filterShown('office_id') && (filterOptions?.office.length ?? 0) > 1 && (
            <FilterSelect label="Office" value={draft.office_id} onChange={(office_id) => setDraft((d) => ({ ...d, office_id }))} className="lg:col-span-4">
              <option value="">All offices</option>
              {filterOptions?.office.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </FilterSelect>
          )}
          {(
            [
              ['item_type_id', 'Item type', 'All item types', filterOptions?.item_type],
              ['brand_id', 'Brand / manufacturer', 'All brands', filterOptions?.brand],
              ['functional', 'Status', 'Functional and non-functional', FUNCTIONAL_OPTIONS],
            ] as const
          )
            .filter(([key]) => filterShown(key))
            .map(([key, label, any, list]) => (
            <FilterSelect key={key} label={label} value={draft[key]} onChange={(value) => setDraft((d) => ({ ...d, [key]: value }))} className="lg:col-span-4">
              <option value="">{any}</option>
              {list?.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </FilterSelect>
          ))}
          {filterShown('condition') && (
<FilterSelect label="Condition" value={draft.condition} onChange={(condition) => setDraft((d) => ({ ...d, condition }))} className="lg:col-span-2">
            <option value="">All conditions</option>
            {filterOptions?.condition.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </FilterSelect>
)}
          {filterShown('category') && (
<FilterSelect label="Category" value={draft.category} onChange={(category) => setDraft((d) => ({ ...d, category }))} className="lg:col-span-3">
            <option value="">All categories</option>
            {filterOptions?.category.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </FilterSelect>
)}
          {filterShown('disposition_status') && (
<FilterSelect
            label="Disposition status"
            value={draft.disposition_status}
            onChange={(disposition_status) => setDraft((d) => ({ ...d, disposition_status }))}
            className="lg:col-span-3"
          >
            <option value="">All disposition statuses</option>
            {filterOptions?.disposition_status.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </FilterSelect>
)}
          {(
            [
              ['estimated_useful_life', 'Estimated useful life', 'Any useful life', filterOptions?.estimated_useful_life],
              ['transaction_type', 'Latest transaction type', 'All transaction types', filterOptions?.transaction_type],
              ['accountable_officer_id', 'Accountable officer', 'All accountable officers', filterOptions?.accountable_officer],
              ['received_by_id', 'Received by (latest transaction)', 'Anyone', filterOptions?.received_by],
              ['used_by', 'Used by', 'Anyone', [...(filterOptions?.used_by ?? []), { value: 'none', label: 'Not set' }]],
            ] as const
          )
            .filter(([key]) => filterShown(key))
            .map(([key, label, any, list]) => (
            <FilterSelect key={key} label={label} value={draft[key]} onChange={(value) => setDraft((d) => ({ ...d, [key]: value }))} className="lg:col-span-3">
              <option value="">{any}</option>
              {list?.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </FilterSelect>
          ))}
          <FilterSelect label="Receipt" value={draft.awaiting_receipt} onChange={(awaiting_receipt) => setDraft((d) => ({ ...d, awaiting_receipt }))} className="lg:col-span-3">
            <option value="">All records</option>
            <option value="1">Awaiting receipt</option>
          </FilterSelect>
          {filterShown('duplicates') && (
<FilterSelect
            label="Old property no."
            value={draft.duplicates}
            onChange={(duplicates) => setDraft((d) => ({ ...d, duplicates }))}
            className="lg:col-span-3"
          >
            <option value="">All records</option>
            <option value="1">Duplicates only</option>
          </FilterSelect>
)}
          {filterShown('high_cost') && (
<FilterSelect label="Cost" value={draft.high_cost} onChange={(high_cost) => setDraft((d) => ({ ...d, high_cost }))} className="lg:col-span-3">
            <option value="">All costs</option>
            <option value="1">Cost looks too high</option>
          </FilterSelect>
)}
        </div>

        <div className="mt-3 flex flex-col-reverse gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:items-center sm:justify-between">
          <FilterChips chips={chips} onRemove={removeFilter} />
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={clearFilters}
              leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
              className="flex-1 sm:flex-none"
              disabled={!dirty && chips.length === 0}
            >
              Reset
            </Button>
            <Button size="sm" onClick={applyFilters} leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
              Apply Filters
            </Button>
          </div>
        </div>
      </div>

      {canTransfer && selected.length > 0 && (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm" role="status">
          <span className="font-medium text-primary-900">{selected.length} selected</span>
          <div className="flex gap-2">
            <Button size="sm" leftIcon={<ArrowRightLeft className="h-4 w-4" />} disabled={!equipmentOptions} onClick={() => setTransferOpen(true)}>
              Transfer to office
            </Button>
            <Button size="sm" variant="outline" leftIcon={<PackageCheck className="h-4 w-4" />} isLoading={confirmingReceipt} onClick={confirmReceipt}>
              Confirm receipt
            </Button>
            <Button size="sm" variant="outline" leftIcon={<X className="h-4 w-4" />} onClick={() => setSelected([])}>
              Clear
            </Button>
          </div>
        </div>
      )}

      <DataGrid
        id="equipment"
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
        rowHeights={{ comfortable: 61, compact: 49 }}
        onHiddenColumnsChange={onHiddenColumnsChange}
        emptyMessage="No equipment records found."
        onRowClick={(row) => navigate(`/equipment/${row.id}/edit`)}
      />

      {equipmentOptions && (
        <BulkTransferDialog
          open={transferOpen}
          onOpenChange={setTransferOpen}
          equipmentIds={selected}
          options={equipmentOptions}
          onTransferred={(message) => {
            setFlash(message)
            setSelected([])
            void reload()
          }}
        />
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete equipment record?"
        description={`This will soft-delete ${
          pendingDelete?.property_no ? `property no. ${pendingDelete.property_no}` : 'this pending equipment record'
        }. The record can be restored later if needed, but it will disappear from this list.`}
        confirmText="Delete"
      />
    </div>
  )
}
