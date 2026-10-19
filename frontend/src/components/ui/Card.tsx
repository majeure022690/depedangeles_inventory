import { type HTMLAttributes, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode
  hoverable?: boolean
}

export function Card({ children, className, hoverable = false, ...props }: CardProps) {
  return (
    <div
      className={cn(
        'bg-white rounded-lg border border-gray-200 shadow-xs overflow-hidden',
        hoverable && 'transition-all duration-200 hover:shadow-md hover:-translate-y-0.5',
        className,
      )}
      {...props}
    >
      {children}
    </div>
  )
}

const CARD_PADDING = 'p-[clamp(1rem,0.85rem_+_0.75vw,1.5rem)]'

export function CardHeader({ children, className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn(CARD_PADDING, 'border-b border-gray-200', className)} {...props}>
      {children}
    </div>
  )
}

export function CardContent({ children, className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn(CARD_PADDING, className)} {...props}>
      {children}
    </div>
  )
}

export function CardFooter({ children, className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn(CARD_PADDING, 'border-t border-gray-200 bg-gray-50', className)} {...props}>
      {children}
    </div>
  )
}
