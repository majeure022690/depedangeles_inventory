import { type ReactNode } from 'react'

import { cn } from '@/lib/utils'

export interface TabItem {
  value: string
  label: string
}

interface TabsProps {
  tabs: TabItem[]
  value: string
  onChange: (value: string) => void
  className?: string
}

/** Lightweight tab strip — no Radix Tabs dependency installed, so this is a plain controlled component. */
export function Tabs({ tabs, value, onChange, className }: TabsProps) {
  return (
    <div className={cn('flex gap-1 overflow-x-auto border-b border-gray-200', className)}>
      {tabs.map((tab) => (
        <button
          key={tab.value}
          type="button"
          onClick={() => onChange(tab.value)}
          className={cn(
            'shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
            value === tab.value
              ? 'border-primary-600 text-primary-700'
              : 'border-transparent text-gray-500 hover:text-gray-700',
          )}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}

export function TabPanel({ active, children }: { active: boolean; children: ReactNode }) {
  if (!active) return null
  return <div className="pt-4">{children}</div>
}
