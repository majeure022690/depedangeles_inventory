import { ArrowRight, Filter, RotateCcw } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { FilterChips, FilterSearch, type FilterChip } from '@/components/ui/FilterControls'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { formatDateTime } from '@/lib/utils'
import { stakeholderProfileService } from '@/services/stakeholderProfileService'
import type { StakeholderProfileOfficeRow } from '@/types/stakeholder-profile'

const column = gridColumnHelper<StakeholderProfileOfficeRow>()

/** Cross-office admin list (`stakeholder_profile.view_all`): one profile per office. */
export function StakeholderProfileList() {
  usePageTitle('Stakeholder Profile')
  const navigate = useNavigate()

  const [search, setSearch] = useState('')
  const [applied, setApplied] = useState({ search: '' })

  const { items, meta, loading, error, page, setPage, perPage, setPerPage } = useServerTable((params) => stakeholderProfileService.list(params), {
    filters: applied,
    waitForPerPage: true,
  })

  const chips: FilterChip<'search'>[] = applied.search ? [{ key: 'search', label: `Office: ${applied.search}`, removable: true }] : []

  function reset() {
    setSearch('')
    setApplied({ search: '' })
  }

  const columns = useMemo<GridColumn<StakeholderProfileOfficeRow>[]>(
    () => [
      column.accessor('office_name', {
        header: 'Office',
        enableSorting: false,
        enableHiding: false,
        cell: (info) => <span className="font-medium text-gray-900">{info.getValue()}</span>,
      }),
      column.accessor('office_type', { header: 'Type', enableSorting: false, cell: (info) => info.getValue() ?? '—' }),
      column.accessor('school_id', { header: 'School ID', enableSorting: false, cell: (info) => info.getValue() ?? '—' }),
      column.accessor('has_profile', {
        header: 'Status',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <Badge color={info.getValue() ? 'success' : 'gray'}>{info.getValue() ? 'Submitted' : 'Not started'}</Badge>,
      }),
      column.accessor('updated_at', {
        header: 'Last updated',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap text-xs text-gray-500' },
        cell: (info) => formatDateTime(info.getValue()) || '—',
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right', className: 'whitespace-nowrap' },
        cell: ({ row }) => (
          <Button
            variant="ghost"
            size="sm"
            aria-label={`${row.original.has_profile ? 'Edit' : 'Start'} the stakeholder profile of ${row.original.office_name}`}
            onClick={(event) => {
              event.stopPropagation()
              navigate(`/stakeholder-profiles/${row.original.id}`)
            }}
            rightIcon={<ArrowRight className="h-4 w-4 text-primary-600" />}
          >
            {row.original.has_profile ? 'Edit' : 'Start'}
          </Button>
        ),
      }),
    ],
    [navigate],
  )

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">Stakeholder Profile</h1>
        <p className="text-sm text-gray-600">
          Every school and division-level office&rsquo;s stakeholder profile — one per office, filled in by that office&rsquo;s own users.
        </p>
      </div>

      <form
        className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        role="search"
        aria-label="Filter offices"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied({ search })
        }}
      >
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <FilterSearch label="Search by office name..." value={search} onChange={setSearch} onSubmit={() => setApplied({ search })} className="sm:max-w-md sm:flex-1" />
          <div className="flex flex-1 flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
            <FilterChips chips={chips} onRemove={reset} />
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
                className="flex-1 sm:flex-none"
                disabled={search === '' && applied.search === ''}
                onClick={reset}
              >
                Reset
              </Button>
              <Button type="submit" size="sm" leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
                Search
              </Button>
            </div>
          </div>
        </div>
      </form>

      <DataGrid
        id="stakeholder-profiles"
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
        emptyMessage="No offices found."
        onRowClick={(row) => navigate(`/stakeholder-profiles/${row.id}`)}
      />
    </div>
  )
}
