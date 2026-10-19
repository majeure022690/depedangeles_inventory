import { useRef, type KeyboardEvent, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

export interface TabItem {
  value: string
  label: string
}

interface TabsProps {
  /** Shared with each TabPanel's `tabsId`; builds the tab/panel ids that tie them together. */
  id: string
  tabs: TabItem[]
  value: string
  onChange: (value: string) => void
  /** Accessible name for the tab list. */
  label?: string
  className?: string
}

const tabId = (tabsId: string, value: string) => `${tabsId}-tab-${value}`
const panelId = (tabsId: string, value: string) => `${tabsId}-panel-${value}`

function targetIndex(key: string, current: number, last: number): number | null {
  switch (key) {
    case 'ArrowRight':
      return current >= last ? 0 : current + 1
    case 'ArrowLeft':
      return current <= 0 ? last : current - 1
    case 'Home':
      return 0
    case 'End':
      return last
    default:
      return null
  }
}

/**
 * WAI-ARIA tabs (no Radix Tabs dependency installed): a controlled tab strip
 * with roving tabindex; Left/Right/Home/End move focus and select (automatic
 * activation). Pair with `<TabPanel tabsId={id} value=...>`.
 */
export function Tabs({ id, tabs, value, onChange, label, className }: TabsProps) {
  const listRef = useRef<HTMLDivElement>(null)
  const selectedIndex = tabs.findIndex((tab) => tab.value === value)
  const focusableIndex = selectedIndex === -1 ? 0 : selectedIndex

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const next = targetIndex(event.key, focusableIndex, tabs.length - 1)
    if (next === null || tabs.length === 0) return
    event.preventDefault()
    onChange(tabs[next].value)
    listRef.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]?.focus()
  }

  return (
    <div
      ref={listRef}
      role="tablist"
      aria-label={label}
      onKeyDown={handleKeyDown}
      className={cn('flex gap-1 overflow-x-auto border-b border-gray-200', className)}
    >
      {tabs.map((tab, index) => {
        const selected = index === selectedIndex
        return (
          <button
            key={tab.value}
            id={tabId(id, tab.value)}
            type="button"
            role="tab"
            aria-selected={selected}
            // Only the active panel is mounted, so only the active tab points at one.
            aria-controls={selected ? panelId(id, tab.value) : undefined}
            tabIndex={index === focusableIndex ? 0 : -1}
            onClick={() => onChange(tab.value)}
            className={cn(
              'shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500/40',
              selected ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700',
            )}
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}

export function TabPanel({ tabsId, value, active, children }: { tabsId: string; value: string; active: boolean; children: ReactNode }) {
  if (!active) return null
  return (
    <div id={panelId(tabsId, value)} role="tabpanel" aria-labelledby={tabId(tabsId, value)} className="pt-4">
      {children}
    </div>
  )
}
