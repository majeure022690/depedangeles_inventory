import { FormField } from '@/components/forms/FormField'
import { SelectPicker } from '@/components/ui/SelectPicker'
import type { PersonOption } from '@/types/lookup'

/** A searchable personnel picker that shows the chosen person's position under it. */
export function PersonPicker({
  id,
  label,
  hint,
  people,
  current,
  value,
  onChange,
  placeholder,
  disabled = false,
  error,
}: {
  id: string
  label: string
  hint?: string
  people: PersonOption[]
  current: PersonOption | null
  value: string
  onChange: (value: string) => void
  placeholder: string
  disabled?: boolean
  error?: string
}) {
  const list = current && !people.some((person) => person.value === current.value) ? [current, ...people] : people
  const selected = list.find((person) => String(person.value) === value) ?? null

  return (
    <FormField id={id} label={label} hint={hint} error={error} note={selected ? `Position: ${selected.position ?? 'not set in Personnel'}` : undefined}>
      {(aria) => (
        <SelectPicker
          id={id}
          aria-labelledby={`${id}-label`}
          options={list.map((person) => ({ value: String(person.value), label: person.label }))}
          selectedValue={value === '' ? null : value}
          onSelect={(option) => onChange(option ? option.value : '')}
          placeholder={placeholder}
          disabled={disabled}
          clearable
          {...aria}
        />
      )}
    </FormField>
  )
}
