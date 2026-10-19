import { useEffect, useState } from 'react'

export type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'dictis.theme'

function readPreference(): ThemePreference {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    return stored === 'dark' || stored === 'system' ? stored : 'light'
  } catch {
    return 'light'
  }
}

export function usePortalTheme() {
  const [preference, setPreference] = useState<ThemePreference>(readPreference)

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const apply = () => document.documentElement.classList.toggle('dark', preference === 'dark' || (preference === 'system' && media.matches))
    apply()
    media.addEventListener('change', apply)
    return () => {
      media.removeEventListener('change', apply)
      document.documentElement.classList.remove('dark')
    }
  }, [preference])

  function choose(next: ThemePreference) {
    setPreference(next)
    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {}
  }

  return { preference, choose }
}
