import { useState } from 'react'

/**
 * Ties list filters to table columns: a filter whose column the user hid in the grid's column
 * settings is hidden too, and `clear` is asked to reset it so nothing invisible keeps filtering.
 */
export function useColumnLinkedFilters(filtersByColumn: Record<string, readonly string[]>, clear: (keys: string[]) => void) {
  const [hiddenColumns, setHiddenColumns] = useState<string[]>([])
  const hiddenFilters = hiddenColumns.flatMap((columnId) => filtersByColumn[columnId] ?? [])

  return {
    filterShown: (key: string) => !hiddenFilters.includes(key),
    onHiddenColumnsChange: (hidden: string[]) => {
      setHiddenColumns(hidden)
      const keys = hidden.flatMap((columnId) => filtersByColumn[columnId] ?? [])
      if (keys.length > 0) clear(keys)
    },
  }
}
