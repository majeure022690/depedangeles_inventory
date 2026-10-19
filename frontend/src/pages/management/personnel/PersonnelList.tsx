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
import { extractError } from '@/lib/errors'
import { personnelService } from '@/services/personnelService'
import type { Personnel, PersonnelStatusFilter } from '@/types/personnel'

const column = gridColumnHelper<Personnel>()

const STATUS_OPTIONS: { value: PersonnelStatusFilter; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'all', label: 'All' },
]

/** `status=active` is the server's default, so it's the baseline "no filter" state, not a removable chip. */
const DEFAULT_FILTERS: { search: string; status: PersonnelStatusFilter } = { search: '', status: 'active' }

type FilterKey = keyof typeof DEFAULT_FILTERS

export function PersonnelList() {
  usePageTitle('Personnel')
  const navigate = useNavigate()
  const location = useLocation()
  const { can } = useAuth()
  const canCreate = can('personnel.create')
  const canEdit = can('personnel.edit')
  const canDelete = can('personnel.delete')

  const [flash, setFlash] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)
  useEffect(() => {
    if (location.state) navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  const [draft, setDraft] = useState(DEFAULT_FILTERS)
  const [applied, setApplied] = useState(DEFAULT_FILTERS)
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => personnelService.list(params),
    { filters: applied, waitForPerPage: true },
  )

  function applyFilters() {
    setApplied(draft)
  }

  function clearFilters() {
    setDraft(DEFAULT_FILTERS)
    setApplied(DEFAULT_FILTERS)
  }

  function removeFilter(key: FilterKey) {
    const next = { ...applied, [key]: DEFAULT_FILTERS[key] }
    setApplied(next)
    setDraft(next)
  }

  const chips: FilterChip<FilterKey>[] = [
    { key: 'search' as const, label: `Search: ${applied.search}`, removable: applied.search !== '' },
    {
      key: 'status' as const,
      label: `Status: ${STATUS_OPTIONS.find((option) => option.value === applied.status)?.label ?? applied.status}`,
      removable: applied.status !== DEFAULT_FILTERS.status,
    },
  ].filter((chip) => chip.key === 'status' || chip.removable)

  const [pendingDelete, setPendingDelete] = useState<Personnel | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    setActionError(null)
    try {
      await personnelService.remove(pendingDelete.id)
      setFlash('Personnel record deleted.')
      setPendingDelete(null)
      await reload()
    } catch (e) {
      setActionError(extractError(e, 'Unable to delete this personnel record.'))
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  const columns = useMemo<GridColumn<Personnel>[]>(
    () => [
      column.accessor('employee_id', {
        header: 'Employee ID',
        enableHiding: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <span className="font-mono text-xs font-medium text-gray-900">{info.getValue()}</span>,
      }),
      column.accessor('full_name', {
        header: 'Name',
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="font-medium text-gray-900">{row.original.full_name}</p>
            {row.original.oic && <p className="text-xs text-gray-500">OIC{row.original.oic_office ? ` — ${row.original.oic_office}` : ''}</p>}
            {row.original.deped_email && <p className="text-xs text-gray-500">{row.original.deped_email}</p>}
          </div>
        ),
      }),
      column.accessor('position', {
        header: 'Position',
        enableSorting: false,
        cell: (info) => info.getValue() ?? <span className="text-gray-400">—</span>,
      }),
      column.display({
        id: 'office',
        header: 'Office',
        enableSorting: false,
        cell: ({ row }) => row.original.division_unit ?? row.original.ro_division ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('mobile_1', {
        header: 'Mobile',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => info.getValue() ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('inactive', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => (info.getValue() ? <Badge color="error">Inactive</Badge> : <Badge color="success">Active</Badge>),
      }),
      column.accessor('created_at', {
        header: 'Added',
        meta: { className: 'whitespace-nowrap', defaultHidden: true },
        cell: (info) => {
          const value = info.getValue()
          return value ? new Date(value).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—'
        },
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => (
          <div className="inline-flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
            <Button
              variant="ghost"
              size="sm"
              aria-label={`${canEdit ? 'Edit' : 'View'} ${row.original.full_name}`}
              title={canEdit ? 'Edit' : 'View'}
              onClick={() => navigate(`/portal/personnel/${row.original.id}/edit`)}
            >
              {canEdit ? <Pencil className="h-4 w-4 text-primary-600" /> : <ArrowRight className="h-4 w-4 text-primary-600" />}
            </Button>
            {canDelete && (
              <Button variant="ghost" size="sm" aria-label={`Delete ${row.original.full_name}`} title="Delete" onClick={() => setPendingDelete(row.original)}>
                <Trash2 className="h-4 w-4 text-error-600" />
              </Button>
            )}
          </div>
        ),
      }),
    ],
    [canEdit, canDelete, navigate],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Personnel</h1>
          <p className="text-sm text-gray-600">Manage division personnel records.</p>
        </div>
        {canCreate && (
          <div className="page-actions">
            <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/portal/personnel/new')}>
              Add Personnel
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
            label="Name, employee ID, or email"
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={applyFilters}
            className="lg:col-span-5"
          />
          <FilterSelect
            label="Status"
            value={draft.status}
            onChange={(status) => setDraft((d) => ({ ...d, status: status as PersonnelStatusFilter }))}
            className="lg:col-span-2"
          >
            {STATUS_OPTIONS.map((option) => (
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
              disabled={!dirty && chips.every((chip) => !chip.removable)}
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
        id="personnel"
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
        emptyMessage="No personnel records found."
        onRowClick={(row) => navigate(`/portal/personnel/${row.id}/edit`)}
      />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete personnel record?"
        description={`This will soft-delete ${pendingDelete?.full_name ?? 'this record'}. The record can be restored later if needed, but it will disappear from this list.`}
        confirmText="Delete"
      />
    </div>
  )
}
