import { ChevronDown } from 'lucide-react'
import { type KeyboardEvent, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

import { FieldLabelText } from '@/components/ui/FieldLabelText'
import { cn } from '@/lib/utils'

interface SuggestInputProps {
  value: string
  onChange: (value: string) => void
  suggestions: string[]
  label?: string
  placeholder?: string
  disabled?: boolean
  maxLength?: number
  portal?: boolean
  className?: string
  inputClassName?: string
  'aria-label'?: string
}

export function SuggestInput({
  value,
  onChange,
  suggestions,
  label,
  placeholder,
  disabled = false,
  maxLength,
  portal = false,
  className,
  inputClassName,
  'aria-label': ariaLabel,
}: SuggestInputProps) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const [highlight, setHighlight] = useState(-1)
  const [rect, setRect] = useState<DOMRect | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const listRef = useRef<HTMLUListElement>(null)

  const term = value.trim().toLowerCase()
  const matches = [...new Set(suggestions)].filter((name) => name.toLowerCase().includes(term) && name.toLowerCase() !== term)
  const showList = open && !disabled && matches.length > 0

  useEffect(() => {
    if (!open) return
    function handleClick(event: MouseEvent) {
      const target = event.target as Node
      if (!containerRef.current?.contains(target) && !listRef.current?.contains(target)) setOpen(false)
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [open])

  useLayoutEffect(() => {
    if (!showList || !portal) return
    const update = () => setRect(containerRef.current?.getBoundingClientRect() ?? null)
    update()
    window.addEventListener('scroll', update, true)
    window.addEventListener('resize', update)
    return () => {
      window.removeEventListener('scroll', update, true)
      window.removeEventListener('resize', update)
    }
  }, [showList, portal])

  function choose(name: string) {
    onChange(name)
    setOpen(false)
    setHighlight(-1)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setOpen(true)
      setHighlight((h) => Math.min(h + 1, matches.length - 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlight((h) => Math.max(h - 1, 0))
    } else if (event.key === 'Enter' && showList && highlight >= 0) {
      event.preventDefault()
      choose(matches[highlight])
    } else if (event.key === 'Escape' && showList) {
      event.stopPropagation()
      setOpen(false)
    }
  }

  const list = showList && (
    <ul
      ref={listRef}
      id={`${id}-list`}
      role="listbox"
      className={cn(
        'max-h-60 overflow-y-auto overscroll-contain rounded-lg border border-gray-300 bg-white py-1 shadow-lg',
        portal ? 'fixed z-[60]' : 'absolute left-0 right-0 top-full z-50 mt-1',
      )}
      style={portal && rect ? { top: rect.bottom + 4, left: rect.left, width: rect.width } : undefined}
    >
      {matches.map((name, index) => (
        <li
          key={name}
          role="option"
          aria-selected={index === highlight}
          className={cn('cursor-pointer px-3 py-2 text-sm text-gray-700 hover:bg-primary-50 hover:text-primary-700', index === highlight && 'bg-primary-50 text-primary-700')}
          onMouseDown={(event) => event.preventDefault()}
          onMouseEnter={() => setHighlight(index)}
          onClick={() => choose(name)}
        >
          {name}
        </li>
      ))}
    </ul>
  )

  return (
    <div className={cn('w-full', className)}>
      {label && (
        <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-700">
          <FieldLabelText label={label} />
        </label>
      )}
      <div ref={containerRef} className="relative">
        <input
          id={id}
          type="text"
          role="combobox"
          aria-expanded={showList}
          aria-controls={`${id}-list`}
          aria-autocomplete="list"
          aria-label={ariaLabel}
          autoComplete="off"
          value={value}
          placeholder={placeholder}
          disabled={disabled}
          maxLength={maxLength}
          onChange={(event) => {
            onChange(event.target.value)
            setOpen(true)
            setHighlight(-1)
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={handleKeyDown}
          className={cn(
            'w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-9 text-sm text-gray-900',
            'focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20',
            'disabled:cursor-not-allowed disabled:bg-gray-50',
            inputClassName,
          )}
        />
        {!disabled && suggestions.length > 0 && (
          <ChevronDown className={cn('pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 transition-transform', showList && 'rotate-180')} />
        )}
        {!portal && list}
      </div>
      {portal && list && createPortal(list, document.body)}
    </div>
  )
}
