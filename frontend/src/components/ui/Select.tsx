import { ChevronDown } from 'lucide-react'
import { forwardRef, useId, type SelectHTMLAttributes } from 'react'

import { cn, idList } from '@/lib/utils'

import { FieldLabelText } from '@/components/ui/FieldLabelText'
import { INVALID_FIELD_CLASS, isInvalid } from '@/components/ui/fieldState'

export interface SelectOption {
  label: string
  value: string | number
}

interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
  label?: string
  error?: string
  hint?: string
  options: SelectOption[]
  placeholder?: string
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ label, error, hint, options, placeholder, id, className, 'aria-describedby': describedBy, 'aria-invalid': ariaInvalid, ...props }, ref) => {
    const generatedId = useId()
    const selectId = id ?? props.name ?? generatedId
    const invalid = isInvalid(error, ariaInvalid)

    return (
      <div className="w-full">
        {label && (
          <label htmlFor={selectId} className="mb-1 block text-sm font-medium text-gray-700">
            <FieldLabelText label={label} />
          </label>
        )}
        <div className="relative">
          <select
            ref={ref}
            id={selectId}
            aria-describedby={idList(describedBy, hint && !error && `${selectId}-description`, error && `${selectId}-error`)}
            aria-invalid={invalid || undefined}
            className={cn(
              'w-full appearance-none rounded-lg border border-gray-300 bg-white px-3 py-2 pr-9 text-sm text-gray-900',
              'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
              'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500',
              invalid && INVALID_FIELD_CLASS,
              className,
            )}
            {...props}
          >
            {placeholder && <option value="">{placeholder}</option>}
            {options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
        </div>
        {hint && !error && (
          <p id={`${selectId}-description`} className="mt-1 text-xs text-gray-500">
            {hint}
          </p>
        )}
        {error && (
          <p id={`${selectId}-error`} className="mt-1 text-xs text-error-600">
            {error}
          </p>
        )}
      </div>
    )
  },
)
Select.displayName = 'Select'
