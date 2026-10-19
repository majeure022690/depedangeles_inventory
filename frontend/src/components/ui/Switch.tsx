import { useId } from 'react'

import { cn, idList } from '@/lib/utils'

interface SwitchProps {
  checked: boolean
  onChange: (checked: boolean) => void
  label?: string
  disabled?: boolean
  id?: string
  /** Validation message, shown below and linked to the switch. */
  error?: string
  /** Helper text, shown below (when there's no error) and linked to the switch. */
  hint?: string
  'aria-describedby'?: string
}

export function Switch({ checked, onChange, label, disabled, id, error, hint, 'aria-describedby': describedBy }: SwitchProps) {
  const generatedId = useId()
  const switchId = id ?? generatedId

  return (
    <div>
      <label className={cn('flex items-center gap-2 text-sm text-gray-700 select-none', disabled && 'opacity-60')}>
        <button
          id={switchId}
          type="button"
          role="switch"
          aria-checked={checked}
          aria-describedby={idList(describedBy, hint && !error && `${switchId}-description`, error && `${switchId}-error`)}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          onClick={() => onChange(!checked)}
          className={cn(
            'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-1',
            checked ? 'bg-primary-600' : 'bg-gray-300',
            disabled && 'cursor-not-allowed',
          )}
        >
          <span
            className={cn(
              'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
              checked ? 'translate-x-4' : 'translate-x-0.5',
            )}
          />
        </button>
        {label}
      </label>
      {hint && !error && (
        <p id={`${switchId}-description`} className="mt-1 text-xs text-gray-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${switchId}-error`} className="mt-1 text-xs text-error-600">
          {error}
        </p>
      )}
    </div>
  )
}
