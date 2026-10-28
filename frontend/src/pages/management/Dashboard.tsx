import { Building2, RefreshCw, Sparkles, Tag } from 'lucide-react'
import { useEffect, useState } from 'react'

import { EquipmentDistributionCard } from '@/components/dashboard/EquipmentDistributionCard'
import { EquipmentOverviewSection } from '@/components/dashboard/EquipmentOverviewSection'
import { InternetConnectivitySection } from '@/components/dashboard/InternetConnectivitySection'
import { PropertyValueCard } from '@/components/dashboard/PropertyValueCard'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import { dashboardService } from '@/services/dashboardService'
import type { DashboardSummary } from '@/types/dashboard'

function formatAsOf(date: Date): string {
  return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
}

function isoDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function DashboardSkeleton() {
  return (
    <div className="space-y-4" aria-busy="true" aria-label="Loading dashboard">
      <div className="grid gap-4 md:grid-cols-2">
        {[1, 2].map((item) => (
          <div key={item} className="h-36 animate-pulse rounded-lg bg-gray-200" />
        ))}
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[1, 2, 3, 4, 5, 6].map((item) => (
          <div key={item} className="h-32 animate-pulse rounded-lg bg-gray-200" />
        ))}
      </div>
      <div className="h-72 animate-pulse rounded-lg bg-gray-200" />
    </div>
  )
}

export function Dashboard() {
  usePageTitle('Dashboard')
  const { user, can } = useAuth()
  const scopeNotice =
    user?.data_scope === 'own' && user.personnel_id === null && (can('personnel.view') || can('equipment.view'))
      ? user.employee_no === null
        ? 'Your account has no DepEd employee number in PRIME. Contact your administrator.'
        : `No personnel record matches employee number ${user.employee_no}. Contact your administrator.`
      : user?.data_scope === 'school' && user.office_id === null
        ? "Your account isn't assigned to an office yet. Your office comes from PRIME. Contact your administrator."
        : null
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [asOf, setAsOf] = useState(() => new Date())
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function loadSummary(showRefreshState = false) {
    if (showRefreshState) setRefreshing(true)
    else setLoading(true)
    setError(null)
    try {
      setSummary(await dashboardService.summary())
      setAsOf(new Date())
    } catch (requestError) {
      setError(extractError(requestError, 'Unable to load dashboard data.'))
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }

  useEffect(() => {
    loadSummary()
  }, [])

  const ppe = summary?.ppe
  const semi = summary?.semi_expendable
  const overview = summary?.equipment_overview
  const distribution = summary?.equipment_distribution
  const connectivity = summary?.internet_connectivity
  const hasAnySection = !!summary && Object.keys(summary).length > 0

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Dashboard</h1>
          <p className="text-sm text-gray-500">ICT asset and connectivity overview</p>
        </div>
        <div className="page-actions">
          <p className="text-sm text-gray-500">
            As of <time dateTime={isoDate(asOf)}>{formatAsOf(asOf)}</time>
          </p>
          <Button variant="outline" size="sm" isLoading={refreshing} disabled={loading} leftIcon={<RefreshCw className="h-4 w-4" />} onClick={() => loadSummary(true)}>
            Refresh data
          </Button>
        </div>
      </div>

      {error && <Alert type="error">{error}</Alert>}
      {scopeNotice && <Alert type="warning">{scopeNotice}</Alert>}

      {loading ? (
        <DashboardSkeleton />
      ) : summary && !hasAnySection ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
            <Sparkles className="h-8 w-8 text-primary-500" aria-hidden="true" />
            <h2 className="text-lg font-semibold text-gray-900">Welcome to the Division ICT Inventory System</h2>
            <p className="max-w-md text-sm text-gray-500">
              Your account doesn&apos;t have access to any dashboard section yet. Once an administrator gives your role dashboard access, summaries of
              property values, ICT equipment and internet connectivity will appear here.
            </p>
          </CardContent>
        </Card>
      ) : summary ? (
        <>
          {(ppe || semi) && (
            <section aria-label="Property values" className="grid gap-4 md:grid-cols-[repeat(auto-fit,minmax(20rem,1fr))]">
              {ppe && (
                <PropertyValueCard
                  icon={Building2}
                  title="Total Property, Plant and Equipment"
                  caption="₱50,000 and above"
                  value={ppe}
                  iconClassName="bg-primary-600 text-white"
                  cardClassName="border-primary-100 bg-primary-50/60"
                />
              )}
              {semi && (
                <PropertyValueCard
                  icon={Tag}
                  title="Total Semi-Expendable Property"
                  caption="Below ₱50,000"
                  value={semi}
                  iconClassName="bg-cyan-600 text-white"
                  cardClassName="border-cyan-100 bg-cyan-50/60"
                />
              )}
            </section>
          )}

          {(overview || distribution) && (
            <div className={overview && distribution ? 'grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]' : undefined}>
              {overview && (
                <section aria-labelledby="equipment-overview-heading" className="h-full">
                  <EquipmentOverviewSection overview={overview} canViewEquipment={can('equipment.view')} />
                </section>
              )}
              {distribution && (
                <section aria-labelledby="equipment-distribution-heading" className="h-full">
                  <EquipmentDistributionCard distribution={distribution} />
                </section>
              )}
            </div>
          )}

          {connectivity && (
            <section aria-labelledby="internet-connectivity-heading">
              <InternetConnectivitySection summary={connectivity} canViewAccounts={can('isp_accounts.view')} />
            </section>
          )}
        </>
      ) : null}
    </div>
  )
}
