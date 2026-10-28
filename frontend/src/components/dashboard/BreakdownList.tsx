export interface BreakdownItem {
  value: string
  count: number
}

/**
 * Ranked counts as labelled bars, each scaled to the largest count so the
 * top row always fills the width. One hue: these are magnitudes of a single
 * metric, not separate series.
 */
export function BreakdownList({ items, emptyMessage = 'No data yet.' }: { items: BreakdownItem[]; emptyMessage?: string }) {
  if (items.length === 0) return <p className="text-sm text-gray-500">{emptyMessage}</p>

  const max = Math.max(1, ...items.map((item) => item.count))

  return (
    <ul className="space-y-3">
      {items.map((item) => (
        <li key={item.value}>
          <div className="mb-1 flex items-center justify-between gap-3 text-sm">
            <span className="truncate text-gray-700" title={item.value}>
              {item.value}
            </span>
            <span className="tabular-nums text-gray-900">{item.count.toLocaleString()}</span>
          </div>
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
            <div className="h-full rounded-full bg-primary-600" style={{ width: `${(item.count / max) * 100}%` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}
