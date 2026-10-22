import { AlertCircle, AlertTriangle, CheckCircle2, Info } from 'lucide-react'
import { type ReactNode, type Ref } from 'react'

import { cn } from '@/lib/utils'

interface AlertProps {
  type?: 'info' | 'success' | 'warning' | 'error'
  children: ReactNode
  className?: string
  id?: string
  role?: 'status' | 'alert'
  ref?: Ref<HTMLDivElement>
  tabIndex?: number
}

const config = {
  info: { icon: Info, className: 'bg-primary-50 text-primary-800 border-primary-200' },
  success: { icon: CheckCircle2, className: 'bg-success-50 text-success-800 border-success-200' },
  warning: { icon: AlertTriangle, className: 'bg-warning-50 text-warning-800 border-warning-200' },
  error: { icon: AlertCircle, className: 'bg-error-50 text-error-800 border-error-200' },
}

export function Alert({ type = 'info', children, className, id, role, ref, tabIndex }: AlertProps) {
  const { icon: Icon, className: typeClassName } = config[type]

  return (
    <div ref={ref} id={id} role={role} tabIndex={tabIndex} className={cn('flex items-start gap-2 rounded-lg border px-3 py-2 text-sm', typeClassName, className)}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <div>{children}</div>
    </div>
  )
}
