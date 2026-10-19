import { ChevronLeft, ChevronRight } from 'lucide-react'

interface PaginationProps {
  page: number
  lastPage: number
  onPageChange: (page: number) => void
}

export function Pagination({ page, lastPage, onPageChange }: PaginationProps) {
  if (lastPage <= 1) return null

  return (
    <div className="flex items-center justify-center gap-1">
      <button
        type="button"
        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 text-gray-600 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-gray-50"
        onClick={() => onPageChange(page - 1)}
        disabled={page <= 1}
        aria-label="Previous page"
      >
        <ChevronLeft className="h-4 w-4" />
      </button>
      <span className="px-3 text-sm text-gray-600">
        Page {page} of {lastPage}
      </span>
      <button
        type="button"
        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 text-gray-600 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-gray-50"
        onClick={() => onPageChange(page + 1)}
        disabled={page >= lastPage}
        aria-label="Next page"
      >
        <ChevronRight className="h-4 w-4" />
      </button>
    </div>
  )
}
