import { ArrowUpRight } from 'lucide-react'
import { Link } from 'react-router-dom'

import { DashboardSectionHeading } from '@/components/dashboard/DashboardSectionHeading'
import { equipmentGroupStyle } from '@/components/dashboard/equipmentGroupStyles'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { equipmentListUrlForGroup, equipmentListUrlForItemTypes } from '@/lib/dashboardGroups'
import { cn } from '@/lib/utils'
import type { DashboardEquipmentBreakdown, DashboardEquipmentOverview } from '@/types/dashboard'

function GroupTile({
  groupKey,
  label,
  count,
  breakdown,
  linked,
}: {
  groupKey: string
  label: string
  count: number
  breakdown: DashboardEquipmentBreakdown[]
  linked: boolean
}) {
  const style = equipmentGroupStyle(groupKey)
  const Icon = style.icon
  const headingId = `equipment-group-${groupKey}`
  const rows = breakdown.length > 0
    ? breakdown.map((row) => ({ ...row, to: row.item_type_ids.length > 0 ? equipmentListUrlForItemTypes(row.item_type_ids) : null }))
    : [{ label, count, to: equipmentListUrlForGroup(groupKey) }]

  return (
    <li className="flex overflow-hidden rounded-lg border border-gray-200 bg-white">
      <article aria-labelledby={headingId} className="flex flex-1 flex-col">
        <div className={cn('flex items-center gap-3 px-3 py-2.5', style.tile)}>
          <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', style.badge)} aria-hidden="true">
            <Icon className="h-[1.125rem] w-[1.125rem]" />
          </span>
          <div className="min-w-0 flex-1">
            <h3 id={headingId} className="truncate text-xs font-semibold text-gray-700" title={label}>
              {label}
            </h3>
            <p className="text-xl font-bold leading-tight tabular-nums text-gray-900">{count.toLocaleString()}</p>
          </div>
          {linked && (
            <Link
              to={equipmentListUrlForGroup(groupKey)}
              className="rounded-md p-1 text-gray-500 hover:bg-white/70 hover:text-primary-700 focus-visible:text-primary-700"
              title={`View ${label} items`}
            >
              <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
              <span className="sr-only">View {label} items</span>
            </Link>
          )}
        </div>

        <ul className="flex-1 px-1.5 py-1.5 text-xs">
          {rows.map((row) => {
            const content = (
              <>
                <span className="truncate text-gray-600 group-hover:text-primary-700" title={row.label}>
                  {row.label}
                </span>
                <span className="font-medium tabular-nums text-gray-900 group-hover:text-primary-700">{row.count.toLocaleString()}</span>
              </>
            )
            return (
              <li key={row.label}>
                {linked && row.to ? (
                  <Link
                    to={row.to}
                    className="group flex items-center justify-between gap-3 rounded px-1.5 py-0.5 hover:bg-primary-50 focus-visible:bg-primary-50"
                    aria-label={`${row.label}: ${row.count.toLocaleString()}. View items`}
                  >
                    {content}
                  </Link>
                ) : (
                  <div className="flex items-center justify-between gap-3 px-1.5 py-0.5">{content}</div>
                )}
              </li>
            )
          })}
        </ul>
      </article>
    </li>
  )
}

export function EquipmentOverviewSection({ overview, canViewEquipment }: { overview: DashboardEquipmentOverview; canViewEquipment: boolean }) {
  return (
    <Card className="h-full">
      <CardHeader className="px-4 py-3">
        <DashboardSectionHeading id="equipment-overview-heading" title="ICT Equipment Overview" description="Total count of ICT equipment by category" />
      </CardHeader>
      <CardContent className="p-4">
        <ul className="grid auto-rows-fr gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {overview.groups.map((group) => (
            <GroupTile key={group.key} groupKey={group.key} label={group.label} count={group.count} breakdown={group.breakdown} linked={canViewEquipment} />
          ))}
          <GroupTile groupKey="other" label="Other equipment" count={overview.other.count} breakdown={[]} linked={canViewEquipment} />
        </ul>
      </CardContent>
    </Card>
  )
}
