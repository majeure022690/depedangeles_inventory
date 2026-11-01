import { ArrowRight, Download, Filter, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterChips, FilterSearch, type FilterChip } from '@/components/ui/FilterControls'
import { Input } from '@/components/ui/Input'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError } from '@/lib/errors'
import { formatDate } from '@/lib/utils'
import { gspProfileService } from '@/services/gspProfileService'
import type { GspProfile } from '@/types/gsp-profile'

const column = gridColumnHelper<GspProfile>()

const EMPTY_FILTERS = { search: '', school_year: '' }

type Filters = typeof EMPTY_FILTERS
type FilterKey = keyof Filters
type Feedback = { type: 'success' | 'error'; message: string } | null

export function GspProfileList() {
  usePageTitle('GSP ICT Profiles')
  const navigate = useNavigate()
  const { can } = useAuth()
  const canCreate = can('gsp_profiles.create')
  const canEdit = can('gsp_profiles.edit')
  const canDelete = can('gsp_profiles.delete')
  const canDownload = can('reports.gsp_ict')

  const [draft, setDraft] = useState<Filters>(EMPTY_FILTERS)
  const [applied, setApplied] = useState<Filters>(EMPTY_FILTERS)
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => gspProfileService.list(params),
    {
      filters: applied,
      waitForPerPage: true,
    },
  )

  const [feedback, setFeedback] = useState<Feedback>(null)
  const [pendingDelete, setPendingDelete] = useState<GspProfile | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [downloadingId, setDownloadingId] = useState<number | null>(null)

  const chips: FilterChip<FilterKey>[] = (
    [
      { key: 'search', label: `School: ${applied.search}` },
      { key: 'school_year', label: `SY ${applied.school_year}` },
    ] as const
  )
    .filter((chip) => applied[chip.key] !== '')
    .map((chip) => ({ ...chip, removable: true }))

  function removeFilter(key: FilterKey) {
    const next = { ...applied, [key]: '' }
    setApplied(next)
    setDraft(next)
  }

  async function download(profile: GspProfile) {
    setDownloadingId(profile.id)
    setFeedback(null)
    try {
      await gspProfileService.download(profile.id)
    } catch (downloadError) {
      setFeedback({
        type: 'error',
        message: extractError(downloadError, 'Unable to generate the workbook.'),
      })
    } finally {
      setDownloadingId(null)
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await gspProfileService.remove(pendingDelete.id)
      setFeedback({ type: 'success', message: 'GSP ICT profile deleted.' })
      setPendingDelete(null)
      await reload()
    } catch (deleteError) {
      setFeedback({
        type: 'error',
        message: extractError(deleteError, 'Unable to delete the profile. Please try again.'),
      })
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  const columns = useMemo<GridColumn<GspProfile>[]>(
    () => [
      column.display({
        id: 'office',
        header: 'School',
        enableHiding: false,
        cell: ({ row }) => (
          <div>
            <span className="font-medium text-gray-900">{row.original.office?.office_name ?? '—'}</span>
            {row.original.office?.school_id && <p className="text-xs text-gray-500">School ID {row.original.office.school_id}</p>}
          </div>
        ),
      }),
      column.accessor('school_year', {
        header: 'School year',
        meta: { className: 'whitespace-nowrap' },
      }),
      column.accessor('as_of', {
        header: 'As of',
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => formatDate(info.getValue()),
      }),
      column.accessor('level_label', { header: 'Level', enableSorting: false }),
      column.display({
        id: 'school_head',
        header: 'School head',
        cell: ({ row }) => row.original.school_head?.label ?? <span className="text-gray-400">—</span>,
      }),
      column.accessor('updated_at', {
        header: 'Last updated',
        meta: { className: 'whitespace-nowrap text-xs text-gray-500' },
        cell: (info) => formatDate(info.getValue()) || '—',
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => {
          const label = `${row.original.office?.office_name ?? 'profile'} SY ${row.original.school_year}`
          return (
            <div className="flex items-center justify-end gap-1">
              {canDownload && (
                <Button
                  variant="outline"
                  size="sm"
                  aria-label={`Download the GSP workbook for ${label}`}
                  leftIcon={<Download className="h-4 w-4" />}
                  isLoading={downloadingId === row.original.id}
                  onClick={(event) => {
                    event.stopPropagation()
                    void download(row.original)
                  }}
                >
                  GSP
                </Button>
              )}
              {canDelete && (
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={`Delete ${label}`}
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
                aria-label={`${canEdit ? 'Edit' : 'View'} ${label}`}
                title={canEdit ? 'Edit' : 'View'}
                onClick={(event) => {
                  event.stopPropagation()
                  navigate(`/gsp-profiles/${row.original.id}/edit`)
                }}
              >
                <ArrowRight className="h-4 w-4 text-primary-600" />
              </Button>
            </div>
          )
        },
      }),
    ],
    [canDelete, canDownload, canEdit, downloadingId, navigate],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">GSP ICT Profiles</h1>
          <p className="text-sm text-gray-600">
            Government School Profile, Part VI (ICT in Schools): one profile per school per school year. Computers, ICT equipment and internet subscriptions are
            filled from IRIS records.
          </p>
        </div>
        {canCreate && (
          <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/gsp-profiles/new')}>
            New profile
          </Button>
        )}
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}

      <form
        className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        role="search"
        aria-label="Filter GSP ICT profiles"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied(draft)
        }}
      >
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label="Search by school name or School ID..."
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={() => setApplied(draft)}
            className="sm:col-span-2 lg:col-span-9"
          />
          <Input
            aria-label="School year"
            placeholder="School year, e.g. 2025-2026"
            value={draft.school_year}
            maxLength={9}
            onChange={(event) => setDraft((d) => ({ ...d, school_year: event.target.value }))}
            className="lg:col-span-3"
          />
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
        </div>
      </form>

      <DataGrid
        id="gsp-profiles"
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
        emptyMessage="No GSP ICT profiles yet."
        onRowClick={(row) => navigate(`/gsp-profiles/${row.id}/edit`)}
      />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete GSP ICT profile?"
        description={
          pendingDelete ? `The SY ${pendingDelete.school_year} profile of ${pendingDelete.office?.office_name ?? 'this office'} will be deleted.` : ''
        }
        confirmText="Delete"
        confirmVariant="danger"
      />
    </div>
  )
}
