import { CheckCircle2, Clock, ExternalLink, Mail, RefreshCw, Trash2, XCircle } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Pagination } from '@/components/ui/Pagination'
import { extractError } from '@/lib/errors'
import { emailLogService, type EmailLogEntry, type EmailLogPage } from '@/services/emailLogService'

const UNCONFIRMED_AFTER_MINUTES = 5

export function EmailLogPanel() {
  const [page, setPage] = useState(1)
  const [log, setLog] = useState<EmailLogPage | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [clearOpen, setClearOpen] = useState(false)
  const [clearing, setClearing] = useState(false)

  const load = useCallback(async (target: number) => {
    setLoading(true)
    setError(null)
    try {
      setLog(await emailLogService.list(target))
    } catch (e) {
      setError(extractError(e))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load(page)
  }, [load, page])

  async function handleClear() {
    setClearing(true)
    try {
      await emailLogService.clear()
      setClearOpen(false)
      if (page === 1) await load(1)
      else setPage(1)
    } catch (e) {
      setError(extractError(e))
    } finally {
      setClearing(false)
    }
  }

  const entries = log?.data ?? []

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="rounded-lg bg-primary-600 p-2 text-white"><Mail className="h-5 w-5" /></span>
            <div>
              <h2 className="font-semibold text-gray-900">Email log</h2>
              <p className="mt-1 text-sm text-gray-500">
                Emails the system sent, such as password reset links. Only the recipient and subject are kept, never the message body.
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" isLoading={loading} onClick={() => load(page)} aria-label="Refresh">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {error && <Alert type="error" className="mb-4">{error}</Alert>}

        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-gray-500">{log ? `${log.meta.total} email${log.meta.total === 1 ? '' : 's'} recorded` : ''}</p>
          <Button size="sm" variant="danger" leftIcon={<Trash2 className="h-4 w-4" />} disabled={!log || log.meta.total === 0} onClick={() => setClearOpen(true)}>
            Clear Log
          </Button>
        </div>

        {entries.length === 0 ? (
          <p className="text-sm text-gray-500">{loading ? 'Loading…' : 'No emails sent yet.'}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs font-medium uppercase text-gray-500">
                  <th className="py-2 pr-3">When</th>
                  <th className="py-2 pr-3">To</th>
                  <th className="py-2 pr-3">Subject</th>
                  <th className="py-2 pr-3">Type</th>
                  <th className="py-2 pr-3">Result</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr key={entry.id} className="border-t border-gray-100 align-top">
                    <td className="whitespace-nowrap py-2 pr-3 text-gray-700">{new Date(entry.created_at).toLocaleString()}</td>
                    <td className="break-all py-2 pr-3 text-gray-900">{entry.recipients}</td>
                    <td className="py-2 pr-3 text-gray-700">{entry.subject ?? '—'}</td>
                    <td className="py-2 pr-3 text-gray-700">{entry.type ?? '—'}</td>
                    <td className="py-2 pr-3">
                      <EmailResult entry={entry} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {log && (
          <div className="mt-4">
            <Pagination page={log.meta.current_page} lastPage={log.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </CardContent>

      <ConfirmDialog
        open={clearOpen}
        onOpenChange={setClearOpen}
        onConfirm={handleClear}
        title="Clear email log?"
        description="This permanently removes every entry from the email log. Emails already sent are not affected, and the mail provider keeps its own records."
        confirmText="Clear Log"
        loading={clearing}
      />
    </Card>
  )
}

function EmailResult({ entry }: { entry: EmailLogEntry }) {
  if (entry.status === 'sent') {
    return (
      <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="inline-flex items-center gap-1 text-success-700">
          <CheckCircle2 className="h-4 w-4" /> Sent
        </span>
        {entry.provider_message_id && (
          <a
            href={`https://resend.com/emails/${entry.provider_message_id}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 text-xs text-primary-700 hover:underline"
          >
            Resend <ExternalLink className="h-3 w-3" />
          </a>
        )}
      </span>
    )
  }

  const minutes = (Date.now() - new Date(entry.created_at).getTime()) / 60000

  return minutes > UNCONFIRMED_AFTER_MINUTES ? (
    <span className="inline-flex items-center gap-1 text-error-700">
      <XCircle className="h-4 w-4" /> Not sent
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 text-gray-600">
      <Clock className="h-4 w-4" /> Sending
    </span>
  )
}
