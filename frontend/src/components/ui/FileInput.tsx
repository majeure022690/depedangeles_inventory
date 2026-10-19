import { Upload } from 'lucide-react'
import { useRef } from 'react'

import { FieldLabelText } from '@/components/ui/FieldLabelText'

interface FileInputProps {
  label?: string
  accept?: string
  value: File | null
  onChange: (file: File | null) => void
}

export function FileInput({ label, accept, value, onChange }: FileInputProps) {
  const inputRef = useRef<HTMLInputElement>(null)

  return (
    <div className="w-full">
      {label && <label className="mb-1 block text-sm font-medium text-gray-700"><FieldLabelText label={label} /></label>}
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
        >
          <Upload className="h-4 w-4" />
          Choose File
        </button>
        <span className="truncate text-sm text-gray-500">{value?.name ?? 'No file selected'}</span>
      </div>
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="hidden"
        onChange={(e) => onChange(e.target.files?.[0] ?? null)}
      />
    </div>
  )
}
