import { useEffect } from 'react'

/**
 * Adds 'show-recaptcha-badge' to <body> on mount and removes it on unmount.
 * Use this on pages that have reCAPTCHA-protected forms.
 */
export function useShowRecaptchaBadge(): void {
  useEffect(() => {
    document.body.classList.add('show-recaptcha-badge')
    return () => {
      document.body.classList.remove('show-recaptcha-badge')
    }
  }, [])
}
