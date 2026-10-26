import { usePageTitle } from '@/hooks/usePageTitle'
import { ErrorLogPanel } from '@/pages/management/ErrorLogPanel'
import { SecuritySettingsPanel } from '@/pages/management/SecuritySettingsPanel'
import { SlowQueryLogPanel } from '@/pages/management/SlowQueryLogPanel'

export function Settings() {
  usePageTitle('Settings')

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Settings</h1>
        <p className="text-sm text-gray-500">Security settings and system logs.</p>
      </div>

      <SecuritySettingsPanel />
      <ErrorLogPanel />
      <SlowQueryLogPanel />
    </div>
  )
}
