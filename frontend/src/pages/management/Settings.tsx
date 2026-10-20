import { useSearchParams } from 'react-router-dom'

import { TabPanel, Tabs } from '@/components/ui/Tabs'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import type { PermissionName } from '@/lib/permissions'
import { EmailLogPanel } from '@/pages/management/EmailLogPanel'
import { ErrorLogPanel } from '@/pages/management/ErrorLogPanel'
import { SecuritySettingsPanel } from '@/pages/management/SecuritySettingsPanel'
import { SlowQueryLogPanel } from '@/pages/management/SlowQueryLogPanel'

const TABS: { value: string; label: string; permission: PermissionName }[] = [
  { value: 'security', label: 'Security & Logs', permission: 'security_settings.manage' },
  { value: 'email', label: 'Email Log', permission: 'email_log.manage' },
]

export function Settings() {
  usePageTitle('Settings')
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const tabs = TABS.filter((t) => can(t.permission))
  const requested = searchParams.get('tab')
  const tab = tabs.find((t) => t.value === requested)?.value ?? tabs[0]?.value

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Settings</h1>
          <p className="text-sm text-gray-500">Security settings, emails, and system logs for the portal.</p>
        </div>
      </div>

      <Tabs id="settings" label="Settings sections" tabs={tabs} value={tab ?? ''} onChange={(value) => setSearchParams({ tab: value }, { replace: true })} />

      <TabPanel tabsId="settings" value="security" active={tab === 'security'}>
        <div className="space-y-4">
          <SecuritySettingsPanel />
          <ErrorLogPanel />
          <SlowQueryLogPanel />
        </div>
      </TabPanel>

      <TabPanel tabsId="settings" value="email" active={tab === 'email'}>
        <EmailLogPanel />
      </TabPanel>
    </div>
  )
}
