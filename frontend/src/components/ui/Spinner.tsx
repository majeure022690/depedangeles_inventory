import { Loader2 } from 'lucide-react'

import { cn } from '@/lib/utils'

export function Spinner({ className, full }: { className?: string; full?: boolean }) {
  const spinner = <Loader2 className={cn('h-6 w-6 animate-spin text-primary-600', className)} />

  if (!full) return spinner

  return <div className="flex justify-center py-16">{spinner}</div>
}
