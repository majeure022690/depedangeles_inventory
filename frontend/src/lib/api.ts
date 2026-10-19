import axios from 'axios'

export const apiBaseUrl = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/$/, '')

/**
 * Every state-changing request needs the XSRF-TOKEN cookie Sanctum issues
 * via this endpoint (architecture.md §5) — call once on app boot, before
 * any POST/PUT/PATCH/DELETE. withXSRFToken is required because the SPA
 * and API are different origins (different ports), so axios's normal
 * same-origin-only XSRF handling doesn't kick in without it.
 */
export const api = axios.create({
  baseURL: `${apiBaseUrl}/api`,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

export async function ensureCsrfCookie(): Promise<void> {
  await axios.get(`${apiBaseUrl}/sanctum/csrf-cookie`, { withCredentials: true })
}

/** Registered from AuthContext once it mounts — kept out of this module's own import graph. */
let onUnauthorized: (() => void) | null = null

export function registerUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      onUnauthorized?.()
    }
    return Promise.reject(error)
  },
)
