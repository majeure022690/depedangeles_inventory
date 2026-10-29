import { forwardRef, useId, type InputHTMLAttributes } from 'react'

import { cn, idList } from '@/lib/utils'

import { FieldLabelText } from '@/components/ui/FieldLabelText'
import { INVALID_FIELD_CLASS, isInvalid } from '@/components/ui/fieldState'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string
  error?: string
  hint?: string
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ label, error, hint, id, className, required, 'aria-describedby': describedBy, 'aria-invalid': ariaInvalid, ...props }, ref) => {
    const generatedId = useId()
    const inputId = id ?? props.name ?? generatedId
    const invalid = isInvalid(error, ariaInvalid)

    return (
      <div className="w-full">
        {label && (
          <label htmlFor={inputId} className="mb-1 block text-sm font-medium text-gray-700">
            <FieldLabelText label={label} />
            {required && (
              <span aria-hidden="true" className="ml-0.5 text-error-600">
                *
              </span>
            )}
          </label>
        )}
        <input
          ref={ref}
          id={inputId}
          aria-describedby={idList(describedBy, hint && !error && `${inputId}-description`, error && `${inputId}-error`)}
          aria-invalid={invalid || undefined}
          required={required}
          className={cn(
            'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900',
            'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
            'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500',
            invalid && INVALID_FIELD_CLASS,
            className,
          )}
          {...props}
        />
        {hint && !error && (
          <p id={`${inputId}-description`} className="mt-1 text-xs text-gray-500">
            {hint}
          </p>
        )}
        {error && (
          <p id={`${inputId}-error`} className="mt-1 text-xs text-error-600">
            {error}
          </p>
        )}
      </div>
    )
  },
)
Input.displayName = 'Input'
