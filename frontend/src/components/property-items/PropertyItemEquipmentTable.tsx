import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'

import { Badge } from '@/components/ui/Badge'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { useAuth } from '@/context/AuthContext'
import { useServerTable } from '@/hooks/useServerTable'
import { propertyItemService } from '@/services/propertyItemService'
import type { PropertyItemEquipment, SchoolPropertyFilters } from '@/types/property-item'

const column = gridColumnHelper<PropertyItemEquipment>()

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const empty = <span className="text-gray-400">—</span>

/** The items on hand behind a School Property office row; edited in Equipment, not here. */
export function PropertyItemEquipmentTable({ filters, showOffice }: { filters: SchoolPropertyFilters; showOffice: boolean }) {
  const navigate = useNavigate()
  const { can } = useAuth()
  const canOpen = can('equipment.view')

  const { items, meta, loading, error, page, setPage, perPage, setPerPage } = useServerTable((params) => propertyItemService.equipment(params), {
    filters,
    waitForPerPage: true,
  })

  const columns = useMemo<GridColumn<PropertyItemEquipment>[]>(
    () => [
      column.accessor('article', {
        header: 'Article',
        enableHiding: false,
        enableSorting: false,
        cell: ({ row }) => (
          <div>
            <span className="font-medium text-gray-900">{row.original.article ?? '—'}</span>
            {row.original.description && <p className="text-xs text-gray-500">{row.original.description}</p>}
          </div>
        ),
      }),
      ...(showOffice ? [column.accessor('office', { header: 'Office', enableSorting: false, cell: (info) => info.getValue() ?? empty })] : []),
      column.accessor('property_no', {
        header: 'Property no.',
        enableSorting: false,
        cell: (info) => (info.getValue() ? <span className="font-mono text-xs">{info.getValue()}</span> : empty),
      }),
      column.accessor('semi_expendable', {
        header: 'RPCPPE sheet',
        enableSorting: false,
        cell: (info) => <Badge color={info.getValue() ? 'gray' : 'primary'}>{info.getValue() ? 'IT Semi-Expendable' : 'IT Equipment'}</Badge>,
      }),
      column.accessor('on_hand', {
        header: 'On hand',
        enableSorting: false,
        meta: { align: 'right', className: 'tabular-nums' },
        cell: ({ row }) => (
          <span className={row.original.on_hand === 0 ? 'font-medium text-error-600' : undefined} title={row.original.on_hand === 0 ? (row.original.disposition_status ?? undefined) : undefined}>
            {row.original.on_hand}
          </span>
        ),
      }),
      column.accessor('unit_value', {
        header: 'Unit value',
        enableSorting: false,
        meta: { align: 'right', className: 'whitespace-nowrap tabular-nums' },
        cell: (info) => (info.getValue() === null ? '—' : currency.format(info.getValue() as number)),
      }),
      column.accessor('accountable_officer', { header: 'Accountable officer', enableSorting: false, cell: (info) => info.getValue() ?? empty }),
      column.accessor('end_user', { header: 'End user', enableSorting: false, meta: { defaultHidden: true }, cell: (info) => info.getValue() ?? empty }),
      column.accessor('condition', { header: 'Condition', enableSorting: false, cell: (info) => info.getValue() ?? empty }),
    ],
    [showOffice],
  )

  return (
    <DataGrid
      id="school-property-equipment"
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
      emptyMessage="No items on hand for these filters."
      onRowClick={canOpen ? (row) => navigate(`/equipment/${row.id}/edit`) : undefined}
    />
  )
}
