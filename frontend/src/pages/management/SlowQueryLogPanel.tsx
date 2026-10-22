import { isAxiosError } from 'axios'
import { ArrowDownUp, Download, RefreshCw, TimerOff, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { PasswordConfirmDialog } from '@/components/ui/PasswordConfirmDialog'
import { useAuth } from '@/context/AuthContext'
import { systemLogService, type SlowQueryStats, type SlowQueryVariables } from '@/services/systemLogService'

export function SlowQueryLogPanel() {
  const { can } = useAuth()
  const canManage = can('security_settings.manage')

  const [logs, setLogs] = useState<string[]>([])
  const [enabled, setEnabled] = useState(true)
  const [stats, setStats] = useState<SlowQueryStats | null>(null)
  const [variables, setVariables] = useState<SlowQueryVariables | null>(null)
  const [loading, setLoading] = useState(true)
  const [reversed, setReversed] = useState(true)
  const [downloading, setDownloading] = useState(false)
  const [clearConfirmOpen, setClearConfirmOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const [logResult, statusResult] = await Promise.all([systemLogService.slowQueryLogs(), systemLogService.slowQueryStatus()])
      setLogs(logResult.logs)
      setEnabled(logResult.enabled)
      setStats(logResult.stats)
      setVariables(statusResult.variables)
    } catch (requestError) {
      setError(isAxiosError(requestError) ? requestError.response?.data?.message ?? 'Unable to load the slow query log.' : 'Unable to load the slow query log.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManage) load()
  }, [canManage])

  async function handleDownload() {
    setDownloading(true)
    try {
      await systemLogService.downloadSlowQueryLogs()
    } catch {
      setError('Unable to download the slow query log.')
    } finally {
      setDownloading(false)
    }
  }

  async function handleClear(currentPassword: string) {
    await systemLogService.clearSlowQueryLogs(currentPassword)
    void load()
  }

  if (!canManage) return null

  const displayedLogs = reversed ? [...logs].reverse() : logs

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="rounded-lg bg-primary-600 p-2 text-white"><TimerOff className="h-5 w-5" /></span>
            <div>
              <h2 className="font-semibold text-gray-900">Slow query log</h2>
              <p className="mt-1 text-sm text-gray-500">Database queries that took longer than the threshold. Only the SQL is kept, never the values in it.</p>
            </div>
          </div>
          <Button variant="ghost" size="sm" isLoading={loading} onClick={load} aria-label="Refresh">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {error && <Alert type="error" className="mb-4">{error}</Alert>}

        {!enabled && <Alert type="info" className="mb-4">Slow query logging is off. Set DB_SLOW_QUERY_MS (for example 1000) on the server to turn it on.</Alert>}

        {stats && (
          <div className="mb-3 flex flex-wrap gap-2">
            <Badge>Entries: {stats.total_count}</Badge>
            <Badge>Slowest: {stats.slowest}</Badge>
            <Badge>Avg: {stats.avg_time}</Badge>
            <Badge>Threshold: {stats.long_query_time ?? '—'}s</Badge>
            <Badge>Env: {stats.environment}</Badge>
            <Badge>Updated: {stats.last_update ?? '—'}</Badge>
          </div>
        )}

        <div className="mb-3 flex flex-wrap gap-2">
          <Button size="sm" variant="outline" leftIcon={<ArrowDownUp className="h-4 w-4" />} onClick={() => setReversed((v) => !v)}>
            {reversed ? 'Newest First' : 'Oldest First'}
          </Button>
          <Button size="sm" variant="outline" leftIcon={<Download className="h-4 w-4" />} isLoading={downloading} onClick={handleDownload} disabled={!enabled}>
            Download Full Log
          </Button>
          <Button size="sm" variant="danger" leftIcon={<Trash2 className="h-4 w-4" />} onClick={() => setClearConfirmOpen(true)} disabled={!enabled}>
            Clear Log
          </Button>
        </div>

        <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-gray-200 bg-gray-900 p-3 font-mono text-[11px] text-gray-100">
          {displayedLogs.join('\n') || (loading ? 'Loading…' : 'No slow query entries.')}
        </pre>

        {variables && (
          <details className="mt-3 rounded-lg border border-gray-200">
            <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700">Logging settings</summary>
            <div className="divide-y divide-gray-100 border-t border-gray-200 px-3">
              {Object.entries(variables).map(([key, value]) => (
                <div key={key} className="flex items-center justify-between py-2 text-xs">
                  <span className="font-mono text-gray-500">{key}</span>
                  <span className="font-mono text-gray-900">{value}</span>
                </div>
              ))}
            </div>
          </details>
        )}
      </CardContent>

      <PasswordConfirmDialog
        title="Clear slow query log?"
        open={clearConfirmOpen}
        onOpenChange={setClearConfirmOpen}
        onConfirm={handleClear}
        description="This permanently erases the current slow query log and cannot be undone. Enter your password to clear it."
        confirmText="Clear Log"
        confirmVariant="danger"
        fallbackError="Unable to clear the slow query log."
      />
    </Card>
  )
}
