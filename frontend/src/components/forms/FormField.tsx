import type { ReactNode } from 'react'

import { FieldHint } from '@/components/forms/FieldHint'
import { Checkbox } from '@/components/ui/Checkbox'
import { FieldLabelText } from '@/components/ui/FieldLabelText'
import { cn, idList } from '@/lib/utils'

/** What FormField hands its control: spread it onto the Input/Select/Textarea/SelectPicker. */
export interface FieldAria {
  'aria-describedby'?: string
  'aria-invalid'?: true
  'aria-required'?: true
}

function RequiredMark() {
  return (
    <span aria-hidden="true" className="ml-0.5 text-error-600">
      *
    </span>
  )
}

const plainLabel = (label: string) => label.replace(/\s*\(optional\)$/i, '')

/**
 * Label (+ optional FieldHint tooltip) above a control, with the hint and the
 * error linked through `aria-describedby`. The label is `${id}-label` and
 * targets `id`, so the control must use that id (SelectPicker: pass
 * `id={id} aria-labelledby={`${id}-label`}`).
 *
 *   <FormField id="model" label="Model (optional)" hint="..." error={errors.model}>
 *     {(aria) => <Input id="model" value={...} onChange={...} {...aria} />}
 *   </FormField>
 */
export function FormField({
  id,
  label,
  hint,
  note,
  error,
  required = false,
  className,
  children,
}: {
  id: string
  label: string
  hint?: string
  /** Always-visible text under the control (e.g. why it's read-only), linked through aria-describedby. */
  note?: string
  error?: string
  /** Shows a required marker and hands the control `aria-required`. */
  required?: boolean
  className?: string
  children: (aria: FieldAria) => ReactNode
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <div className="mb-1 flex items-center gap-1.5">
        <label id={`${id}-label`} htmlFor={id} className="text-sm font-medium text-gray-700">
          <FieldLabelText label={label} />
          {required && <RequiredMark />}
        </label>
        {hint && <FieldHint htmlFor={id} label={plainLabel(label)} text={hint} />}
      </div>
      {children({
        'aria-describedby': idList(hint && `${id}-hint`, note && `${id}-note`, error && `${id}-error`),
        'aria-invalid': error ? true : undefined,
        'aria-required': required ? true : undefined,
      })}
      {note && (
        <p id={`${id}-note`} className="mt-1 text-xs text-gray-500">
          {note}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-error-600">
          {error}
        </p>
      )}
    </div>
  )
}

/** A single checkbox with an optional FieldHint and a linked error, bottom-aligned in a FormSection grid cell. */
export function CheckboxField({
  id,
  label,
  hint,
  checked,
  onChange,
  disabled,
  error,
}: {
  id: string
  label: string
  hint?: string
  checked: boolean
  onChange: (checked: boolean) => void
  disabled?: boolean
  error?: string
}) {
  return (
    <div className="self-end pb-2">
      <div className="flex items-center gap-1.5">
        <Checkbox
          id={id}
          label={label}
          checked={checked}
          disabled={disabled}
          aria-describedby={idList(hint && `${id}-hint`, error && `${id}-error`)}
          aria-invalid={error ? true : undefined}
          onChange={(e) => onChange(e.target.checked)}
        />
        {hint && <FieldHint htmlFor={id} label={label} text={hint} />}
      </div>
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-error-600">
          {error}
        </p>
      )}
    </div>
  )
}
