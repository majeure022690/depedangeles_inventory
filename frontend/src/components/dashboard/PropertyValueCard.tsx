import type { LucideIcon } from 'lucide-react'

import { pesoExact } from '@/components/dashboard/format'
import { Card, CardContent } from '@/components/ui/Card'
import { cn } from '@/lib/utils'
import type { DashboardPropertyValue } from '@/types/dashboard'

export function PropertyValueCard({
  icon: Icon,
  title,
  caption,
  value,
  iconClassName,
  cardClassName,
}: {
  icon: LucideIcon
  title: string
  caption: string
  value: DashboardPropertyValue
  iconClassName: string
  cardClassName: string
}) {
  return (
    <Card className={cardClassName}>
      <CardContent className="flex items-center gap-4 p-4">
        <span className={cn('flex h-12 w-12 shrink-0 items-center justify-center rounded-xl', iconClassName)} aria-hidden="true">
          <Icon className="h-6 w-6" />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold text-gray-800">
            {title} <span className="font-normal text-gray-500">· {caption}</span>
          </h2>
          <p className="mt-1 text-2xl font-bold tabular-nums text-gray-900 [overflow-wrap:anywhere]">{pesoExact.format(value.total_value)}</p>
          <p className="text-xs text-gray-500">
            Total number of items <span className="font-semibold tabular-nums text-gray-800">{value.item_count.toLocaleString()}</span>
          </p>
        </div>
      </CardContent>
    </Card>
  )
}
