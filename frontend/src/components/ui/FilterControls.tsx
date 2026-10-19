import { ChevronDown, Search, X } from 'lucide-react'
import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

export const filterFieldClass =
  'h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500'

export function FilterSearch({
  label,
  value,
  onChange,
  onSubmit,
  className,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  onSubmit: () => void
  className?: string
}) {
  return (
    <div className={cn('relative', className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
      <input
        type="text"
        placeholder={label}
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={(e) => e.key === 'Enter' && onSubmit()}
        className={cn(filterFieldClass, 'pl-9')}
      />
    </div>
  )
}

export function FilterSelect({
  label,
  value,
  onChange,
  disabled = false,
  className,
  children,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  className?: string
  children: ReactNode
}) {
  return (
    <div className={cn('relative', className)}>
      <select
        aria-label={label}
        title={label}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        className={cn(filterFieldClass, 'appearance-none pr-9')}
      >
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
    </div>
  )
}

export interface FilterChip<K extends string> {
  key: K
  label: string
  removable: boolean
}

export function FilterChips<K extends string>({ chips, onRemove }: { chips: FilterChip<K>[]; onRemove: (key: K) => void }) {
  return (
    <div className="flex min-h-7 flex-wrap items-center gap-1.5">
      {chips.length === 0 ? (
        <span className="text-xs text-gray-400">No filters applied</span>
      ) : (
        chips.map((chip) => (
          <span key={chip.key} className="inline-flex items-center gap-1 rounded-full bg-primary-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-primary-700">
            {chip.label}
            {chip.removable && (
              <button type="button" onClick={() => onRemove(chip.key)} className="rounded-full p-0.5 hover:bg-primary-100" aria-label={`Remove ${chip.label}`}>
                <X className="h-3 w-3" />
              </button>
            )}
          </span>
        ))
      )}
    </div>
  )
}
