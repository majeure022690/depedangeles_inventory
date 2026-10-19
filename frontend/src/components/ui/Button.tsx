import { Loader2 } from 'lucide-react'
import { type ButtonHTMLAttributes, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  children: ReactNode
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'link'
  size?: 'sm' | 'md' | 'lg'
  fullWidth?: boolean
  isLoading?: boolean
  leftIcon?: ReactNode
  rightIcon?: ReactNode
}

const variants = {
  primary: 'bg-primary-600 text-white hover:bg-primary-700 dark:hover:bg-primary-500 focus-visible:ring-primary-500',
  secondary: 'bg-secondary-600 text-white hover:bg-secondary-700 dark:hover:bg-secondary-500 focus-visible:ring-secondary-500',
  outline: 'bg-transparent border border-gray-300 text-gray-700 hover:bg-gray-50 focus-visible:ring-primary-500',
  ghost: 'bg-transparent text-gray-700 hover:bg-gray-100 focus-visible:ring-gray-400',
  danger: 'bg-error-600 text-white hover:bg-error-700 dark:hover:bg-error-500 focus-visible:ring-error-500',
  link: 'bg-transparent text-primary-600 hover:underline p-0 h-auto focus-visible:ring-0',
}

const sizes = {
  sm: 'text-sm px-3 py-1.5 h-8',
  md: 'text-sm px-4 py-2 h-10',
  lg: 'text-base px-6 py-3 h-12',
}

export function Button({
  children,
  className,
  variant = 'primary',
  size = 'md',
  fullWidth = false,
  isLoading = false,
  leftIcon,
  rightIcon,
  disabled,
  type = 'button',
  ...props
}: ButtonProps) {
  const isDisabled = disabled || isLoading

  return (
    <button
      type={type}
      className={cn(
        'inline-flex items-center justify-center rounded-md font-medium transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
        variants[variant],
        sizes[size],
        fullWidth && 'w-full',
        isDisabled && 'opacity-60 cursor-not-allowed',
        variant !== 'link' && 'shadow-xs',
        className,
      )}
      disabled={isDisabled}
      {...props}
    >
      {isLoading && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
      {!isLoading && leftIcon && <span className="mr-2">{leftIcon}</span>}
      {children}
      {!isLoading && rightIcon && <span className="ml-2">{rightIcon}</span>}
    </button>
  )
}
