const OPTIONAL_SUFFIX = /\s*\(optional\)$/i

export function FieldLabelText({ label }: { label: string }) {
  if (!OPTIONAL_SUFFIX.test(label)) return <>{label}</>

  return (
    <>
      {label.replace(OPTIONAL_SUFFIX, '')} <span className="text-xs font-normal text-red-500">(optional)</span>
    </>
  )
}
