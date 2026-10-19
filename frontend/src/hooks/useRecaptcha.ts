import { useGoogleReCaptcha } from 'react-google-recaptcha-v3'

/**
 * Returns a function that silently executes reCAPTCHA v3 and returns a token.
 * Pass the action name (e.g. 'admin_login') for score context.
 */
export function useRecaptcha() {
  const { executeRecaptcha } = useGoogleReCaptcha()

  return async (action: string): Promise<string | null> => {
    if (!executeRecaptcha) return null
    return executeRecaptcha(action)
  }
}
