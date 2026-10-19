import { Filter, RotateCcw, UserSearch } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { FilterChips, FilterSearch, FilterSelect, filterFieldClass, type FilterChip } from '@/components/ui/FilterControls'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError } from '@/lib/errors'
import { auditLogService } from '@/services/auditLogService'
import type { AuditLogEntry, AuditLogOptions } from '@/types/auditLog'

const eventColors: Record<string, 'success' | 'primary' | 'error' | 'warning' | 'gray'> = {
  created: 'success',
  updated: 'primary',
  deleted: 'error',
  'authorization.denied': 'warning',
}

const MAX_CHANGES_SHOWN = 3

const column = gridColumnHelper<AuditLogEntry>()

/** `user.roles_changed` -> `User roles changed`. */
function humanize(value: string): string {
  const text = value.replace(/[._-]+/g, ' ').trim()
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function formatValue(value: unknown, limit = 40): string {
  if (value === null || value === undefined || value === '') return 'empty'
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
  return limit && text.length > limit ? `${text.slice(0, limit)}…` : text
}

function fieldLabel(field: string): string {
  return field.replace(/_id$/, '').replace(/_/g, ' ')
}

function formatDateTime(iso: string): { date: string; time: string } {
  const date = new Date(iso)
  return {
    date: date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }),
    time: date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' }),
  }
}

function actorName(entry: AuditLogEntry): string {
  return entry.actor?.name ?? entry.actor?.email ?? 'System'
}

interface ChangeRow {
  field: string
  before: unknown
  after: unknown
  hasBefore: boolean
  hasAfter: boolean
}

function changeRows(entry: AuditLogEntry): ChangeRow[] {
  const before = entry.changes.old ?? {}
  const after = entry.changes.attributes ?? {}
  const fields = [...new Set([...Object.keys(after), ...Object.keys(before)])]
  return fields.map((field) => ({ field, before: before[field], after: after[field], hasBefore: field in before, hasAfter: field in after }))
}

function ChangeValue({ row, limit }: { row: ChangeRow; limit?: number }) {
  if (row.hasBefore && row.hasAfter) {
    return (
      <>
        <span className="text-gray-400 line-through">{formatValue(row.before, limit)}</span>
        <span aria-label="changed to"> → </span>
        {formatValue(row.after, limit)}
      </>
    )
  }
  if (row.hasBefore) return <span className="text-gray-400 line-through">{formatValue(row.before, limit)}</span>
  return <>{formatValue(row.after, limit)}</>
}

function ChangeSummary({ entry }: { entry: AuditLogEntry }) {
  const rows = changeRows(entry)
  if (rows.length === 0) return <span className="text-gray-400">—</span>
  return (
    <ul className="space-y-0.5 text-xs">
      {rows.slice(0, MAX_CHANGES_SHOWN).map((row) => (
        <li key={row.field} className="text-gray-600">
          <span className="font-medium text-gray-800">{fieldLabel(row.field)}</span>
          {': '}
          <ChangeValue row={row} />
        </li>
      ))}
      {rows.length > MAX_CHANGES_SHOWN && <li className="text-gray-400">and {rows.length - MAX_CHANGES_SHOWN} more</li>}
    </ul>
  )
}

const emptyFilters = { search: '', event: '', subject_type: '', causer_id: '', date_from: '', date_to: '' }

type FilterKey = keyof typeof emptyFilters

