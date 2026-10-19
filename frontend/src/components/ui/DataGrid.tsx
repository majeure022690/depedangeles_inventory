import {
  columnVisibilityFeature,
  createColumnHelper,
  rowSortingFeature,
  tableFeatures,
  useTable,
  type CellData,
  type ColumnDef,
  type ColumnVisibilityState,
  type RowData,
  type TableFeatures,
} from '@tanstack/react-table'
import {
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  ChevronsUpDown,
  ChevronDown,
  ChevronUp,
  Columns3,
  Rows3,
  Rows4,
} from 'lucide-react'
import { useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'

import { Alert } from '@/components/ui/Alert'
import { cn } from '@/lib/utils'
import type { PaginationMeta } from '@/types/api'

declare module '@tanstack/table-core' {
  interface ColumnMeta<in out TFeatures extends TableFeatures, in out TData extends RowData, TValue extends CellData = CellData> {
    align?: 'left' | 'center' | 'right'
    className?: string
    defaultHidden?: boolean
    mobileHidden?: boolean
  }
}

export const gridFeatures = tableFeatures({ columnVisibilityFeature, rowSortingFeature })

export type GridColumn<T extends RowData> = ColumnDef<typeof gridFeatures, T, any>

export function gridColumnHelper<T extends RowData>() {
  return createColumnHelper<typeof gridFeatures, T>()
}

type Density = 'comfortable' | 'compact'

type PageSizeMode = 'auto' | number

const PAGE_SIZES = [10, 20, 50, 100]

const DESKTOP_QUERY = '(min-width: 768px)'

const MOBILE_PAGE_SIZE = 10

const MIN_AUTO_ROWS = 5

const MAX_AUTO_ROWS = 100

const ROW_HEIGHT_ESTIMATE: Record<Density, number> = { comfortable: 57, compact: 37 }

const FOOTER_HEIGHT_ESTIMATE = 58

const PAGE_BOTTOM_GAP = 26

function readStored<V>(key: string, fallback: V): V {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as V) : fallback
  } catch {
    return fallback
  }
}

function writeStored(key: string, value: unknown) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    return
  }
}

function pageWindow(page: number, last: number): (number | 'gap')[] {
  const pages = new Set([1, last, page - 1, page, page + 1].filter((p) => p >= 1 && p <= last))
  const sorted = [...pages].sort((a, b) => a - b)
  return sorted.flatMap((p, i) => (i > 0 && p - sorted[i - 1] > 1 ? ['gap' as const, p] : [p]))
}

interface DataGridProps<T extends RowData> {
  id: string
  columns: GridColumn<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  loading?: boolean
  error?: string | null
  meta?: PaginationMeta | null
  page: number
  onPageChange: (page: number) => void
  perPage?: number | null
  onPerPageChange?: (perPage: number) => void
  sortBy?: string | null
  sortDir?: 'asc' | 'desc'
  onSortChange?: (key: string) => void
  emptyMessage?: string
  onRowClick?: (row: T) => void
  toolbar?: ReactNode
  rowHeights?: Partial<Record<Density, number>>
}

