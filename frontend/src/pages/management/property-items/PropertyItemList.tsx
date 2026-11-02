import { FileSpreadsheet, Filter, RotateCcw } from 'lucide-react'
import { useEffect, useState } from 'react'

import { PropertyItemEquipmentTable } from '@/components/property-items/PropertyItemEquipmentTable'
import { RpcppeDownloadDialog } from '@/components/property-items/RpcppeDownloadDialog'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/Dialog'
import { FilterChips, FilterSearch, FilterSelect, type FilterChip } from '@/components/ui/FilterControls'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { propertyItemService } from '@/services/propertyItemService'
import type { Option } from '@/types/lookup'
import type { SchoolPropertyFilters, SchoolPropertyOfficeRow, SchoolPropertyOptions, SchoolPropertySummary } from '@/types/property-item'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const EMPTY_FILTERS: SchoolPropertyFilters = {
  search: '',
  office_id: '',
  item_type_id: '',
  brand_id: '',
  functional: '',
  equipment_condition_id: '',
  disposition_status: '',
  estimated_useful_life: '',
  transaction_type: '',
  accountable_officer_id: '',
  received_by_id: '',
}

type FilterKey = keyof SchoolPropertyFilters

const FUNCTIONAL_OPTIONS: Option<string>[] = [
  { value: 'functional', label: 'Functional' },
  { value: 'non_functional', label: 'Non-functional' },
]

/** Select filters in display order, each with its "any" text and the option list it reads. */
const SELECT_FILTERS: { key: Exclude<FilterKey, 'search' | 'office_id'>; label: string; any: string; options: (o: SchoolPropertyOptions, draft: SchoolPropertyFilters) => Option[] }[] = [
  { key: 'item_type_id', label: 'Item type', any: 'All item types', options: (o) => o.item_type },
  { key: 'brand_id', label: 'Brand / manufacturer', any: 'All brands', options: (o) => o.brand },
  { key: 'functional', label: 'Status', any: 'Functional and non-functional', options: () => FUNCTIONAL_OPTIONS },
  { key: 'equipment_condition_id', label: 'Condition', any: 'All conditions', options: (o) => o.equipment_condition },
  { key: 'disposition_status', label: 'Disposition status', any: 'All disposition statuses', options: (o) => o.disposition_status },
  { key: 'estimated_useful_life', label: 'Estimated useful life', any: 'Any useful life', options: (o) => o.estimated_useful_life },
  { key: 'transaction_type', label: 'Latest transaction type', any: 'All transaction types', options: (o) => o.transaction_type },
  {
    key: 'accountable_officer_id',
    label: 'Accountable officer',
    any: 'All accountable officers',
    options: (o, draft) => (draft.office_id === '' ? o.accountable_officer : o.accountable_officer.filter((officer) => String(officer.office_id) === draft.office_id)),
  },
  { key: 'received_by_id', label: 'Received by (latest transaction)', any: 'Anyone', options: (o) => o.received_by },
]

