import { Check, Monitor, Moon, Sun, type LucideIcon } from 'lucide-react'
import { useState } from 'react'

import type { ThemePreference } from '@/hooks/usePortalTheme'
import { cn } from '@/lib/utils'

const OPTIONS: { value: ThemePreference; label: string; icon: LucideIcon }[] = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'Match device', icon: Monitor },
]

export function ThemeToggle({ preference, onChange }: { preference: ThemePreference; onChange: (value: ThemePreference) => void }) {
  const [open, setOpen] = useState(false)
  const current = OPTIONS.find((option) => option.value === preference) ?? OPTIONS[0]

  return (
    <div className="relative">
      <button
        type="button"
        className="rounded-md p-2 text-gray-600 hover:bg-gray-100"
        onClick={() => setOpen((v) => !v)}
        aria-label={`Theme: ${current.label}`}
        title={`Theme: ${current.label}`}
      >
        <current.icon className="h-5 w-5" />
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-full z-20 mt-1 w-44 overflow-hidden rounded-lg border border-gray-100 bg-white py-1 shadow-lg">
            {OPTIONS.map((option) => (
              <button
                key={option.value}
                type="button"
                className={cn(
                  'flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50',
                  option.value === preference ? 'font-medium text-primary-700' : 'text-gray-700',
                )}
                onClick={() => {
                  onChange(option.value)
                  setOpen(false)
                }}
              >
                <option.icon className="h-4 w-4" />
                <span className="flex-1">{option.label}</span>
                {option.value === preference && <Check className="h-4 w-4" />}
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
