import type { SelectOption } from '@/components/ui/Select'

/**
 * Keeps a stored value visible when it's no longer an active option
 * (e.g. a deactivated brand), instead of the select silently showing its
 * placeholder. The server still decides whether the value is accepted.
 */
export function withCurrentOption(options: readonly SelectOption[] | undefined, value: string | number | null, label?: string | null): SelectOption[] {
  const list = [...(options ?? [])]
  if (value === null || value === '' || list.some((option) => option.value === value)) return list
  return [...list, { value, label: `${label ?? value} (inactive)` }]
}

export const OTHERS = 'Others'

/** True when the chosen option is the catch-all "Others", which needs a typed value beside it. */
export function selectsOthers(options: readonly SelectOption[] | undefined, value: string | number | null): boolean {
  return value !== null && value !== '' && (options ?? []).some((option) => option.value === value && option.label === OTHERS)
}
