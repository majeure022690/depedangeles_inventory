import { FieldLabelText } from '@/components/ui/FieldLabelText'
import type { Option } from '@/types/lookup'
import { cn } from '@/lib/utils'

interface CheckboxGroupFieldProps<TValue extends string | number> {
  /** Used for the fieldset legend and to build each checkbox id. */
  id: string
  label?: string
  options: readonly Option<TValue>[]
  /** The JSON-array field's current value; `null` is treated as empty. */
  value: TValue[] | null
  onChange: (value: TValue[]) => void
  disabled?: boolean
  error?: string
  hint?: string
  className?: string
}

/** Adds or removes `item` from `current` (no duplicates), keeping the original order. */
export function toggleArrayValue<TValue extends string | number>(current: readonly TValue[] | null, item: TValue, checked: boolean): TValue[] {
  const list = current ?? []
  if (checked) return list.includes(item) ? [...list] : [...list, item]
  return list.filter((existing) => existing !== item)
}

/**
 * A lookup-backed multi-select rendered as a grid of checkboxes bound to one
 * JSON-array field (check adds the option's value, uncheck removes it).
 * Shared by the Stakeholder Profile and Internet Connectivity Survey forms
 * (string-valued Tier 2 options) and by Personnel's `fund_source`
 * (id-valued options, `TValue = number`).
 */
export function CheckboxGroupField<TValue extends string | number>({
  id,
  label,
  options,
  value,
  onChange,
  disabled = false,
  error,
  hint,
  className,
}: CheckboxGroupFieldProps<TValue>) {
  const selected = value ?? []
  const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined

  return (
    <fieldset className={cn('w-full', className)} aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
      {label && (
        <legend className="mb-2 block text-sm font-medium text-gray-700">
          <FieldLabelText label={label} />
        </legend>
      )}
      {hint && (
        <p id={`${id}-hint`} className="mb-2 text-xs text-gray-500">
          {hint}
        </p>
      )}
      {options.length === 0 ? (
        <p className="text-sm text-gray-500">No options available.</p>
      ) : (
        <div className="grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
          {options.map((option) => {
            const optionId = `${id}-${option.value}`
            return (
              <label key={option.value} htmlFor={optionId} className="flex items-center gap-2 text-sm text-gray-700 select-none">
                <input
                  id={optionId}
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  checked={selected.includes(option.value)}
                  disabled={disabled}
                  onChange={(event) => onChange(toggleArrayValue(selected, option.value, event.target.checked))}
                />
                {option.label}
              </label>
            )
          })}
        </div>
      )}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-sm text-red-600">
          {error}
        </p>
      )}
    </fieldset>
  )
}
