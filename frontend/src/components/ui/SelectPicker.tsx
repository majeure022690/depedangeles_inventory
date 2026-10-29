import { ChevronDown, Search, X } from 'lucide-react'
import { type AriaAttributes, type ChangeEvent, type KeyboardEvent, useEffect, useId, useRef, useState } from 'react'

import { cn, idList } from '@/lib/utils'

import { FieldLabelText } from '@/components/ui/FieldLabelText'
import { isInvalid } from '@/components/ui/fieldState'

export interface SelectPickerOption {
  label: string
  value: string
}

interface SelectPickerProps {
  options: SelectPickerOption[]
  onSelect: (option: SelectPickerOption | null) => void
  placeholder?: string
  className?: string
  disabled?: boolean
  clearable?: boolean
  selectedValue?: string | null
  label?: string
  error?: string
  /** Id of the trigger button; a `<label htmlFor>` pointing here names it. */
  id?: string
  /** An external label element's id (e.g. a FormField label); used instead of `label`. */
  'aria-labelledby'?: string
  /** Accessible name when there's no visible label at all. */
  'aria-label'?: string
  'aria-describedby'?: string
  'aria-invalid'?: AriaAttributes['aria-invalid']
  /** A button can't carry aria-required, so required-ness is exposed as part of the description. */
  'aria-required'?: AriaAttributes['aria-required']
}

const LIST_MAX_HEIGHT = 288
const LIST_MIN_HEIGHT = 120
const SEARCH_HEADER_HEIGHT = 58
const EDGE_GAP = 8
const PAGE_STEP = 10

/**
 * Forked from BetterGov.ph's components/ui/SelectPicker.tsx — a searchable
 * combobox with no Radix Select dependency (not installed in this stack).
 * Used for long option lists (case types, PSGC locations, etc.) where a
 * plain <Select> would be a wall of unsearchable options.
 *
 * Keyboard (WAI-ARIA combobox + listbox): ArrowDown/ArrowUp/Enter/Space on the
 * trigger open the popup; in the search box ArrowUp/ArrowDown/PageUp/PageDown
 * move the active option, Enter selects, Escape closes and returns focus.
 */
