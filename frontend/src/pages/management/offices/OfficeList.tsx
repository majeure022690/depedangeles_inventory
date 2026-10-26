import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

import { officeDeleteErrorMessage } from '@/components/offices/OfficeFields'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterSearch } from '@/components/ui/FilterControls'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { officeService } from '@/services/officeService'
import type { Office } from '@/types/office'

const column = gridColumnHelper<Office>()

type Feedback = { type: 'success' | 'error'; message: string }

export function OfficeList() {
  usePageTitle('Offices')
  const navigate = useNavigate()
  const location = useLocation()
  const { can } = useAuth()
  const canEdit = can('office.edit')
  const canDelete = can('office.delete')

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, setSearch, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => officeService.list(params),
    { waitForPerPage: true },
  )

  const [searchDraft, setSearchDraft] = useState('')
  useEffect(() => {
    const timer = window.setTimeout(() => setSearch(searchDraft.trim()), 300)
    return () => window.clearTimeout(timer)
    // setSearch is recreated every render; only the typed value should restart the debounce.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchDraft])

  // Create/Edit pages navigate back here with a one-shot message in router state.
  const [feedback, setFeedback] = useState<Feedback | null>(() => {
    const flash = (location.state as { flash?: string } | null)?.flash
    return flash ? { type: 'success', message: flash } : null
  })
  useEffect(() => {
    if ((location.state as { flash?: string } | null)?.flash) navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  const [pendingDelete, setPendingDelete] = useState<Office | null>(null)
  const [deleting, setDeleting] = useState(false)

  async function handleDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await officeService.remove(pendingDelete.id)
      setFeedback({ type: 'success', message: `"${pendingDelete.office_name}" was deleted.` })
      await reload()
    } catch (deleteError) {
      setFeedback({ type: 'error', message: officeDeleteErrorMessage(deleteError) })
    } finally {
      setDeleting(false)
      setPendingDelete(null)
    }
  }

  const columns = useMemo<GridColumn<Office>[]>(
    () => [
      column.accessor('office_name', {
        header: 'Office name',
        enableHiding: false,
        cell: ({ row }) => (
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-gray-900">{row.original.office_name}</span>
            {row.original.managed_by_prime && (
              <Badge color="primary" className="px-2">
                <span aria-hidden="true">PRIME</span>
                <span className="sr-only">Managed by PRIME</span>
              </Badge>
            )}
          </span>
        ),
      }),
      column.accessor('office_type', {
        header: 'Type',
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('school_id', {
        header: 'School ID',
        meta: { className: 'whitespace-nowrap font-mono text-xs' },
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('district', {
        header: 'District',
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('division', {
        header: 'Division',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('region', {
        header: 'Region',
        enableSorting: false,
        meta: { defaultHidden: true },
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('contact_number', {
        header: 'Contact',
        enableSorting: false,
        meta: { defaultHidden: true, className: 'whitespace-nowrap' },
        cell: (info) => info.getValue() ?? '—',
      }),
      column.accessor('is_active', {
        header: 'Status',
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <Badge color={info.getValue() ? 'success' : 'gray'}>{info.getValue() ? 'Active' : 'Inactive'}</Badge>,
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => navigate(`/offices/${row.original.id}/edit`)}
              aria-label={`${canEdit ? 'Edit' : 'View'} ${row.original.office_name}`}
              title={canEdit ? 'Edit office' : 'View office'}
            >
              <Pencil className="h-4 w-4 text-primary-600" />
            </Button>
            {canDelete && !row.original.managed_by_prime && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setPendingDelete(row.original)}
                aria-label={`Delete ${row.original.office_name}`}
                title="Delete office"
              >
                <Trash2 className="h-4 w-4 text-error-600" />
              </Button>
            )}
          </div>
        ),
      }),
    ],
    [navigate, canEdit, canDelete],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Offices</h1>
          <p className="text-sm text-gray-600">Schools and division-level offices/units that users, office profiles and connectivity surveys belong to.</p>
        </div>
        {can('office.create') && (
          <div className="page-actions">
            <Button leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/offices/new')} size="sm">
              Add office
            </Button>
          </div>
        )}
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          {feedback.message}
        </Alert>
      )}

      <DataGrid
        id="offices"
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
        emptyMessage={searchDraft ? 'No offices match this search.' : 'No offices found.'}
        onRowClick={(row) => navigate(`/offices/${row.id}/edit`)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-3">
            <div className="w-full sm:w-72">
              <FilterSearch
                label="Search by office name, type, or School ID"
                value={searchDraft}
                onChange={setSearchDraft}
                onSubmit={() => setSearch(searchDraft.trim())}
              />
            </div>
            {meta && (
              <span>
                <span className="font-semibold text-gray-900">{meta.total.toLocaleString()}</span> office{meta.total === 1 ? '' : 's'}
              </span>
            )}
          </div>
        }
      />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={handleDelete}
        title="Delete office?"
        description={`This permanently deletes "${pendingDelete?.office_name ?? ''}". If user accounts, stakeholder profiles or connectivity surveys still refer to it, deactivate it instead.`}
        confirmText="Delete"
        loading={deleting}
      />
    </div>
  )
}
