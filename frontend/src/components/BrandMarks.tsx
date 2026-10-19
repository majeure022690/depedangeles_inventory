import { BRANDING } from '@/lib/branding'
import { cn } from '@/lib/utils'

// The division seal, shown wherever the portal identifies itself.
export function BrandMarks({ className, imageClassName }: { className?: string; imageClassName?: string }) {
  return (
    <div className={cn('flex shrink-0 items-center gap-1.5', className)}>
      <img src={BRANDING.seal} alt={BRANDING.sealAlt} className={cn('h-10 w-10 shrink-0', imageClassName)} />
    </div>
  )
}
