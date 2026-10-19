import { forwardRef, useId, type TextareaHTMLAttributes } from 'react'

import { cn } from '@/lib/utils'

import { FieldLabelText } from '@/components/ui/FieldLabelText'

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string
  error?: string
  hint?: string
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ label, error, hint, id, className, rows = 3, ...props }, ref) => {
    const generatedId = useId()
    const textareaId = id ?? props.name ?? generatedId

    return (
      <div className="w-full">
        {label && (
          <label htmlFor={textareaId} className="mb-1 block text-sm font-medium text-gray-700">
            <FieldLabelText label={label} />
          </label>
        )}
        <textarea
          ref={ref}
          id={textareaId}
          rows={rows}
          className={cn(
            'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900',
            'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
            'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500',
            error && 'border-error-400 focus:border-error-500 focus:ring-error-500/20',
            className,
          )}
          {...props}
        />
        {hint && !error && <p className="mt-1 text-xs text-gray-500">{hint}</p>}
        {error && <p className="mt-1 text-xs text-error-600">{error}</p>}
      </div>
    )
  },
)
Textarea.displayName = 'Textarea'
