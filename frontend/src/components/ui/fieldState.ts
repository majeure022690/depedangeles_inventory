import type { AriaAttributes } from 'react'

/** Error border for a field control: set by its own `error`, or by an `aria-invalid` from an outer FormField. */
export const INVALID_FIELD_CLASS = 'border-error-400 focus:border-error-500 focus:ring-error-500/20'

export function isInvalid(error: string | undefined, ariaInvalid: AriaAttributes['aria-invalid']): boolean {
  return Boolean(error) || ariaInvalid === true || ariaInvalid === 'true'
}