export function SelectPicker({
  options,
  onSelect,
  placeholder = 'Select an option...',
  className,
  disabled = false,
  clearable = true,
  selectedValue,
  label,
  error,
  id,
  'aria-labelledby': labelledBy,
  'aria-label': ariaLabel,
  'aria-describedby': describedBy,
  'aria-invalid': ariaInvalid,
  'aria-required': ariaRequired,
}: SelectPickerProps) {
  const generatedId = useId()
  const triggerId = id ?? generatedId
  const labelId = `${triggerId}-label`
  const valueId = `${triggerId}-value`
  const errorId = `${triggerId}-error`
  const requiredId = `${triggerId}-required`
  const required = ariaRequired === true || ariaRequired === 'true'
  const listboxId = `${triggerId}-listbox`
  const clearId = `${triggerId}-clear`
  const optionId = (index: number) => `${listboxId}-option-${index}`
  // Name = label + current value ("Office, Division Office"), like a native select.
  // With only aria-label, the trigger references itself, which resolves to its aria-label.
  const nameSource = labelledBy ?? (label ? labelId : ariaLabel ? triggerId : undefined)
  // The search box and listbox are named by the field label alone (no value).
  const popupLabelledBy = labelledBy ?? (label ? labelId : undefined)
  const invalid = isInvalid(error, ariaInvalid)

  const [isOpen, setIsOpen] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const [dropdownPosition, setDropdownPosition] = useState<'bottom' | 'top'>('bottom')
  const [listMaxHeight, setListMaxHeight] = useState(LIST_MAX_HEIGHT)
  const containerRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  const selectedOption = options.find((opt) => opt.value === selectedValue) ?? null
  const showClear = clearable && selectedOption !== null && !disabled

  const filteredOptions = options.filter((option) => option.label.toLowerCase().includes(searchTerm.toLowerCase()))
  const activeOption = activeIndex >= 0 ? filteredOptions[activeIndex] : undefined

  function close(returnFocus: boolean) {
    setIsOpen(false)
    setSearchTerm('')
    setActiveIndex(-1)
    if (returnFocus) triggerRef.current?.focus()
  }

  function handleSelect(option: SelectPickerOption) {
    close(true)
    onSelect(option)
  }

  function handleClear() {
    onSelect(null)
    // The clear button unmounts once the value is gone; keep focus in the field.
    triggerRef.current?.focus()
  }

  function visibleBounds(el: HTMLElement): { top: number; bottom: number } {
    let top = 0
    let bottom = window.innerHeight
    for (let node = el.parentElement; node; node = node.parentElement) {
      if (/(auto|scroll|hidden)/.test(getComputedStyle(node).overflowY)) {
        const rect = node.getBoundingClientRect()
        top = Math.max(top, rect.top)
        bottom = Math.min(bottom, rect.bottom)
        break
      }
    }
    return { top, bottom }
  }

  function checkDropdownLayout(): { position: 'bottom' | 'top'; listMaxHeight: number } {
    if (!triggerRef.current) return { position: 'bottom', listMaxHeight: LIST_MAX_HEIGHT }
    const rect = triggerRef.current.getBoundingClientRect()
    const bounds = visibleBounds(triggerRef.current)
    const spaceBelow = bounds.bottom - rect.bottom - EDGE_GAP
    const spaceAbove = rect.top - bounds.top - EDGE_GAP
    const position = spaceBelow >= SEARCH_HEADER_HEIGHT + LIST_MAX_HEIGHT || spaceBelow >= spaceAbove ? 'bottom' : 'top'
    const space = position === 'bottom' ? spaceBelow : spaceAbove
    return { position, listMaxHeight: Math.max(LIST_MIN_HEIGHT, Math.min(LIST_MAX_HEIGHT, space - SEARCH_HEADER_HEIGHT)) }
  }

  function open() {
    const layout = checkDropdownLayout()
    setDropdownPosition(layout.position)
    setListMaxHeight(layout.listMaxHeight)
    const selectedIndex = options.findIndex((opt) => opt.value === selectedValue)
    setActiveIndex(selectedIndex >= 0 ? selectedIndex : options.length > 0 ? 0 : -1)
    setIsOpen(true)
    setTimeout(() => searchInputRef.current?.focus(), 0)
  }

  function handleToggle() {
    if (disabled) return
    if (isOpen) close(false)
    else open()
  }

  // Enter/Space open via the button's native click; arrows need handling here.
  function handleTriggerKeyDown(e: KeyboardEvent<HTMLButtonElement>) {
    if (disabled || isOpen) return
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      open()
    }
  }

  function handleSearchChange(e: ChangeEvent<HTMLInputElement>) {
    const term = e.target.value
    setSearchTerm(term)
    const hasMatch = options.some((option) => option.label.toLowerCase().includes(term.toLowerCase()))
    setActiveIndex(hasMatch ? 0 : -1)
  }

  function moveActive(delta: number) {
    const last = filteredOptions.length - 1
    if (last < 0) return
    setActiveIndex((i) => Math.max(0, Math.min(last, i < 0 ? 0 : i + delta)))
  }

  function handleSearchKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        moveActive(1)
        break
      case 'ArrowUp':
        e.preventDefault()
        moveActive(-1)
        break
      case 'PageDown':
        e.preventDefault()
        moveActive(PAGE_STEP)
        break
      case 'PageUp':
        e.preventDefault()
        moveActive(-PAGE_STEP)
        break
      case 'Enter':
        e.preventDefault()
        if (activeOption) handleSelect(activeOption)
        break
      case 'Escape':
        e.preventDefault()
        close(true)
        break
      case 'Tab':
        // Refocus the trigger before the browser acts, so Tab continues from the field instead of the unmounting popup.
        close(true)
        break
    }
  }

  useEffect(() => {
    if (!isOpen || activeIndex < 0) return
    document.getElementById(`${listboxId}-option-${activeIndex}`)?.scrollIntoView({ block: 'nearest' })
  }, [isOpen, activeIndex, listboxId])

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchTerm('')
        setActiveIndex(-1)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  return (
    <div className="w-full">
      {label && (
        <label id={labelId} htmlFor={triggerId} className="mb-1 block text-sm font-medium text-gray-700">
          <FieldLabelText label={label} />
        </label>
      )}
      <div className={cn('relative w-full', className)} ref={containerRef}>
        <button
          ref={triggerRef}
          id={triggerId}
          type="button"
          aria-label={ariaLabel}
          aria-labelledby={nameSource ? idList(nameSource, valueId) : undefined}
          aria-describedby={idList(required && requiredId, describedBy, error && errorId)}
          aria-invalid={invalid || undefined}
          className={cn(
            'flex h-10 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 text-left text-sm',
            'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
            'transition-colors',
            disabled ? 'cursor-not-allowed bg-gray-50 opacity-60' : 'hover:border-gray-400',
            isOpen && 'border-primary-500 ring-2 ring-primary-500/20',
            invalid && 'border-error-400',
          )}
          onClick={handleToggle}
          onKeyDown={handleTriggerKeyDown}
          disabled={disabled}
          aria-haspopup="listbox"
          aria-expanded={isOpen}
          aria-controls={isOpen ? listboxId : undefined}
        >
          <span id={valueId} className={cn('truncate', selectedOption ? 'text-gray-900' : 'text-gray-500', showClear && 'pr-7')}>
            {selectedOption ? selectedOption.label : placeholder}
          </span>
          <ChevronDown aria-hidden="true" className={cn('h-4 w-4 shrink-0 text-gray-500 transition-transform', isOpen && 'rotate-180')} />
        </button>
        {/* A sibling of the trigger (not nested in it), overlaid where the old inline X sat. */}
        {showClear && (
          <button
            id={clearId}
            type="button"
            onClick={handleClear}
            aria-label={label || ariaLabel ? `Clear ${label || ariaLabel}` : 'Clear'}
            aria-labelledby={!label && !ariaLabel && labelledBy ? idList(clearId, labelledBy) : undefined}
            className="absolute right-8 top-1/2 -translate-y-1/2 rounded-full p-1 text-gray-500 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        )}

        {isOpen && (
          <div
            className={cn(
              'absolute z-50 w-full overflow-hidden rounded-lg border border-gray-300 bg-white shadow-lg',
              dropdownPosition === 'top' ? 'bottom-full mb-1' : 'top-full mt-1',
            )}
          >
            <div className="border-b border-gray-200 p-2">
              <div className="relative">
                <Search aria-hidden="true" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                <input
                  ref={searchInputRef}
                  type="text"
                  role="combobox"
                  aria-expanded={true}
                  aria-controls={listboxId}
                  aria-autocomplete="list"
                  aria-activedescendant={activeOption ? optionId(activeIndex) : undefined}
                  aria-labelledby={popupLabelledBy}
                  aria-label={popupLabelledBy ? undefined : (ariaLabel ?? 'Search options')}
                  autoComplete="off"
                  className="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                  placeholder="Search..."
                  value={searchTerm}
                  onChange={handleSearchChange}
                  onKeyDown={handleSearchKeyDown}
                />
              </div>
            </div>
            <div className="overflow-y-auto overscroll-contain" style={{ maxHeight: listMaxHeight }}>
              <ul
                id={listboxId}
                role="listbox"
                aria-labelledby={popupLabelledBy}
                aria-label={popupLabelledBy ? undefined : ariaLabel}
                className={cn(filteredOptions.length > 0 && 'py-1')}
              >
                {filteredOptions.map((option, index) => {
                  const isSelected = selectedOption?.value === option.value
                  return (
                    <li
                      key={option.value}
                      id={optionId(index)}
                      role="option"
                      aria-selected={isSelected}
                      className={cn(
                        'cursor-pointer px-3 py-2 text-sm hover:bg-primary-50 hover:text-primary-700',
                        index === activeIndex && 'bg-primary-50 text-primary-700',
                        isSelected && 'bg-primary-100 text-primary-700',
                      )}
                      // Keeps focus in the search box (the combobox) while clicking.
                      onMouseDown={(e) => e.preventDefault()}
                      onMouseEnter={() => setActiveIndex(index)}
                      onClick={() => handleSelect(option)}
                    >
                      {option.label}
                    </li>
                  )
                })}
              </ul>
              {filteredOptions.length === 0 && (
                <div role="status" className="px-3 py-2 text-center text-sm text-gray-500">
                  No options found
                </div>
              )}
            </div>
          </div>
        )}
      </div>
      {required && (
        <span id={requiredId} hidden>
          Required
        </span>
      )}
      {error && (
        <p id={errorId} className="mt-1 text-xs text-error-600">
          {error}
        </p>
      )}
    </div>
  )
}
