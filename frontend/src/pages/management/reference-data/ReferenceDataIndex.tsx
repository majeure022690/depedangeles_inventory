import { Building2, ChevronRight, Database, Library, RefreshCw } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/Skeleton'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import { referenceDataService } from '@/services/referenceDataService'
import type { ReferenceDataOverview } from '@/types/reference-data'

function plural(count: number, singular: string, pluralForm = `${singular}s`) {
  return `${count.toLocaleString()} ${count === 1 ? singular : pluralForm}`
}

function TableCard({ to, title, badge, count, accent = false }: { to: string; title: string; badge: ReactNode; count: string; accent?: boolean }) {
  return (
    <Link
      to={to}
      className={
        'group flex h-full flex-col justify-between gap-4 rounded-lg border bg-white p-4 shadow-xs transition-all hover:-translate-y-0.5 hover:border-primary-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 ' +
        (accent ? 'border-primary-200' : 'border-gray-200')
      }
    >
      <span className="flex items-center justify-between gap-2">
        <span className="font-semibold text-gray-900">{title}</span>
        <ChevronRight className="h-4 w-4 shrink-0 text-gray-400 transition-transform group-hover:translate-x-0.5 group-hover:text-primary-600" aria-hidden="true" />
      </span>
      <span className="flex items-center justify-between gap-2">
        {badge}
        <span className="text-sm text-gray-500">{count}</span>
      </span>
    </Link>
  )
}

function Section({ icon, title, description, children }: { icon: ReactNode; title: string; description: string; children: ReactNode }) {
  const id = `reference-section-${title.toLowerCase().replace(/\s+/g, '-')}`
  return (
    <section aria-labelledby={id} className="space-y-3">
      <div>
        <h2 id={id} className="flex items-center gap-2 text-base font-semibold text-gray-900">
          <span className="text-gray-400" aria-hidden="true">{icon}</span>
          {title}
        </h2>
        <p className="mt-0.5 text-sm text-gray-500">{description}</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{children}</div>
    </section>
  )
}

export function ReferenceDataIndex() {
  usePageTitle('Reference Data')
  const { can } = useAuth()
  const [overview, setOverview] = useState<ReferenceDataOverview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      setOverview(await referenceDataService.overview())
    } catch (loadError) {
      setError(extractError(loadError, 'Unable to load reference data.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  const tier1 = overview?.tables.filter((table) => table.tier === 1) ?? []
  const tier2 = overview?.tables.filter((table) => table.tier === 2) ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Reference Data</h1>
          <p className="text-sm text-gray-600">The dedicated and grouped lookup tables behind the equipment, personnel, ISP account and office profile forms.</p>
        </div>
        <div className="page-actions">
          <Button variant="outline" size="sm" isLoading={loading && overview !== null} leftIcon={<RefreshCw className="h-4 w-4" />} onClick={load}>
            Refresh
          </Button>
        </div>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {loading && !overview ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-24 rounded-lg" />
          ))}
        </div>
      ) : overview ? (
        <>
          <Section icon={<Database className="h-4 w-4" />} title="Dedicated tables" description="Tier 1: records link to these by id, so renaming an entry updates every record that uses it.">
            {tier1.map((table) => (
              <TableCard
                key={table.key}
                to={`/reference-data/${table.key}`}
                title={table.label}
                badge={<Badge color="primary">Tier 1</Badge>}
                count={plural(table.row_count, 'row')}
              />
            ))}
          </Section>

          <Section icon={<Library className="h-4 w-4" />} title="Grouped libraries" description="Tier 2: small fixed vocabularies grouped by type. Values are copied onto records, so they can't be renamed.">
            {tier2.map((table) => (
              <TableCard
                key={table.key}
                to={`/reference-data/${table.key}`}
                title={table.label}
                badge={<Badge color="gray">Tier 2</Badge>}
                count={plural(table.row_count, 'row')}
              />
            ))}
          </Section>

          {can('office.view') && (
            <div className="border-t border-gray-200 pt-6">
              <Section icon={<Building2 className="h-4 w-4" />} title="Institutional data" description="Schools and division offices have their own full create/edit pages.">
                <TableCard
                  to="/offices"
                  title="Offices"
                  badge={<Badge color="success">Full CRUD</Badge>}
                  count={plural(overview.office_count, 'office')}
                  accent
                />
              </Section>
            </div>
          )}
        </>
      ) : null}
    </div>
  )
}
