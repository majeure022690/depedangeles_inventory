import { isAxiosError } from 'axios'

import { api } from '@/lib/api'

/** Blank strings and nulls are left out so the server sees only what was filled in. */
export function queryParams(params: object): Record<string, string | number> {
  return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== null && value !== '')) as Record<string, string | number>
}

function filenameFrom(disposition: string | undefined, fallback: string): string {
  const match = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  return match?.[1] ? decodeURIComponent(match[1]) : fallback
}

/** A failed blob request carries its JSON error body as a Blob; parse it so the usual error helpers can read it. */
async function withJsonErrorBody(error: unknown): Promise<never> {
  if (isAxiosError(error) && error.response?.data instanceof Blob) {
    try {
      error.response.data = JSON.parse(await error.response.data.text())
    } catch {
      // Not JSON: leave the body as is.
    }
  }
  throw error
}

/** GETs a file and saves it under the server's filename. */
export async function downloadFile(path: string, params: object, fallbackName: string): Promise<void> {
  const response = await api.get<Blob>(path, { params: queryParams(params), responseType: 'blob' }).catch(withJsonErrorBody)
  const url = window.URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filenameFrom(response.headers['content-disposition'], fallbackName)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