export function PropertyItemList() {
  usePageTitle('School Property')
  const { can, user } = useAuth()
  const showOffice = hasAllScope(user)
  const [rpcppeOpen, setRpcppeOpen] = useState(false)

  const [options, setOptions] = useState<SchoolPropertyOptions | null>(null)
  useEffect(() => {
    propertyItemService
      .options()
      .then(setOptions)
      .catch(() => setOptions(null))
  }, [])

  const [draft, setDraft] = useState<SchoolPropertyFilters>(EMPTY_FILTERS)
  const [applied, setApplied] = useState<SchoolPropertyFilters>(EMPTY_FILTERS)
  const dirty = JSON.stringify(draft) !== JSON.stringify(applied)

  const [summary, setSummary] = useState<SchoolPropertySummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    let cancelled = false
    propertyItemService
      .summary(applied)
      .then((data) => {
        if (!cancelled) {
          setSummary(data)
          setError(null)
        }
      })
      .catch((loadError: unknown) => {
        if (!cancelled) setError(extractError(loadError, 'Unable to load school property.'))
      })
    return () => {
      cancelled = true
    }
  }, [applied])

  const [breakdown, setBreakdown] = useState<SchoolPropertyOfficeRow | null>(null)

  const labelOf = (key: FilterKey, value: string): string => {
    if (key === 'search') return `Search: ${value}`
    if (key === 'office_id') return options?.office.find((o) => String(o.value) === value)?.label ?? 'Office'
    const filter = SELECT_FILTERS.find((f) => f.key === key)
    const option = filter && options ? filter.options(options, EMPTY_FILTERS).find((o) => String(o.value) === value) : undefined
    return `${filter?.label ?? key}: ${option?.label ?? value}`
  }

  const chips: FilterChip<FilterKey>[] = (Object.keys(applied) as FilterKey[])
    .filter((key) => applied[key] !== '')
    .map((key) => ({ key, label: labelOf(key, applied[key]), removable: true }))

  function apply(next: SchoolPropertyFilters) {
    setSummary(null)
    setApplied(next)
  }

  function removeFilter(key: FilterKey) {
    const next = { ...applied, [key]: '' }
    setDraft(next)
    apply(next)
  }

  function resetFilters() {
    setDraft(EMPTY_FILTERS)
    apply(EMPTY_FILTERS)
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">School Property</h1>
          <p className="text-sm text-gray-600">
            Equipment on hand and its total cost per office, from the Equipment module. An item belongs to its accountable officer&rsquo;s office.
          </p>
        </div>
        {can('reports.rpcppe') && (
          <Button variant="outline" size="sm" leftIcon={<FileSpreadsheet className="h-4 w-4 text-green-600" />} onClick={() => setRpcppeOpen(true)}>
            Download RPCPPE
          </Button>
        )}
      </div>

      <form
        className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        role="search"
        aria-label="Filter school property"
        onSubmit={(event) => {
          event.preventDefault()
          apply(draft)
        }}
      >
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <FilterSearch
            label="Search by property no., serial no., model or person..."
            value={draft.search}
            onChange={(search) => setDraft((d) => ({ ...d, search }))}
            onSubmit={() => apply(draft)}
            className={showOffice ? 'sm:col-span-2 lg:col-span-8' : 'sm:col-span-2 lg:col-span-12'}
          />
          {showOffice && (
            <FilterSelect
              label="Office"
              value={draft.office_id}
              onChange={(office_id) =>
                setDraft((d) => {
                  const keeps = d.accountable_officer_id === '' || options?.accountable_officer.some((officer) => String(officer.value) === d.accountable_officer_id && (office_id === '' || String(officer.office_id) === office_id))
                  return { ...d, office_id, accountable_officer_id: keeps ? d.accountable_officer_id : '' }
                })
              } className="lg:col-span-4">
              <option value="">All offices</option>
              {options?.office.map((office) => (
                <option key={office.value} value={office.value}>
                  {office.label}
                </option>
              ))}
            </FilterSelect>
          )}
          {SELECT_FILTERS.map((filter) => (
            <FilterSelect
              key={filter.key}
              label={filter.label}
              value={draft[filter.key]}
              disabled={!options}
              onChange={(value) => setDraft((d) => ({ ...d, [filter.key]: value }))}
              className="lg:col-span-4"
            >
              <option value="">{filter.any}</option>
              {options &&
                filter.options(options, draft).map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
            </FilterSelect>
          ))}
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

      {error && (
        <Alert type="error" className="mb-4">
          <span role="alert">{error}</span>
        </Alert>
      )}

      <Card>
        <CardContent className="overflow-x-auto p-0">
          <table className="w-full min-w-[36rem] text-sm">
            <caption className="sr-only">Equipment on hand and total cost per office</caption>
            <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
              <tr>
                <th scope="col" className="px-4 py-3">
                  Office
                </th>
                <th scope="col" className="px-4 py-3 text-right">
                  On hand
                </th>
                <th scope="col" className="px-4 py-3 text-right">
                  Lost / stolen
                </th>
                <th scope="col" className="px-4 py-3 text-right">
                  Total cost
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {!summary &&
                !error &&
                Array.from({ length: 4 }, (_, index) => (
                  <tr key={index}>
                    <td colSpan={4} className="px-4 py-3">
                      <Skeleton className="h-5 w-full" />
                    </td>
                  </tr>
                ))}
              {summary?.offices.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-4 py-10 text-center text-gray-500">
                    No equipment matches these filters.
                  </td>
                </tr>
              )}
              {summary?.offices.map((row) => (
                <tr key={row.office_id ?? 'none'} className="even:bg-gray-50/60">
                  <th scope="row" className="px-4 py-3 text-left font-medium text-gray-900">
                    {row.office_name ?? <span className="font-normal italic text-gray-500">No office assigned</span>}
                  </th>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {row.office_id !== null && row.on_hand > 0 ? (
                      <button
                        type="button"
                        onClick={() => setBreakdown(row)}
                        className="font-semibold text-primary-700 underline-offset-2 hover:underline focus-visible:underline"
                        aria-label={`Show the ${row.on_hand} items on hand at ${row.office_name ?? 'this office'}`}
                      >
                        {row.on_hand}
                      </button>
                    ) : (
                      row.on_hand
                    )}
                  </td>
                  <td className={`px-4 py-3 text-right tabular-nums ${row.missing > 0 ? 'font-medium text-error-600' : 'text-gray-500'}`}>{row.missing}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{currency.format(row.total_cost)}</td>
                </tr>
              ))}
            </tbody>
            {summary && summary.offices.length > 0 && (
              <tfoot className="border-t-2 border-gray-200 bg-gray-50 font-semibold text-gray-900">
                <tr>
                  <th scope="row" className="px-4 py-3 text-left">
                    Total
                  </th>
                  <td className="px-4 py-3 text-right tabular-nums">{summary.totals.on_hand}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{summary.totals.missing}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{currency.format(summary.totals.total_cost)}</td>
                </tr>
              </tfoot>
            )}
          </table>
        </CardContent>
      </Card>

      <Dialog open={breakdown !== null} onOpenChange={(open) => !open && setBreakdown(null)}>
        <DialogContent size="full">
          <DialogTitle>{breakdown?.office_name} &middot; items on hand</DialogTitle>
          <DialogDescription>
            {breakdown ? `${breakdown.on_hand} item(s) worth ${currency.format(breakdown.total_cost)}, under the filters applied on the page.` : ''}
          </DialogDescription>
          <div className="mt-4">
            {breakdown && breakdown.office_id !== null && (
              <PropertyItemEquipmentTable filters={{ ...applied, office_id: String(breakdown.office_id) }} showOffice={false} />
            )}
          </div>
        </DialogContent>
      </Dialog>

      <RpcppeDownloadDialog open={rpcppeOpen} onOpenChange={setRpcppeOpen} />
    </div>
  )
}
