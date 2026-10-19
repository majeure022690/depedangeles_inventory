import { PagePlaceholder } from '@/components/PagePlaceholder'
import { usePageTitle } from '@/hooks/usePageTitle'

// Stub registered by the Phase 4.0 shell; replaced by its batch (see decoupled-spa-stack-plan.md, Phase 4).
export function ReferenceDataIndex() {
  usePageTitle('Reference Data')
  return <PagePlaceholder title="Reference Data" />
}