export function DataGrid<T extends RowData>({
  id,
  columns,
  rows,
  rowKey,
  loading = false,
  error,
  meta,
  page,
  onPageChange,
  perPage,
  onPerPageChange,
  sortBy,
  sortDir = 'asc',
  onSortChange,
  emptyMessage = 'No records found.',
  onRowClick,
  toolbar,
  rowHeights,
}: DataGridProps<T>) {
  const storageKey = `dictis.grid.${id}`
  const defaultVisibility = useMemo(
    () =>
      Object.fromEntries(
        columns.filter((c) => c.meta?.defaultHidden).map((c) => [String(c.id ?? ('accessorKey' in c ? c.accessorKey : '')), false]),
      ) as ColumnVisibilityState,
    [columns],
  )
  const [columnVisibility, setColumnVisibility] = useState<ColumnVisibilityState>(() =>
    readStored(`${storageKey}.columns`, defaultVisibility),
  )
  const [density, setDensity] = useState<Density>(() => readStored(`${storageKey}.density`, 'comfortable'))

  const [pageSizeMode, setPageSizeMode] = useState<PageSizeMode>(() => readStored(`${storageKey}.pageSize`, 'auto'))
  const bodyRef = useRef<HTMLTableSectionElement>(null)
  const footerRef = useRef<HTMLDivElement>(null)
  const measuredRows = useRef(false)

  useEffect(() => writeStored(`${storageKey}.columns`, columnVisibility), [storageKey, columnVisibility])
  useEffect(() => writeStored(`${storageKey}.density`, density), [storageKey, density])
  useEffect(() => writeStored(`${storageKey}.pageSize`, pageSizeMode), [storageKey, pageSizeMode])

  const fitRows = useRef(() => {})
  fitRows.current = () => {
    if (!onPerPageChange) return
    if (pageSizeMode !== 'auto') {
      if (pageSizeMode !== perPage) onPerPageChange(pageSizeMode)
      return
    }
    const body = bodyRef.current
    const target =
      !body || !window.matchMedia(DESKTOP_QUERY).matches
        ? MOBILE_PAGE_SIZE
        : (() => {
            const dataRows = Array.from(body.rows).filter((row) => row.dataset.row === 'data')
            const heightKey = `${storageKey}.rowHeight.${density}`
            const measured = dataRows.length ? dataRows.reduce((sum, row) => sum + row.offsetHeight, 0) / dataRows.length : null
            if (measured) writeStored(heightKey, Math.round(measured * 10) / 10)
            const rowHeight = measured ?? readStored<number | null>(heightKey, null) ?? rowHeights?.[density] ?? ROW_HEIGHT_ESTIMATE[density]
            const top = body.getBoundingClientRect().top + window.scrollY
            const footer = footerRef.current?.offsetHeight ?? FOOTER_HEIGHT_ESTIMATE
            const available = window.innerHeight - top - footer - PAGE_BOTTOM_GAP
            return Math.min(MAX_AUTO_ROWS, Math.max(MIN_AUTO_ROWS, Math.floor(available / rowHeight)))
          })()
    if (target !== perPage) onPerPageChange(target)
  }

  useLayoutEffect(() => {
    fitRows.current()
  }, [pageSizeMode, density])

  useEffect(() => {
    if (measuredRows.current || rows.length === 0) return
    measuredRows.current = true
    fitRows.current()
  }, [rows])

  useEffect(() => {
    let timer: number | undefined
    function onResize() {
      window.clearTimeout(timer)
      timer = window.setTimeout(() => fitRows.current(), 200)
    }
    window.addEventListener('resize', onResize)
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('resize', onResize)
    }
  }, [])

  const sorting = useMemo(() => (sortBy ? [{ id: sortBy, desc: sortDir === 'desc' }] : []), [sortBy, sortDir])

  const table = useTable({
    features: gridFeatures,
    columns,
    data: rows,
    getRowId: (row) => String(rowKey(row)),
    manualSorting: true,
    state: { sorting, columnVisibility },
    onColumnVisibilityChange: setColumnVisibility,
  })

  const visibleColumns = table.getVisibleLeafColumns()
  const hideableColumns = table.getAllLeafColumns().filter((column) => column.getCanHide() && typeof column.columnDef.header === 'string' && column.columnDef.header !== '')
  const lastPage = meta?.last_page ?? 1
  const total = meta?.total ?? rows.length
  const cellPadding = density === 'compact' ? 'px-3 py-1.5' : 'px-4 py-3'
  const showSkeleton = loading && rows.length === 0
  const hasFooter = !!meta && rows.length > 0

  // No overflow-hidden here: it would clip the Columns menu when the grid is shorter than the menu.
  return (
    <div className="relative w-full rounded-lg border border-gray-200 bg-white shadow-sm">
      {loading && (
        <div className="absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden rounded-t-lg bg-primary-100">
          <div className="h-full w-1/3 animate-indeterminate bg-primary-600" />
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-3 py-2">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-xs text-gray-500">
          {toolbar ?? (
            <span>
              {meta ? (
                <>
                  <span className="font-semibold text-gray-900">{total.toLocaleString()}</span> record{total === 1 ? '' : 's'}
                </>
              ) : (
                ' '
              )}
            </span>
          )}
        </div>
        <div className="flex items-center gap-1">
          <ColumnMenu
            columns={hideableColumns.map((column) => ({
              id: column.id,
              label: column.columnDef.header as string,
              visible: column.getIsVisible(),
              toggle: () => column.toggleVisibility(),
            }))}
            onReset={() => setColumnVisibility(defaultVisibility)}
          />
          <button
            type="button"
            onClick={() => setDensity((d) => (d === 'compact' ? 'comfortable' : 'compact'))}
            className="hidden h-8 items-center gap-1.5 rounded-md border border-gray-300 px-2.5 text-xs font-medium text-gray-700 hover:bg-gray-50 md:inline-flex"
            title={density === 'compact' ? 'Comfortable rows' : 'Compact rows'}
          >
            {density === 'compact' ? <Rows3 className="h-3.5 w-3.5" /> : <Rows4 className="h-3.5 w-3.5" />}
            {density === 'compact' ? 'Comfortable' : 'Compact'}
          </button>
        </div>
      </div>

      <div className={cn('hidden overflow-x-auto md:block', !hasFooter && 'rounded-b-lg')}>
        <table className="w-full text-left text-sm">
          <thead className="border-b border-gray-200 bg-gray-50">
            {table.getHeaderGroups().map((group) => (
              <tr key={group.id}>
                {group.headers.map((header) => {
                  const column = header.column
                  const canSort = column.getCanSort() && !!onSortChange
                  const sorted = column.getIsSorted()
                  const align = column.columnDef.meta?.align
                  return (
                    <th
                      key={header.id}
                      scope="col"
                      aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}
                      className={cn(
                        'whitespace-nowrap bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500',
                        cellPadding,
                        align === 'center' && 'text-center',
                        align === 'right' && 'text-right',
                      )}
                    >
                      {header.isPlaceholder ? null : canSort ? (
                        <button
                          type="button"
                          onClick={() => onSortChange?.(column.id)}
                          className={cn('inline-flex items-center gap-1 uppercase hover:text-gray-900', sorted && 'text-primary-700')}
                        >
                          <table.FlexRender header={header} />
                          {sorted === 'asc' ? (
                            <ChevronUp className="h-3.5 w-3.5" />
                          ) : sorted === 'desc' ? (
                            <ChevronDown className="h-3.5 w-3.5" />
                          ) : (
                            <ChevronsUpDown className="h-3.5 w-3.5 text-gray-400" />
                          )}
                        </button>
                      ) : (
                        <table.FlexRender header={header} />
                      )}
                    </th>
                  )
                })}
              </tr>
            ))}
          </thead>
          <tbody ref={bodyRef} className={cn('divide-y divide-gray-100 transition-opacity', loading && rows.length > 0 && 'opacity-60')}>
            {error ? (
              <tr>
                <td colSpan={visibleColumns.length} className="px-4 py-6">
                  <Alert type="error">{error}</Alert>
                </td>
              </tr>
            ) : showSkeleton ? (
              Array.from({ length: Math.min(perPage ?? 10, 8) }, (_, i) => (
                <tr key={`skeleton-${i}`}>
                  {visibleColumns.map((column) => (
                    <td key={column.id} className={cellPadding}>
                      <div className="h-4 animate-pulse rounded bg-gray-100" />
                    </td>
                  ))}
                </tr>
              ))
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={visibleColumns.length} className="px-4 py-10 text-center text-gray-500">
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr
                  key={row.id}
                  data-row="data"
                  className={cn('transition-colors even:bg-gray-50/60 hover:bg-primary-50/50', onRowClick && 'cursor-pointer')}
                  onClick={() => onRowClick?.(row.original)}
                >
                  {row.getVisibleCells().map((cell) => {
                    const cellMeta = cell.column.columnDef.meta
                    return (
                      <td
                        key={cell.id}
                        className={cn(
                          'text-gray-700',
                          cellPadding,
                          cellMeta?.align === 'center' && 'text-center',
                          cellMeta?.align === 'right' && 'text-right',
                          cellMeta?.className,
                        )}
                      >
                        <table.FlexRender cell={cell} />
                      </td>
                    )
                  })}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className={cn('md:hidden', !hasFooter && 'overflow-hidden rounded-b-lg')}>
        {error ? (
          <div className="p-4">
            <Alert type="error">{error}</Alert>
          </div>
        ) : showSkeleton ? (
          <div className="space-y-3 p-4">
            {Array.from({ length: 3 }, (_, i) => (
              <div key={i} className="h-20 animate-pulse rounded-lg bg-gray-100" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-10 text-center text-gray-500">{emptyMessage}</div>
        ) : (
          <div className="divide-y divide-gray-100">
            {table.getRowModel().rows.map((row) => {
              const cells = row.getVisibleCells().filter((cell) => !cell.column.columnDef.meta?.mobileHidden)
              const fields = cells.filter((cell) => cell.column.columnDef.header !== '')
              const actions = cells.filter((cell) => cell.column.columnDef.header === '')
              return (
                <div
                  key={row.id}
                  className={cn('space-y-3 p-4', onRowClick && 'cursor-pointer active:bg-gray-50')}
                  onClick={() => onRowClick?.(row.original)}
                >
                  <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                    {fields.map((cell) => (
                      <div key={cell.id} className="min-w-0">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{cell.column.columnDef.header as string}</div>
                        <div className="mt-0.5 text-sm text-gray-900 [overflow-wrap:anywhere]">
                          <table.FlexRender cell={cell} />
                        </div>
                      </div>
                    ))}
                  </div>
                  {actions.length > 0 && (
                    <div className="flex justify-end gap-1 border-t border-gray-100 pt-3">
                      {actions.map((cell) => (
                        <table.FlexRender key={cell.id} cell={cell} />
                      ))}
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </div>

      {hasFooter && (
        <div ref={footerRef} className="flex flex-col items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 lg:flex-row">
          <div className="flex flex-wrap items-center justify-center gap-3 text-xs text-gray-500">
            <span>
              Showing <span className="font-medium text-gray-900">{meta.from ?? 0}</span>–<span className="font-medium text-gray-900">{meta.to ?? 0}</span> of{' '}
              <span className="font-medium text-gray-900">{total.toLocaleString()}</span>
            </span>
            <span>
              Page <span className="font-medium text-gray-900">{page}</span> of <span className="font-medium text-gray-900">{lastPage}</span>
            </span>
            {onPerPageChange && perPage && (
              <label className="inline-flex items-center gap-1.5">
                Rows per page
                <select
                  value={pageSizeMode}
                  onChange={(e) => setPageSizeMode(e.target.value === 'auto' ? 'auto' : Number(e.target.value))}
                  className="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 focus:border-primary-500 focus:outline-none"
                >
                  <option value="auto">Fit to screen ({pageSizeMode === 'auto' ? perPage : 'auto'})</option>
                  {PAGE_SIZES.map((size) => (
                    <option key={size} value={size}>{size}</option>
                  ))}
                </select>
              </label>
            )}
          </div>
          <nav className="flex items-center gap-1" aria-label="Pagination">
            <PageButton label="First page" disabled={page <= 1} onClick={() => onPageChange(1)}>
              <ChevronsLeft className="h-4 w-4" />
            </PageButton>
            <PageButton label="Previous page" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
              <ChevronLeft className="h-4 w-4" />
            </PageButton>
            {pageWindow(page, lastPage).map((item, i) =>
              item === 'gap' ? (
                <span key={`gap-${i}`} className="px-1 text-sm text-gray-400">…</span>
              ) : (
                <button
                  key={item}
                  type="button"
                  onClick={() => onPageChange(item)}
                  aria-current={item === page ? 'page' : undefined}
                  className={cn(
                    'hidden h-8 min-w-8 rounded-md px-2 text-sm sm:inline-block',
                    item === page ? 'bg-primary-600 font-semibold text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50',
                  )}
                >
                  {item}
                </button>
              ),
            )}
            <span className="px-2 text-sm text-gray-600 sm:hidden">
              {page} / {lastPage}
            </span>
            <PageButton label="Next page" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>
              <ChevronRight className="h-4 w-4" />
            </PageButton>
            <PageButton label="Last page" disabled={page >= lastPage} onClick={() => onPageChange(lastPage)}>
              <ChevronsRight className="h-4 w-4" />
            </PageButton>
          </nav>
        </div>
      )}
    </div>
  )
}

function PageButton({ label, disabled, onClick, children }: { label: string; disabled: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
    >
      {children}
    </button>
  )
}

interface ColumnToggle {
  id: string
  label: string
  visible: boolean
  toggle: () => void
}

function ColumnMenu({ columns, onReset }: { columns: ColumnToggle[]; onReset: () => void }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    function close(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    function escape(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', escape)
    }
  }, [open])

  if (columns.length === 0) return null
  const hidden = columns.filter((c) => !c.visible).length

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="inline-flex h-8 items-center gap-1.5 rounded-md border border-gray-300 px-2.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
      >
        <Columns3 className="h-3.5 w-3.5" />
        Columns
        {hidden > 0 && <span className="rounded-full bg-gray-100 px-1.5 text-[10px] text-gray-600">{hidden} hidden</span>}
      </button>
      {open && (
        <div className="absolute right-0 z-30 mt-1 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
          <p className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Show columns</p>
          {columns.map((column) => (
            <label key={column.id} className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
              <input type="checkbox" className="h-4 w-4 rounded border-gray-300 text-primary-600" checked={column.visible} onChange={column.toggle} />
              {column.label}
            </label>
          ))}
          <div className="mt-1 border-t border-gray-100 px-3 pt-1.5">
            <button type="button" onClick={onReset} className="text-xs font-medium text-primary-700 hover:underline">
              Reset to default
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
