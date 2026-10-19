import { type ChangeEvent, type KeyboardEvent, useEffect, useId, useRef, useState } from 'react'

import { cn } from '@/lib/utils'

interface OtpInputProps {
  label?: string
  length?: number
  value: string
  onChange: (value: string) => void
  autoFocus?: boolean
  disabled?: boolean
  error?: boolean
}

// One box per digit. Typing moves forward, Backspace on an empty box moves back,
// and pasting or autofilling a whole code into any box spreads it across the boxes.
export function OtpInput({ label, length = 6, value, onChange, autoFocus, disabled, error }: OtpInputProps) {
  const labelId = useId()
  const inputs = useRef<(HTMLInputElement | null)[]>([])
  const [digits, setDigits] = useState<string[]>(() => Array.from({ length }, (_, i) => value[i] ?? ''))

  // Reset the boxes when the parent clears the code.
  useEffect(() => {
    if (value === '') setDigits(Array(length).fill(''))
  }, [value, length])

  function focus(index: number) {
    const input = inputs.current[Math.max(0, Math.min(index, length - 1))]
    input?.focus()
    input?.select()
  }

  function update(next: string[]) {
    setDigits(next)
    onChange(next.join(''))
  }

  function handleChange(index: number, e: ChangeEvent<HTMLInputElement>) {
    const chars = e.target.value.replace(/\D/g, '')
    const next = [...digits]

    if (chars.length === 0) {
      next[index] = ''
      update(next)
      return
    }

    chars
      .slice(0, length - index)
      .split('')
      .forEach((char, offset) => {
        next[index + offset] = char
      })
    update(next)
    focus(index + chars.length)
  }

  function handleKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && digits[index] === '' && index > 0) {
      e.preventDefault()
      const next = [...digits]
      next[index - 1] = ''
      update(next)
      focus(index - 1)
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault()
      focus(index - 1)
    } else if (e.key === 'ArrowRight') {
      e.preventDefault()
      focus(index + 1)
    }
  }

  return (
    <div className="w-full">
      {label && (
        <p id={labelId} className="mb-1 block text-sm font-medium text-gray-700">
          {label}
        </p>
      )}
      <div role="group" aria-labelledby={label ? labelId : undefined} className="flex justify-between gap-2">
        {digits.map((digit, index) => (
          <input
            key={index}
            ref={(el) => {
              inputs.current[index] = el
            }}
            type="text"
            inputMode="numeric"
            autoComplete={index === 0 ? 'one-time-code' : 'off'}
            aria-label={`Digit ${index + 1} of ${length}`}
            value={digit}
            onChange={(e) => handleChange(index, e)}
            onKeyDown={(e) => handleKeyDown(index, e)}
            onFocus={(e) => e.target.select()}
            autoFocus={autoFocus && index === 0}
            disabled={disabled}
            className={cn(
              'h-12 w-full min-w-0 max-w-12 rounded-lg border border-gray-300 bg-white text-center text-lg font-semibold text-gray-900',
              'focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500',
              'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500',
              error && 'border-error-400 focus:border-error-500 focus:ring-error-500/20',
            )}
          />
        ))}
      </div>
    </div>
  )
}