export function AuditLog() {
  usePageTitle('Audit Log')

  const [search, setSearch] = useState('')
  const [event, setEvent] = useState('')
  const [subjectType, setSubjectType] = useState('')
  const [causerId, setCauserId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [appliedFilters, setAppliedFilters] = useState(emptyFilters)
  // Fallback name for the "By" chip/picker when the shortcut's actor isn't in `options.actors`.
  const [causerName, setCauserName] = useState('')

  const { items: entries, meta, loading, error, page, setPage, perPage, setPerPage } = useServerTable(
    (params) => auditLogService.list(params),
    { filters: appliedFilters, waitForPerPage: true },
  )

  const [options, setOptions] = useState<AuditLogOptions>({ events: [], subject_types: [], actors: [] })
  const [optionsError, setOptionsError] = useState<string | null>(null)

  useEffect(() => {
    auditLogService
      .options()
      .then(setOptions)
      .catch((loadError: unknown) => setOptionsError(extractError(loadError, 'Unable to load the filter options.')))
  }, [])

  const draftFilters = { search, event, subject_type: subjectType, causer_id: causerId, date_from: dateFrom, date_to: dateTo }
  const dirty = JSON.stringify(draftFilters) !== JSON.stringify(appliedFilters)

  function syncDraft(next: typeof emptyFilters) {
    setSearch(next.search)
    setEvent(next.event)
    setSubjectType(next.subject_type)
    setCauserId(next.causer_id)
    setDateFrom(next.date_from)
    setDateTo(next.date_to)
  }

  function applyFilters() {
    setAppliedFilters(draftFilters)
  }

  function clearFilters() {
    syncDraft(emptyFilters)
    setAppliedFilters(emptyFilters)
  }

  function removeFilter(key: FilterKey) {
    const next = { ...appliedFilters, [key]: '' }
    setAppliedFilters(next)
    syncDraft(next)
  }

  const [selected, setSelected] = useState<AuditLogEntry | null>(null)

  function filterByActor(entry: AuditLogEntry) {
    if (entry.actor?.id == null) return
    const next = { ...appliedFilters, causer_id: String(entry.actor.id) }
    setCauserName(actorName(entry))
    setAppliedFilters(next)
    syncDraft(next)
    setSelected(null)
  }

  const subjectLabel = (fqcn: string) => options.subject_types.find((s) => s.value === fqcn)?.label ?? fqcn

  const actorPickerOptions = useMemo(() => {
    const list = options.actors.map((a) => ({ label: a.label, value: String(a.value) }))
    if (causerId && causerName && !list.some((o) => o.value === causerId)) list.push({ label: causerName, value: causerId })
    return list
  }, [options.actors, causerId, causerName])

  const actorLabel = (id: string) => options.actors.find((a) => String(a.value) === id)?.label ?? (causerName || `user #${id}`)

  const activeChips: FilterChip<FilterKey>[] = [
    { key: 'search' as const, label: `Search: ${appliedFilters.search}`, removable: true },
    { key: 'event' as const, label: humanize(appliedFilters.event), removable: true },
    { key: 'subject_type' as const, label: subjectLabel(appliedFilters.subject_type), removable: true },
    { key: 'causer_id' as const, label: `By ${actorLabel(appliedFilters.causer_id)}`, removable: true },
    { key: 'date_from' as const, label: `From ${appliedFilters.date_from}`, removable: true },
    { key: 'date_to' as const, label: `Until ${appliedFilters.date_to}`, removable: true },
  ].filter((chip) => appliedFilters[chip.key] !== '')

  const columns = useMemo<GridColumn<AuditLogEntry>[]>(
    () => [
      column.accessor('created_at', {
        header: 'When',
        enableSorting: false,
        enableHiding: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => {
          const { date, time } = formatDateTime(info.getValue())
          return (
            <div>
              <p className="text-gray-900">{date}</p>
              <p className="text-xs text-gray-500">{time}</p>
            </div>
          )
        },
      }),
      column.accessor('description', {
        header: 'Action',
        enableSorting: false,
        cell: ({ row }) => {
          const value = row.original.event
          return (
            <div className="space-y-1">
              {value ? <Badge color={eventColors[value] ?? 'gray'}>{humanize(value)}</Badge> : null}
              <p className="font-mono text-xs text-gray-500">{row.original.description}</p>
            </div>
          )
        },
      }),
      column.display({
        id: 'subject',
        header: 'Record',
        meta: { className: 'whitespace-nowrap' },
        cell: ({ row }) =>
          row.original.subject_type ? (
            <div>
              <p className="text-gray-900">{row.original.subject_type}</p>
              {row.original.subject_id !== null && <p className="text-xs text-gray-500">#{row.original.subject_id}</p>}
            </div>
          ) : (
            <span className="text-gray-400">—</span>
          ),
      }),
      column.display({
        id: 'changes',
        header: 'Changes',
        cell: ({ row }) => <ChangeSummary entry={row.original} />,
      }),
      column.display({
        id: 'actor',
        header: 'By',
        cell: ({ row }) => {
          const actor = row.original.actor
          if (!actor) return <span className="text-gray-500">System</span>
          return (
            <div className="min-w-0">
              <p className="truncate text-gray-900">{actor.name ?? actor.email ?? 'Unknown'}</p>
              {actor.name && actor.email && <p className="truncate text-xs text-gray-500">{actor.email}</p>}
            </div>
          )
        },
      }),
      column.accessor('log_name', {
        header: 'Log',
        enableSorting: false,
        meta: { defaultHidden: true },
        cell: (info) => info.getValue() ?? '—',
      }),
    ],
    [],
  )

  const selectedRows = selected ? changeRows(selected) : []
  const selectedProperties = selected
    ? Object.fromEntries(Object.entries(selected.properties ?? {}).filter(([key]) => key !== 'actor_snapshot' && key !== 'attributes' && key !== 'old'))
    : {}
  const hasProperties = Object.keys(selectedProperties).length > 0

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-xl font-bold text-gray-900">Audit Log</h1>
        <p className="text-sm text-gray-600">A read-only record of every change to equipment, personnel, ISP accounts, offices, users and roles, and who made it.</p>
      </div>

      {optionsError && (
        <Alert type="error" className="mb-4">
          {optionsError}
        </Alert>
      )}

      <div className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <div className="sm:col-span-2 lg:col-span-4">
            <FilterSearch label="Search by action or person" value={search} onChange={setSearch} onSubmit={applyFilters} />
          </div>
          <FilterSelect label="Action" value={event} onChange={setEvent} className="lg:col-span-4">
            <option value="">All actions</option>
            {options.events.map((value) => (
              <option key={value} value={value}>
                {humanize(value)}
              </option>
            ))}
          </FilterSelect>
          <FilterSelect label="Record type" value={subjectType} onChange={setSubjectType} className="lg:col-span-4">
            <option value="">All record types</option>
            {options.subject_types.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </FilterSelect>
          <label className="flex items-center gap-2 text-xs text-gray-500 lg:col-span-3">
            <span className="w-10 shrink-0">From</span>
            <input
              type="date"
              aria-label="From date"
              value={dateFrom}
              max={dateTo || undefined}
              onChange={(e) => setDateFrom(e.target.value)}
              className={filterFieldClass}
            />
          </label>
          <label className="flex items-center gap-2 text-xs text-gray-500 lg:col-span-3">
            <span className="w-10 shrink-0">Until</span>
            <input
              type="date"
              aria-label="Until date"
              value={dateTo}
              min={dateFrom || undefined}
              onChange={(e) => setDateTo(e.target.value)}
              className={filterFieldClass}
            />
          </label>
          <div className="sm:col-span-2 lg:col-span-6">
            <SelectPicker
              aria-label="Performed by"
              placeholder="Anyone"
              options={actorPickerOptions}
              selectedValue={causerId || null}
              onSelect={(option) => setCauserId(option ? option.value : '')}
            />
          </div>
        </div>

        <div className="mt-3 flex flex-col-reverse gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:items-center sm:justify-between">
          <FilterChips chips={activeChips} onRemove={removeFilter} />
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={clearFilters}
              size="sm"
              leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
              className="flex-1 sm:flex-none"
              disabled={!dirty && activeChips.length === 0}
            >
              Reset
            </Button>
            <Button onClick={applyFilters} size="sm" leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
              Apply Filters
            </Button>
          </div>
        </div>
      </div>

      <DataGrid
        id="audit-log"
        columns={columns}
        rows={entries}
        rowKey={(row) => row.id}
        loading={loading}
        error={error}
        meta={meta}
        page={page}
        onPageChange={setPage}
        perPage={perPage}
        onPerPageChange={setPerPage}
        rowHeights={{ comfortable: 61, compact: 49 }}
        emptyMessage="No activity matches these filters."
        onRowClick={setSelected}
      />

      <Dialog open={selected !== null} onOpenChange={(open) => !open && setSelected(null)}>
        <DialogContent size="lg">
          <DialogTitle>{selected?.event ? humanize(selected.event) : 'Activity'}</DialogTitle>
          {selected && (
            <>
              <DialogDescription>
                {(() => {
                  const { date, time } = formatDateTime(selected.created_at)
                  return `${date}, ${time}`
                })()}{' '}
                by {actorName(selected)}
                {selected.actor?.name && selected.actor.email ? ` (${selected.actor.email})` : ''}
                {selected.subject_type ? ` on ${selected.subject_type}${selected.subject_id !== null ? ` #${selected.subject_id}` : ''}` : ''}
              </DialogDescription>

              <p className="font-mono text-xs text-gray-500">{selected.description}</p>

              {selectedRows.length > 0 ? (
                <div className="overflow-x-auto rounded-lg border border-gray-200">
                  <table className="w-full text-left text-sm">
                    <caption className="sr-only">Changed fields</caption>
                    <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                      <tr>
                        <th scope="col" className="px-3 py-2 font-medium">Field</th>
                        <th scope="col" className="px-3 py-2 font-medium">Before</th>
                        <th scope="col" className="px-3 py-2 font-medium">After</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {selectedRows.map((row) => (
                        <tr key={row.field}>
                          <th scope="row" className="px-3 py-2 font-medium capitalize text-gray-800">
                            {fieldLabel(row.field)}
                          </th>
                          <td className="break-all px-3 py-2 text-gray-500">{row.hasBefore ? formatValue(row.before, 0) : '—'}</td>
                          <td className="break-all px-3 py-2 text-gray-900">{row.hasAfter ? formatValue(row.after, 0) : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-gray-500">No field changes were recorded for this entry.</p>
              )}

              {hasProperties && (
                <div>
                  <p className="mb-1 text-sm font-medium text-gray-700">Details</p>
                  <pre className="max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800">{JSON.stringify(selectedProperties, null, 2)}</pre>
                </div>
              )}

              <DialogFooter>
                {selected.actor?.id != null && (
                  <Button variant="outline" leftIcon={<UserSearch className="h-4 w-4" />} onClick={() => filterByActor(selected)}>
                    Show all activity by {actorName(selected)}
                  </Button>
                )}
                <Button onClick={() => setSelected(null)}>Close</Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
