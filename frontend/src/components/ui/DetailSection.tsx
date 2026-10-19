import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

export function DetailSection({
  icon,
  title,
  action,
  last = false,
  children,
}: {
  icon: ReactNode
  title: string
  action?: ReactNode
  last?: boolean
  children: ReactNode
}) {
  return (
    <section className={cn('px-5 py-4', !last && 'border-b border-gray-100')}>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
          <span className="text-gray-400">{icon}</span>
          {title}
        </h2>
        {action}
      </div>
      {children}
    </section>
  )
}
