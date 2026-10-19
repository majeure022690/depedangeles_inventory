import { Info } from 'lucide-react'

interface FieldHintProps {
  /** The id of the field this hint describes; the hidden description gets `${htmlFor}-hint`. */
  htmlFor: string
  /** Human-readable field label, used for the trigger's accessible name. */
  label: string
  /** The guidance text, shown in the tooltip and exposed to assistive tech. */
  text: string
}

/**
 * Compact field-level guidance: a small info button that reveals the text on
 * hover/focus for sighted users, plus an always-present visually-hidden
 * description so screen reader users hear it when the field receives focus.
 * Wire it up with `aria-describedby={`${id}-hint`}` on the field itself.
 *
 *   <FieldHint htmlFor="property_no" label="Property number" text="..." />
 *   <Input id="property_no" aria-describedby="property_no-hint" ... />
 */
export function FieldHint({ htmlFor, label, text }: FieldHintProps) {
  return (
    <span className="group relative inline-flex items-center align-middle">
      <button
        type="button"
        aria-label={`Help: ${label}`}
        className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-gray-400 transition-colors hover:text-gray-700 focus-visible:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
      >
        <Info className="h-3.5 w-3.5" aria-hidden="true" />
      </button>
      <span
        role="tooltip"
        aria-hidden="true"
        className="pointer-events-none invisible absolute bottom-full left-1/2 z-40 mb-1.5 w-max max-w-xs -translate-x-1/2 rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-normal text-gray-50 opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
      >
        {text}
      </span>
      <span id={`${htmlFor}-hint`} className="sr-only">
        {text}
      </span>
    </span>
  )
}
