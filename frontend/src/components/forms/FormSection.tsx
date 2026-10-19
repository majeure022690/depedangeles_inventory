import { useId, type ReactNode } from 'react'

import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { cn } from '@/lib/utils'

/** A titled Card holding a two-column grid of form fields, exposed as a named group. */
export function FormSection({ title, className, children }: { title: string; className?: string; children: ReactNode }) {
  const headingId = useId()
  return (
    <Card role="group" aria-labelledby={headingId} className={cn('overflow-visible', className)}>
      <CardHeader className="py-3">
        <h2 id={headingId} className="text-sm font-semibold uppercase tracking-wide text-gray-600">
          {title}
        </h2>
      </CardHeader>
      <CardContent>
        <div className="grid gap-4 sm:grid-cols-2">{children}</div>
      </CardContent>
    </Card>
  )
}
