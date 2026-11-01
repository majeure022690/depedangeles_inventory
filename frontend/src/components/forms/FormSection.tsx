import { useId, type ReactNode } from 'react'

import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { cn } from '@/lib/utils'

const GRID_COLUMNS = {
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-2 xl:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4',
} as const

/** A titled Card holding a grid of form fields (two columns unless `columns` says otherwise), exposed as a named group. */
export function FormSection({
  title,
  className,
  columns = 2,
  children,
}: {
  title: string
  className?: string
  columns?: keyof typeof GRID_COLUMNS
  children: ReactNode
}) {
  const headingId = useId()
  return (
    <Card role="group" aria-labelledby={headingId} className={cn('overflow-visible', className)}>
      <CardHeader className="py-3">
        <h2 id={headingId} className="text-sm font-semibold uppercase tracking-wide text-gray-600">
          {title}
        </h2>
      </CardHeader>
      <CardContent>
        <div className={cn('grid gap-4', GRID_COLUMNS[columns])}>{children}</div>
      </CardContent>
    </Card>
  )
}
