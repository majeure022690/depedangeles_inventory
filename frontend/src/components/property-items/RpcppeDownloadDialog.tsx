import { Download } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import {
  extractError,
  extractFieldErrors,
  isNotFound,
  isTooManyRequests,
  OUT_OF_REACH_MESSAGE,
  TOO_MANY_ATTEMPTS_MESSAGE,
  type FieldErrors,
} from '@/lib/errors'
import { reportService } from '@/services/reportService'
import type { ReportOptions, RpcppeReportParams } from '@/types/report'

/** School years start in June. */
function currentSchoolYear(today = new Date()): string {
  const start = today.getMonth() >= 5 ? today.getFullYear() : today.getFullYear() - 1
  return `${start}-${start + 1}`
}

const EMPTY_FORM: RpcppeReportParams = {
  school_year: currentSchoolYear(),
  accountable_officer_id: null,
  assumed_on: '',
  prepared_by: '',
  school_head: '',
  supply_officer: '',
}

/**
 * The RPCPPE workbook for one office: every category sheet from IRIS equipment
 * plus School Property, then books and furniture per teacher.
 */
export function RpcppeDownloadDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [options, setOptions] = useState<ReportOptions | null>(null)
  const [officeId, setOfficeId] = useState('')
  const [officerList, setOfficerList] = useState<{
    officeId: string
    officers: NonNullable<ReportOptions['accountable_officers']>
  } | null>(null)
  const [form, setForm] = useState<RpcppeReportParams>(EMPTY_FORM)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [generating, setGenerating] = useState(false)

  useEffect(() => {
    if (!open || options) return
    reportService
      .options()
      .then((loaded) => {
        setOptions(loaded)
        if (loaded.offices.length === 1) setOfficeId(String(loaded.offices[0].value))
      })
      .catch((error: unknown) => setMessage(extractError(error, 'Unable to load the report options.')))
  }, [open, options])

  useEffect(() => {
    if (officeId === '') return
    let cancelled = false
    reportService
      .options(Number(officeId))
      .then((loaded) => {
        if (!cancelled)
          setOfficerList({
            officeId,
            officers: loaded.accountable_officers ?? [],
          })
      })
      .catch(() => {
        if (!cancelled) setOfficerList({ officeId, officers: [] })
      })
    return () => {
      cancelled = true
    }
  }, [officeId])

  const officers = officerList && officerList.officeId === officeId ? officerList.officers : []

  function set<K extends keyof RpcppeReportParams>(key: K, value: RpcppeReportParams[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (officeId === '') return
    setGenerating(true)
    setErrors({})
    setMessage(null)
    try {
      await reportService.downloadRpcppe(Number(officeId), form)
      onOpenChange(false)
    } catch (error) {
      setErrors(extractFieldErrors(error))
      setMessage(
        isNotFound(error) ? OUT_OF_REACH_MESSAGE : isTooManyRequests(error) ? TOO_MANY_ATTEMPTS_MESSAGE : extractError(error, 'Unable to generate the report.'),
      )
    } finally {
      setGenerating(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !generating && onOpenChange(next)}>
      <DialogContent size="xl">
        <DialogTitle>Download RPCPPE</DialogTitle>
        <DialogDescription>
          Report on the Physical Count of Property, Plant and Equipment: every category sheet from IRIS equipment and School Property, plus books and furniture
          per teacher.
        </DialogDescription>
        <form onSubmit={handleSubmit} noValidate className="mt-4 space-y-4">
          {message && (
            <Alert type="error">
              <span role="alert">{message}</span>
            </Alert>
          )}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <FormField id="rpcppe-office" label="Office" required>
              {(aria) => (
                <Select
                  id="rpcppe-office"
                  value={officeId}
                  placeholder="Select an office"
                  options={(options?.offices ?? []).map((office) => ({
                    value: String(office.value),
                    label: office.label,
                  }))}
                  disabled={!options || options.offices.length === 1}
                  onChange={(event) => {
                    setOfficeId(event.target.value)
                    set('accountable_officer_id', null)
                  }}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="rpcppe-school-year" label="School year" required error={errors.school_year}>
              {(aria) => (
                <Input
                  id="rpcppe-school-year"
                  value={form.school_year}
                  placeholder="2025-2026"
                  maxLength={9}
                  onChange={(event) => set('school_year', event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField
              id="rpcppe-officer"
              label="Accountable officer (optional)"
              hint="Limit the report to one officer's items and print their name in the header."
              error={errors.accountable_officer_id}
            >
              {(aria) => (
                <Select
                  id="rpcppe-officer"
                  value={form.accountable_officer_id ?? ''}
                  placeholder="Everyone at this office"
                  options={officers.map((officer) => ({
                    value: officer.value,
                    label: officer.position ? `${officer.label} (${officer.position})` : officer.label,
                  }))}
                  disabled={officeId === ''}
                  onChange={(event) => set('accountable_officer_id', event.target.value === '' ? null : Number(event.target.value))}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="rpcppe-assumed-on" label="Accountability assumed on (optional)" error={errors.assumed_on}>
              {(aria) => (
                <Input id="rpcppe-assumed-on" type="date" value={form.assumed_on} onChange={(event) => set('assumed_on', event.target.value)} {...aria} />
              )}
            </FormField>
            <FormField id="rpcppe-prepared-by" label="Prepared by (optional)" error={errors.prepared_by}>
              {(aria) => (
                <Input
                  id="rpcppe-prepared-by"
                  value={form.prepared_by}
                  maxLength={150}
                  onChange={(event) => set('prepared_by', event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="rpcppe-school-head" label="School head (optional)" error={errors.school_head}>
              {(aria) => (
                <Input
                  id="rpcppe-school-head"
                  value={form.school_head}
                  maxLength={150}
                  onChange={(event) => set('school_head', event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
            <FormField id="rpcppe-supply-officer" label="Supply officer (optional)" error={errors.supply_officer}>
              {(aria) => (
                <Input
                  id="rpcppe-supply-officer"
                  value={form.supply_officer}
                  maxLength={150}
                  onChange={(event) => set('supply_officer', event.target.value)}
                  {...aria}
                />
              )}
            </FormField>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={generating}>
              Cancel
            </Button>
            <Button type="submit" leftIcon={<Download className="h-4 w-4" />} isLoading={generating} disabled={officeId === ''}>
              Download Excel
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
