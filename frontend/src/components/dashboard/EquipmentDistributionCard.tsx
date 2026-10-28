import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'

import { DashboardSectionHeading } from '@/components/dashboard/DashboardSectionHeading'
import { equipmentGroupStyle } from '@/components/dashboard/equipmentGroupStyles'
import { formatPercentage } from '@/components/dashboard/format'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import type { DashboardEquipmentDistribution } from '@/types/dashboard'

function chartSummary(distribution: DashboardEquipmentDistribution): string {
  const slices = distribution.slices.filter((slice) => slice.count > 0)
  const parts = slices.map((slice) => `${slice.label} ${slice.count} (${formatPercentage(slice.percentage)})`).join(', ')
  return `ICT equipment distribution, ${distribution.total} total: ${parts || 'no data'}`
}

export function EquipmentDistributionCard({ distribution }: { distribution: DashboardEquipmentDistribution }) {
  const chartData = distribution.slices.filter((slice) => slice.count > 0)

  return (
    <Card className="h-full">
      <CardHeader className="px-4 py-3">
        <DashboardSectionHeading id="equipment-distribution-heading" title="ICT Equipment Distribution" />
      </CardHeader>
      <CardContent className="space-y-4 p-4">
        <div className="relative mx-auto h-48 w-48" role="img" aria-label={chartSummary(distribution)}>
          {chartData.length > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={chartData} dataKey="count" nameKey="label" innerRadius="64%" outerRadius="100%" paddingAngle={chartData.length > 1 ? 2 : 0} stroke="none">
                  {chartData.map((slice) => (
                    <Cell key={slice.key} fill={equipmentGroupStyle(slice.key).chart} />
                  ))}
                </Pie>
                <Tooltip formatter={(value) => [Number(value).toLocaleString(), 'Items']} />
              </PieChart>
            </ResponsiveContainer>
          ) : (
            <div className="absolute inset-0 rounded-full border-[1.5rem] border-gray-100" />
          )}
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
            <span className="text-2xl font-bold tabular-nums text-gray-900">{distribution.total.toLocaleString()}</span>
            <span className="max-w-[6rem] text-[0.6875rem] leading-tight text-gray-500">Total ICT Equipment</span>
          </div>
        </div>

        <ul aria-label="Legend" className="space-y-1.5">
          {distribution.slices.map((slice) => (
            <li key={slice.key} className="flex items-center gap-2 text-xs">
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: equipmentGroupStyle(slice.key).chart }} aria-hidden="true" />
              <span className="min-w-0 flex-1 truncate text-gray-700" title={slice.label}>
                {slice.label}
              </span>
              <span className="whitespace-nowrap tabular-nums">
                <span className="font-semibold text-gray-900">{slice.count.toLocaleString()}</span>{' '}
                <span className="text-gray-500">({formatPercentage(slice.percentage)})</span>
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
