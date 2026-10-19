import { ArrowRight, Filter, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

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
import type { PermissionName } from '@/lib/permissions'
import { ispAccountService } from '@/services/ispAccountService'
import type { IspAccount, IspAccountOptions, IspAccountStatusFilter } from '@/types/isp-account'

const column = gridColumnHelper<IspAccount>()

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const STATUS_LABELS: Record<Exclude<IspAccountStatusFilter, ''>, string> = { active: 'Active', inactive: 'Inactive' }

const EMPTY_FILTERS = { search: '', isp: '', status: '' as IspAccountStatusFilter }

type Filters = typeof EMPTY_FILTERS
type FilterKey = keyof Filters
type Feedback = { type: 'success' | 'error'; message: string } | null

export function IspAccountList() {
  usePageTitle('ISP Accounts')
  const navigate = useNavigate()
  const { can } = useAuth()
  const allowed = (permission: PermissionName) => can(permission)
  const canCreate = allowed('isp_accounts.create')
  const canEdit = allowed('isp_accounts.edit')
  const canDelete = allowed('isp_accounts.delete')

  const [options, setOptions] = useState<IspAccountOptions | null>(null)
  useEffect(() => {
    ispAccountService.options().then(setOptions).catch(() => setOptions(null))
  }, [])

  const [draft, setDraft] = useState<Filters>(EMPTY_FILTERS)
  const [applied, setApplied] = useState<Filters>(EMPTY_FILTERS)
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const { items, meta, loading, error, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => ispAccountService.list(params),
    { filters: applied, waitForPerPage: true },
  )

  const [feedback, setFeedback] = useState<Feedback>(null)
  const [pendingDelete, setPendingDelete] = useState<IspAccount | null>(null)
  const [deleting, setDeleting] = useState(false)

  const providerName = (id: string) => options?.filters.isp_provider.find((p) => String(p.value) === id)?.label ?? 'ISP provider'

  const chips: FilterChip<FilterKey>[] = (
    [
      { key: 'search', label: `Search: ${applied.search}` },
      { key: 'isp', label: providerName(applied.isp) },
      { key: 'status', label: applied.status ? STATUS_LABELS[applied.status] : '' },
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

  async function confirmDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await ispAccountService.remove(pendingDelete.id)
      setFeedback({ type: 'success', message: 'ISP account deleted.' })
      setPendingDelete(null)
      await reload()
    } catch (deleteError) {
      setFeedback({ type: 'error', message: extractError(deleteError, 'Unable to delete the ISP account. Please try again.') })
      setPendingDelete(null)
    } finally {
      setDeleting(false)
    }
  }

  const columns = useMemo<GridColumn<IspAccount>[]>(
    () => [
      column.accessor('isp', {
        header: 'ISP',
        enableSorting: false,
        enableHiding: false,
        cell: ({ row }) => <span className="font-medium text-gray-900">{row.original.isp ?? '—'}</span>,
      }),
      column.accessor('isp_billing_account_no', {
        header: 'Account no.',
        cell: (info) => <span className="font-mono text-xs">{info.getValue()}</span>,
      }),
      column.accessor('subscription_type', { header: 'Subscription type', enableSorting: false }),
      column.accessor('isp_connection_type', { header: 'Connection type', enableSorting: false }),
      column.accessor('cost_per_month', {
        header: 'Cost/month',
        meta: { align: 'right', className: 'whitespace-nowrap tabular-nums' },
        cell: (info) => currency.format(info.getValue()),
      }),
      column.accessor('contract_start_date', {
        header: 'Contract period',
        meta: { className: 'whitespace-nowrap text-xs text-gray-500' },
        cell: ({ row }) => `${row.original.contract_start_date ?? '—'} to ${row.original.contract_end_date ?? '—'}`,
      }),
      column.accessor('overall_signal_quality', {
        header: 'Signal quality',
        enableSorting: false,
        cell: (info) => <Badge color="primary">{info.getValue()}</Badge>,
      }),
      column.accessor('inactive_contract', {
        header: 'Status',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <Badge color={info.getValue() ? 'error' : 'success'}>{info.getValue() ? 'Inactive' : 'Active'}</Badge>,
      }),
      column.accessor('created_at', {
        header: 'Added',
        meta: { defaultHidden: true, className: 'whitespace-nowrap' },
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
        cell: ({ row }) => {
          const label = `${row.original.isp ?? 'ISP account'} — ${row.original.isp_billing_account_no}`
          return (
            <div className="flex items-center justify-end gap-1">
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
                  navigate(`/portal/isp-accounts/${row.original.id}/edit`)
                }}
              >
                <ArrowRight className="h-4 w-4 text-primary-600" />
              </Button>
            </div>
          )
        },
      }),
    ],
    [canDelete, canEdit, navigate],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">ISP Accounts</h1>
          <p className="text-sm text-gray-600">Manage internet service provider subscriptions and contracts.</p>
        </div>
        {canCreate && (
          <Button size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => navigate('/portal/isp-accounts/new')}>
            Add ISP account
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
        aria-label="Filter ISP accounts"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied(draft)
        }}
      >
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label="Search by ISP or billing account no..."
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={() => setApplied(draft)}
            className="sm:col-span-2 lg:col-span-6"
          />
          <FilterSelect label="ISP provider" value={draft.isp} onChange={(isp) => setDraft((d) => ({ ...d, isp }))} className="lg:col-span-3">
            <option value="">All providers</option>
            {options?.filters.isp_provider.map((provider) => (
              <option key={provider.value} value={provider.value}>
                {provider.label}
              </option>
            ))}
          </FilterSelect>
          <FilterSelect
            label="Contract status"
            value={draft.status}
            onChange={(status) => setDraft((d) => ({ ...d, status: status as IspAccountStatusFilter }))}
            className="lg:col-span-3"
          >
            <option value="">All statuses</option>
            {Object.entries(STATUS_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
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

      <DataGrid
        id="isp-accounts"
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
        emptyMessage="No ISP account records found."
        onRowClick={(row) => navigate(`/portal/isp-accounts/${row.id}/edit`)}
      />

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete ISP account record?"
        description={
          pendingDelete
            ? `This will soft-delete the ${pendingDelete.isp ?? ''} account (billing no. ${pendingDelete.isp_billing_account_no}). The record can be restored later if needed, but it will disappear from this list.`
            : ''
        }
        confirmText="Delete"
      />
    </div>
  )
}
