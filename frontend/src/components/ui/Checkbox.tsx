import { forwardRef, type InputHTMLAttributes } from 'react'

import { cn } from '@/lib/utils'

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: string
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(({ label, id, className, ...props }, ref) => {
  const checkboxId = id ?? props.name

  return (
    <label htmlFor={checkboxId} className="flex items-center gap-2 text-sm text-gray-700 select-none">
      <input
        ref={ref}
        id={checkboxId}
        type="checkbox"
        className={cn(
          'h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30',
          className,
        )}
        {...props}
      />
      {label}
    </label>
  )
})
Checkbox.displayName = 'Checkbox'
