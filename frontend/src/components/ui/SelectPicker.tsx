import { ChevronDown, Search, X } from 'lucide-react'
import { type ChangeEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react'

import { cn } from '@/lib/utils'

import { FieldLabelText } from '@/components/ui/FieldLabelText'

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
}

const LIST_MAX_HEIGHT = 288
const LIST_MIN_HEIGHT = 120
const SEARCH_HEADER_HEIGHT = 58
const EDGE_GAP = 8

/**
 * Forked from BetterGov.ph's components/ui/SelectPicker.tsx — a searchable
 * combobox with no Radix Select dependency (not installed in this stack).
 * Used for long option lists (case types, PSGC locations, etc.) where a
 * plain <Select> would be a wall of unsearchable options.
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
}: SelectPickerProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [dropdownPosition, setDropdownPosition] = useState<'bottom' | 'top'>('bottom')
  const [listMaxHeight, setListMaxHeight] = useState(LIST_MAX_HEIGHT)
  const containerRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  const selectedOption = options.find((opt) => opt.value === selectedValue) ?? null

  const filteredOptions = options.filter((option) => option.label.toLowerCase().includes(searchTerm.toLowerCase()))

  function handleSelect(option: SelectPickerOption) {
    setIsOpen(false)
    setSearchTerm('')
    onSelect(option)
  }

  function handleClear(e: React.MouseEvent) {
    e.stopPropagation()
    onSelect(null)
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

  function handleToggle() {
    if (disabled) return
    if (!isOpen) {
      const layout = checkDropdownLayout()
      setDropdownPosition(layout.position)
      setListMaxHeight(layout.listMaxHeight)
      setTimeout(() => searchInputRef.current?.focus(), 0)
    }
    setIsOpen(!isOpen)
  }

  function handleSearchKeyDown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
      setIsOpen(false)
      setSearchTerm('')
    }
  }

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchTerm('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  return (
    <div className="w-full">
      {label && <label className="mb-1 block text-sm font-medium text-gray-700"><FieldLabelText label={label} /></label>}
      <div className={cn('relative w-full', className)} ref={containerRef}>
        <button
          ref={triggerRef}
          type="button"
          className={cn(
            'flex h-10 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 text-left text-sm',
            'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
            'transition-colors',
            disabled ? 'cursor-not-allowed bg-gray-50 opacity-60' : 'hover:border-gray-400',
            isOpen && 'border-primary-500 ring-2 ring-primary-500/20',
            error && 'border-error-400',
          )}
          onClick={handleToggle}
          disabled={disabled}
          aria-haspopup="listbox"
          aria-expanded={isOpen}
        >
          <span className={cn('truncate', selectedOption ? 'text-gray-900' : 'text-gray-500')}>
            {selectedOption ? selectedOption.label : placeholder}
          </span>
          <div className="flex items-center gap-1">
            {clearable && selectedOption && !disabled && (
              <span
                role="button"
                tabIndex={-1}
                className="rounded-full p-1 hover:bg-gray-100"
                onClick={handleClear}
                aria-label="Clear selection"
              >
                <X className="h-4 w-4 text-gray-500" />
              </span>
            )}
            <ChevronDown className={cn('h-4 w-4 text-gray-500 transition-transform', isOpen && 'rotate-180')} />
          </div>
        </button>

        {isOpen && (
          <div
            className={cn(
              'absolute z-50 w-full overflow-hidden rounded-lg border border-gray-300 bg-white shadow-lg',
              dropdownPosition === 'top' ? 'bottom-full mb-1' : 'top-full mt-1',
            )}
          >
            <div className="border-b border-gray-200 p-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                <input
                  ref={searchInputRef}
                  type="text"
                  className="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                  placeholder="Search..."
                  value={searchTerm}
                  onChange={(e: ChangeEvent<HTMLInputElement>) => setSearchTerm(e.target.value)}
                  onKeyDown={handleSearchKeyDown}
                />
              </div>
            </div>
            <div className="overflow-y-auto overscroll-contain" style={{ maxHeight: listMaxHeight }}>
              {filteredOptions.length > 0 ? (
                <ul role="listbox" className="py-1">
                  {filteredOptions.map((option) => (
                    <li
                      key={option.value}
                      className={cn(
                        'cursor-pointer px-3 py-2 text-sm hover:bg-primary-50 hover:text-primary-700',
                        selectedOption?.value === option.value && 'bg-primary-100 text-primary-700',
                      )}
                      onClick={() => handleSelect(option)}
                      role="option"
                      aria-selected={selectedOption?.value === option.value}
                    >
                      {option.label}
                    </li>
                  ))}
                </ul>
              ) : (
                <div className="px-3 py-2 text-center text-sm text-gray-500">No options found</div>
              )}
            </div>
          </div>
        )}
      </div>
      {error && <p className="mt-1 text-xs text-error-600">{error}</p>}
    </div>
  )
}
