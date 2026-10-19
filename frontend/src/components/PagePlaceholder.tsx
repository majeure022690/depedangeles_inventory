import { Construction } from 'lucide-react'

/**
 * Temporary stand-in for pages not yet ported from the old Vue build.
 * Once a page below is implemented for real, its file stops rendering this
 * and the import is removed — this component itself should end up unused.
 */
export function PagePlaceholder({ title }: { title: string }) {
  return (
    <div className="mx-auto flex max-w-3xl flex-col items-center gap-3 px-4 py-16 text-center">
      <Construction className="h-10 w-10 text-gray-400" />
      <h1 className="text-lg font-semibold text-gray-900">{title}</h1>
      <p className="text-sm text-gray-500">This page is being migrated from the previous build and isn't ready yet.</p>
    </div>
  )
}
