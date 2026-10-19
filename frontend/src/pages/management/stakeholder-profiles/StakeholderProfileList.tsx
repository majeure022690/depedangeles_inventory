import { PagePlaceholder } from '@/components/PagePlaceholder'
import { usePageTitle } from '@/hooks/usePageTitle'

// Stub registered by the Phase 4.0 shell; replaced by its batch (see decoupled-spa-stack-plan.md, Phase 4).
export function StakeholderProfileList() {
  usePageTitle('Stakeholder Profiles')
  return <PagePlaceholder title="Stakeholder Profiles" />
}
