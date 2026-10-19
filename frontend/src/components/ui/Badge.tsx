import { type ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface BadgeProps {
  children: ReactNode
  color?: 'gray' | 'primary' | 'secondary' | 'success' | 'warning' | 'error'
  className?: string
}

const colors = {
  gray: 'bg-gray-100 text-gray-700',
  primary: 'bg-primary-100 text-primary-700',
  secondary: 'bg-secondary-100 text-secondary-700',
  success: 'bg-success-100 text-success-700',
  warning: 'bg-warning-100 text-warning-700',
  error: 'bg-error-100 text-error-700',
}

export function Badge({ children, color = 'gray', className }: BadgeProps) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', colors[color], className)}>
      {children}
    </span>
  )
}
