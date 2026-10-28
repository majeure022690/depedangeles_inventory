import { AlertCircle } from 'lucide-react'
import { useRef, type KeyboardEvent, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

export interface TabItem {
  value: string
  label: string
  /** Neutral count shown as a pill after the label (e.g. number of records). */
  count?: number
  /** Validation errors on this tab; shown as a red indicator when above zero. */
  errorCount?: number
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
    const button = listRef.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]
    button?.focus()
    button?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
  }

  return (
    <div
      ref={listRef}
      role="tablist"
      aria-label={label}
      onKeyDown={handleKeyDown}
      className={cn('flex max-w-full gap-1 overflow-x-auto border-b border-gray-200', className)}
    >
      {tabs.map((tab, index) => {
        const selected = index === selectedIndex
        const errorCount = tab.errorCount ?? 0
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
              'inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500/40',
              selected ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700',
            )}
          >
            {tab.label}
            {tab.count !== undefined && (
              <>
                <span
                  aria-hidden="true"
                  className={cn(
                    'rounded-full px-2 py-0.5 text-xs font-medium',
                    selected ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600',
                  )}
                >
                  {tab.count}
                </span>
                <span className="sr-only">({tab.count})</span>
              </>
            )}
            {errorCount > 0 && (
              <>
                <span aria-hidden="true" className="inline-flex items-center gap-1 rounded-full bg-error-100 px-2 py-0.5 text-xs font-medium text-error-700">
                  <AlertCircle className="h-3 w-3" />
                  {errorCount}
                </span>
                <span className="sr-only">
                  , {errorCount} {errorCount === 1 ? 'error' : 'errors'}
                </span>
              </>
            )}
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
