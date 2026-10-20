import { isAxiosError } from 'axios'
import { ArrowDownUp, ChevronDown, ChevronRight, Download, FileWarning, RefreshCw, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useAuth } from '@/context/AuthContext'
import { systemLogService, type ErrorLogEntry, type ErrorLogStats } from '@/services/systemLogService'

const levelColor: Record<string, 'error' | 'warning' | 'gray'> = {
  ERROR: 'error',
  CRITICAL: 'error',
  EMERGENCY: 'error',
  ALERT: 'error',
  WARNING: 'warning',
}

export function ErrorLogPanel() {
  const { can } = useAuth()
  const canManage = can('security_settings.manage')

  const [entries, setEntries] = useState<ErrorLogEntry[]>([])
  const [stats, setStats] = useState<ErrorLogStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [reversed, setReversed] = useState(true)
  const [expanded, setExpanded] = useState<Set<number>>(new Set())
  const [downloading, setDownloading] = useState(false)
  const [clearing, setClearing] = useState(false)
  const [clearConfirmOpen, setClearConfirmOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const result = await systemLogService.errorLogs()
      setEntries(result.logs)
      setStats(result.stats)
      setExpanded(new Set())
    } catch (requestError) {
      setError(isAxiosError(requestError) ? requestError.response?.data?.message ?? 'Unable to load the error log.' : 'Unable to load the error log.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManage) load()
  }, [canManage])

  function toggle(index: number) {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(index)) next.delete(index)
      else next.add(index)
      return next
    })
  }

  async function handleDownload() {
    setDownloading(true)
    try {
      await systemLogService.downloadErrorLogs()
    } catch {
      setError('Unable to download the error log.')
    } finally {
      setDownloading(false)
    }
  }

  async function handleClear() {
    setClearing(true)
    try {
      await systemLogService.clearErrorLogs()
      setClearConfirmOpen(false)
      await load()
    } catch {
      setError('Unable to clear the error log.')
    } finally {
      setClearing(false)
    }
  }

  if (!canManage) return null

  const displayedEntries = reversed ? [...entries].reverse() : entries

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="rounded-lg bg-primary-600 p-2 text-white"><FileWarning className="h-5 w-5" /></span>
            <div>
              <h2 className="font-semibold text-gray-900">Laravel error log</h2>
              <p className="mt-1 text-sm text-gray-500">Recent application errors and warnings from storage/logs/laravel.log.</p>
            </div>
          </div>
          <Button variant="ghost" size="sm" isLoading={loading} onClick={load} aria-label="Refresh">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {error && <Alert type="error" className="mb-4">{error}</Alert>}

        {stats && (
          <div className="mb-3 flex flex-wrap gap-2">
            <Badge>Entries: {stats.total_lines}</Badge>
            <Badge color="error">Errors: {stats.error_count}</Badge>
            <Badge color="warning">Warnings: {stats.warning_count}</Badge>
            <Badge>Updated: {stats.last_update ?? '—'}</Badge>
          </div>
        )}

        <div className="mb-3 flex flex-wrap gap-2">
          <Button size="sm" variant="outline" leftIcon={<ArrowDownUp className="h-4 w-4" />} onClick={() => setReversed((v) => !v)}>
            {reversed ? 'Newest First' : 'Oldest First'}
          </Button>
          <Button size="sm" variant="outline" leftIcon={<Download className="h-4 w-4" />} isLoading={downloading} onClick={handleDownload}>
            Download Full Log
          </Button>
          <Button size="sm" variant="danger" leftIcon={<Trash2 className="h-4 w-4" />} onClick={() => setClearConfirmOpen(true)}>
            Clear Log
          </Button>
        </div>

        <div className="max-h-96 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50">
          {displayedEntries.length === 0 ? (
            <div className="p-4 text-sm text-gray-500">{loading ? 'Loading…' : 'No log entries.'}</div>
          ) : (
            <div className="divide-y divide-gray-200">
              {displayedEntries.map((entry, index) => (
                <div key={index}>
                  <div
                    className={`flex items-start gap-2 px-3 py-2 text-xs ${entry.trace ? 'cursor-pointer hover:bg-gray-100' : ''}`}
                    onClick={() => entry.trace && toggle(index)}
                  >
                    {entry.trace ? (
                      expanded.has(index) ? <ChevronDown className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" /> : <ChevronRight className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                    ) : (
                      <span className="w-3.5 shrink-0" />
                    )}
                    <Badge color={levelColor[entry.level] ?? 'gray'} className="shrink-0">{entry.level}</Badge>
                    <span className="break-all font-mono text-gray-700">{entry.summary}</span>
                  </div>
                  {entry.trace && expanded.has(index) && (
                    <pre className="overflow-x-auto whitespace-pre-wrap break-all bg-gray-900 px-3 py-2 font-mono text-[11px] text-gray-100">{entry.trace}</pre>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </CardContent>

      <ConfirmDialog
        open={clearConfirmOpen}
        onOpenChange={setClearConfirmOpen}
        onConfirm={handleClear}
        title="Clear error log?"
        description="This permanently erases the current Laravel error log. This cannot be undone."
        confirmText="Clear Log"
        loading={clearing}
      />
    </Card>
  )
}
