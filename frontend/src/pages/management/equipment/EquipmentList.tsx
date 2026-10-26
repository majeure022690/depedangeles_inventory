import { ArrowRight, Filter, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterChips, FilterSearch, FilterSelect, type FilterChip } from '@/components/ui/FilterControls'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { scopedTitle } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { equipmentService } from '@/services/equipmentService'
import { optionLabel } from '@/services/lookupService'
import type { Equipment, EquipmentFilterOptions } from '@/types/equipment'

const column = gridColumnHelper<Equipment>()

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const EMPTY_FILTERS = { search: '', condition: '', category: '', disposition_status: '' }

type FilterKey = keyof typeof EMPTY_FILTERS

export function EquipmentList() {
  const { can, user } = useAuth()
  const title = scopedTitle('equipment', user?.data_scope)
  usePageTitle(title)
  const navigate = useNavigate()
  const location = useLocation()
  const canCreate = can('equipment.create')
  const canEdit = can('equipment.edit')
  const canDelete = can('equipment.delete')

  const [flash, setFlash] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)
  useEffect(() => {
    if (location.state) navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  const [filterOptions, setFilterOptions] = useState<EquipmentFilterOptions | null>(null)
  useEffect(() => {
    equipmentService
      .options()
      .then((options) => setFilterOptions(options.filters))
      .catch(() => setFilterOptions(null))
  }, [])

  const [draft, setDraft] = useState(EMPTY_FILTERS)
  const [applied, setApplied] = useState(EMPTY_FILTERS)
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => equipmentService.list(params),
    { filters: applied, waitForPerPage: true },
  )

  function applyFilters() {
    setApplied(draft)
  }

  function clearFilters() {
    setDraft(EMPTY_FILTERS)
    setApplied(EMPTY_FILTERS)
  }

  function removeFilter(key: FilterKey) {
    const next = { ...applied, [key]: '' }
    setApplied(next)
    setDraft(next)
  }

  const chips: FilterChip<FilterKey>[] = (
    [
      { key: 'search', label: `Search: ${applied.search}` },
      { key: 'condition', label: `Condition: ${optionLabel(filterOptions?.condition, Number(applied.condition))}` },
      { key: 'category', label: `Category: ${optionLabel(filterOptions?.category, Number(applied.category))}` },
      { key: 'disposition_status', label: `Disposition: ${optionLabel(filterOptions?.disposition_status, applied.disposition_status)}` },
    ] as const
  )
    .filter((chip) => applied[chip.key] !== '')
    .map((chip) => ({ ...chip, removable: true }))

  const [pendingDelete, setPendingDelete] = useState<Equipment | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

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

  const columns = useMemo<GridColumn<Equipment>[]>(
    () => [
      column.accessor('property_no', {
        header: 'Property No.',
        enableHiding: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => {
          const value = info.getValue()
          return value ? <span className="font-mono text-xs font-medium text-gray-900">{value}</span> : <Badge color="warning">Pending</Badge>
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
          return value !== null ? currency.format(value) : <span className="text-gray-400">—</span>
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
    ],
    [canEdit, canDelete, navigate],
  )

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
            label="Property no., item, brand, or serial no."
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={applyFilters}
            className="sm:col-span-2 lg:col-span-4"
          />
          <FilterSelect label="Condition" value={draft.condition} onChange={(condition) => setDraft((d) => ({ ...d, condition }))} className="lg:col-span-2">
            <option value="">All conditions</option>
            {filterOptions?.condition.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </FilterSelect>
          <FilterSelect label="Category" value={draft.category} onChange={(category) => setDraft((d) => ({ ...d, category }))} className="lg:col-span-3">
            <option value="">All categories</option>
            {filterOptions?.category.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </FilterSelect>
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
        emptyMessage="No equipment records found."
        onRowClick={(row) => navigate(`/equipment/${row.id}/edit`)}
      />

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
