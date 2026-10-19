import { useEffect } from 'react'

import { BRANDING } from '@/lib/branding'

const BASE_TITLE = BRANDING.appName

/**
 * Sets the browser tab title to "DICTIS | <page>" on mount
 * and resets to the base title on unmount.
 */
export function usePageTitle(page?: string): void {
  useEffect(() => {
    document.title = page ? `${BASE_TITLE} | ${page}` : BASE_TITLE
    return () => {
      document.title = BASE_TITLE
    }
  }, [page])
}
