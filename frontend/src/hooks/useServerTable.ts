import { useCallback, useEffect, useRef, useState } from 'react'

import { extractError } from '@/lib/errors'
import type { PaginatedResponse, PaginationMeta } from '@/types/api'

/**
 * Shared by every management list page to page/sort/search/filter the
 * Laravel paginator shape (`data`/`meta`/`links`) the same way, driving
 * `DataGrid`. Filter values passed in `options.filters` are sent as query
 * params and reset the table to page 1 when they change.
 */
export function useServerTable<T>(
  fetcher: (params: Record<string, string | number>) => Promise<PaginatedResponse<T>>,
  options?: { perPage?: number; waitForPerPage?: boolean; filters?: Record<string, string | number | undefined> },
) {
  const filters = options?.filters
  // Filter *values* (not the object identity, which is new every render) drive refetch + page reset.
  const filtersKey = filters ? JSON.stringify(filters) : ''

  const [items, setItems] = useState<T[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loading, setLoading] = useState(options?.waitForPerPage ?? false)
  const [error, setError] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPageState] = useState<number | null>(options?.waitForPerPage ? null : (options?.perPage ?? 20))
  const [search, setSearch] = useState('')
  const [sortBy, setSortBy] = useState<string | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher
  const requestRef = useRef(0)

  const load = useCallback(async () => {
    if (perPage === null) return
    const requestId = ++requestRef.current
    setLoading(true)
    setError(null)
    try {
      const params: Record<string, string | number> = {
        page,
        per_page: perPage,
      }
      if (search) params.search = search
      if (sortBy) {
        params.sort = sortBy
        params.direction = sortDir
      }
      if (filters) {
        for (const [key, value] of Object.entries(filters)) {
          if (value !== undefined && value !== null && value !== '') params[key] = value
        }
      }

      const result = await fetcherRef.current(params)
      if (requestId !== requestRef.current) return
      setItems(result.data)
      setMeta(result.meta)
    } catch (fetchError) {
      if (requestId !== requestRef.current) return
      setError(extractError(fetchError, 'Unable to load records. Please try again.'))
    } finally {
      if (requestId === requestRef.current) setLoading(false)
    }
    // filtersKey (not filters) intentionally drives this — see its definition above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, search, sortBy, sortDir, filtersKey])

  const isFirstFiltersRender = useRef(true)
  useEffect(() => {
    if (isFirstFiltersRender.current) {
      isFirstFiltersRender.current = false
      return
    }
    setPage(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtersKey])

  useEffect(() => {
    load()
  }, [load])

  function handleSortChange(key: string) {
    if (sortBy === key) {
      setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(key)
      setSortDir('asc')
    }
    setPage(1)
  }

  function handlePerPageChange(value: number) {
    setPerPageState(value)
    setPage(1)
  }

  function handleSearchChange(value: string) {
    setSearch(value)
    setPage(1)
  }

  return {
    items,
    meta,
    loading,
    error,
    page,
    setPage,
    perPage,
    setPerPage: handlePerPageChange,
    search,
    setSearch: handleSearchChange,
    sortBy,
    sortDir,
    onSortChange: handleSortChange,
    reload: load,
  }
}
