import { api } from '@/lib/api'
import type { ApiResponse } from '@/types/api'
import type { Option } from '@/types/lookup'

/**
 * In-memory, per-session cache for `GET /api/<resource>/options` payloads
 * (module-level request deduplication, not shared domain state). Option
 * lists only change when an admin edits reference data, so a list page and
 * its create/edit pages can share one request per session. Call
 * `lookupService.forget()` after editing reference data so the next form
 * picks up the change.
 */
const cache = new Map<string, Promise<unknown>>()

async function cached<T>(key: string, fetcher: () => Promise<T>): Promise<T> {
  const existing = cache.get(key)
  if (existing) return existing as Promise<T>

  const promise = fetcher().catch((error: unknown) => {
    cache.delete(key)
    throw error
  })

  cache.set(key, promise)
  return promise
}

export const lookupService = {
  /**
   * Fetches an options endpoint once per session and returns its `data`,
   * e.g. `lookupService.options<PersonnelOptions>('/personnel/options')`.
   * The url doubles as the cache key.
   */
  options: <T>(url: string) => cached(url, () => api.get<ApiResponse<T>>(url).then((r) => r.data.data)),

  /** Drops one cached options payload, or all of them when called without a url. */
  forget: (url?: string) => {
    if (url) cache.delete(url)
    else cache.clear()
  },
}

/** Label for a stored value, falling back to the raw value when it's no longer an active option. */
export function optionLabel(options: readonly Option[] | undefined, value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  return options?.find((option) => option.value === value)?.label ?? String(value)
}
