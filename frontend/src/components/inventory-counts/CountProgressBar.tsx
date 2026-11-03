import { cn } from '@/lib/utils'

export function CountProgressBar({ counted, total, className }: { counted: number; total: number; className?: string }) {
  const percent = total === 0 ? 0 : Math.round((counted / total) * 100)

  return (
    <div className={cn('flex min-w-32 items-center gap-2', className)}>
      <div
        className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={total}
        aria-valuenow={counted}
        aria-label={`${counted} of ${total} counted`}
      >
        <div className="h-full rounded-full bg-primary-600 transition-[width]" style={{ width: `${percent}%` }} />
      </div>
      <span className="whitespace-nowrap text-xs text-gray-600">
        {counted} / {total}
      </span>
    </div>
  )
}
