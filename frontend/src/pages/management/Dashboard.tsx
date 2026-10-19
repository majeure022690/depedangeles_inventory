import {
  AlertTriangle,
  ArrowRight,
  CalendarClock,
  FileClock,
  type LucideIcon,
  Monitor,
  PhilippinePeso,
  RefreshCw,
  Sparkles,
  UserCheck,
  Wifi,
} from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

import { BreakdownList } from '@/components/dashboard/BreakdownList'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import { dashboardService } from '@/services/dashboardService'
import type {
  DashboardCount,
  DashboardEquipmentSummary,
  DashboardIspAccountsSummary,
  DashboardPersonnelSummary,
  DashboardRecentTransaction,
  DashboardSummary,
} from '@/types/dashboard'

const CHART_COLORS = ['#2563eb', '#0891b2', '#16a34a', '#d97706', '#7c3aed', '#dc2626', '#0f766e', '#db2777', '#64748b', '#ea580c']

const pesoFormatter = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 })
const pesoFormatterExact = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}

/** Charts are drawn for sighted users; this gives screen readers the same numbers. */
function chartSummary(title: string, items: DashboardCount[]): string {
  return `${title}: ${items.map((item) => `${item.value} ${item.count}`).join(', ') || 'no data'}`
}

function MetricCard({
  icon: Icon,
  label,
  value,
  detail,
  color,
  warn = false,
}: {
  icon: LucideIcon
  label: string
  value: string
  detail?: string
  color: string
  warn?: boolean
}) {
  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm text-gray-500">{label}</p>
          <p className={warn ? 'mt-1 text-2xl font-bold text-warning-700' : 'mt-1 text-2xl font-bold text-gray-900'}>{value}</p>
          {detail && <p className="mt-1 text-xs text-gray-500">{detail}</p>}
        </div>
        <span className={`shrink-0 rounded-lg p-2.5 ${color}`} aria-hidden="true">
          <Icon className="h-5 w-5 text-white" />
        </span>
      </CardContent>
    </Card>
  )
}

function SectionTitle({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 className="text-base font-semibold text-gray-900">{title}</h2>
        {description && <p className="mt-1 text-sm text-gray-500">{description}</p>}
      </div>
      {action}
    </div>
  )
}

function EmptyChart({ message }: { message: string }) {
  return <p className="flex h-full items-center justify-center text-sm text-gray-500">{message}</p>
}

function ConditionChart({ items }: { items: DashboardCount[] }) {
  return (
    <Card>
      <CardHeader>
        <SectionTitle title="By condition" description="Equipment grouped by its recorded condition." />
      </CardHeader>
      <CardContent>
        <div className="h-64" role="img" aria-label={chartSummary('Equipment by condition', items)}>
          {items.length === 0 ? (
            <EmptyChart message="No conditions recorded yet." />
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={items} dataKey="count" nameKey="value" innerRadius={52} outerRadius={84} paddingAngle={3}>
                  {items.map((item, index) => (
                    <Cell key={item.value} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip formatter={(value) => [Number(value).toLocaleString(), 'Items']} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

function CategoryChart({ items }: { items: DashboardCount[] }) {
  const height = Math.max(256, items.length * 34)
  return (
    <Card>
      <CardHeader>
        <SectionTitle title="By category" description="Where the equipment count sits across categories." />
      </CardHeader>
      <CardContent>
        <div style={{ height: items.length === 0 ? 256 : height }} role="img" aria-label={chartSummary('Equipment by category', items)}>
          {items.length === 0 ? (
            <EmptyChart message="No categories recorded yet." />
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={items} layout="vertical" margin={{ top: 0, right: 16, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
                <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} stroke="#64748b" />
                <YAxis dataKey="value" type="category" width={130} tick={{ fontSize: 11 }} stroke="#64748b" />
                <Tooltip formatter={(value) => [Number(value).toLocaleString(), 'Items']} cursor={{ fill: 'rgba(37, 99, 235, 0.08)' }} />
                <Bar dataKey="count" name="Items" fill="#2563eb" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

function EquipmentSection({ summary }: { summary: DashboardEquipmentSummary }) {
  return (
    <section aria-label="Equipment breakdown" className="space-y-4">
      <div className="grid gap-4 xl:grid-cols-2">
        <ConditionChart items={summary.by_condition} />
        <CategoryChart items={summary.by_category} />
      </div>
      <Card>
        <CardHeader>
          <SectionTitle
            title="By disposition status"
            description="Serviceable, unserviceable, disposed and other statuses."
            action={
              <Link to="/portal/equipment" className="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline">
                View equipment <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
              </Link>
            }
          />
        </CardHeader>
        <CardContent>
          <BreakdownList items={summary.by_disposition_status} emptyMessage="No disposition statuses recorded yet." />
        </CardContent>
      </Card>
    </section>
  )
}

function PersonnelSection({ summary }: { summary: DashboardPersonnelSummary }) {
  const data = [
    { name: 'Active', count: summary.active_count, color: '#2563eb' },
    { name: 'Inactive', count: summary.inactive_count, color: '#94a3b8' },
  ].filter((item) => item.count > 0)
  const total = summary.active_count + summary.inactive_count

  return (
    <Card>
      <CardHeader>
        <SectionTitle title="Personnel" description={`${total.toLocaleString()} personnel on record`} />
      </CardHeader>
      <CardContent>
        <div className="h-52" role="img" aria-label={`Personnel: ${summary.active_count} active, ${summary.inactive_count} inactive`}>
          {data.length === 0 ? (
            <EmptyChart message="No personnel recorded yet." />
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={data} dataKey="count" nameKey="name" innerRadius={48} outerRadius={76} paddingAngle={3}>
                  {data.map((item) => (
                    <Cell key={item.name} fill={item.color} />
                  ))}
                </Pie>
                <Tooltip formatter={(value) => [Number(value).toLocaleString(), 'Personnel']} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>
        <dl className="mt-2 grid grid-cols-3 gap-3 border-t border-gray-100 pt-3 text-center text-sm">
          {[
            ['Active', summary.active_count],
            ['Inactive', summary.inactive_count],
            ['OIC assignments', summary.oic_count],
          ].map(([label, count]) => (
            <div key={label} className="flex flex-col-reverse">
              <dt className="text-xs text-gray-500">{label}</dt>
              <dd className="font-semibold text-gray-900">{count.toLocaleString()}</dd>
            </div>
          ))}
        </dl>
      </CardContent>
    </Card>
  )
}

function IspAccountsSection({ summary }: { summary: DashboardIspAccountsSummary }) {
  const expiring = summary.contracts_expiring_soon_count
  return (
    <Card>
      <CardHeader>
        <SectionTitle
          title="ISP accounts"
          description="Internet subscriptions across the division."
          action={
            <Link to="/portal/isp-accounts" className="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline">
              View accounts <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          }
        />
      </CardHeader>
      <CardContent className="space-y-4">
        <div>
          <p className="text-sm text-gray-500">Monthly cost of active contracts</p>
          <p className="mt-1 text-3xl font-bold text-gray-900">{pesoFormatterExact.format(summary.total_monthly_cost)}</p>
        </div>
        <div
          className={
            expiring > 0
              ? 'flex items-start gap-2 rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-800'
              : 'flex items-start gap-2 rounded-md bg-success-50 px-3 py-2 text-sm text-success-800'
          }
        >
          <CalendarClock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>
            <strong>{expiring.toLocaleString()}</strong> active contract{expiring === 1 ? '' : 's'} ending in the next 30 days
          </span>
        </div>
      </CardContent>
    </Card>
  )
}

function RecentTransactions({ entries }: { entries: DashboardRecentTransaction[] }) {
  return (
    <Card>
      <CardHeader>
        <SectionTitle title="Recent equipment activity" description="The latest issuances, transfers, returns and other recorded transactions." />
      </CardHeader>
      {entries.length === 0 ? (
        <CardContent>
          <p className="text-sm text-gray-500">No recent equipment activity.</p>
        </CardContent>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">Recent equipment transactions</caption>
            <thead className="border-b border-gray-200 bg-gray-50">
              <tr>
                {['Equipment', 'Transaction', 'Accountable officer', 'End user', 'Recorded by', 'Date'].map((heading) => (
                  <th key={heading} scope="col" className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    {heading}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {entries.map((entry) => (
                <tr key={entry.id} className="even:bg-gray-50/60">
                  <td className="px-4 py-3">
                    {entry.equipment ? (
                      <Link to={`/portal/equipment/${entry.equipment.id}/edit`} className="group block">
                        <span className="font-medium text-gray-900 group-hover:text-primary-700 group-hover:underline">{entry.equipment.property_no ?? `#${entry.equipment.id}`}</span>
                        {entry.equipment.item && <span className="block text-xs text-gray-500">{entry.equipment.item}</span>}
                      </Link>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <Badge color="primary">{entry.transaction_type}</Badge>
                  </td>
                  <td className="px-4 py-3 text-gray-700">{entry.accountable_officer?.full_name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-700">{entry.end_user?.full_name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-700">{entry.recorded_by?.name ?? '—'}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-gray-500">{formatDateTime(entry.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

export function Dashboard() {
  usePageTitle('Dashboard')
  const { user } = useAuth()
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function loadSummary(showRefreshState = false) {
    if (showRefreshState) setRefreshing(true)
    else setLoading(true)
    setError(null)
    try {
      setSummary(await dashboardService.summary())
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

  const equipment = summary?.equipment
  const personnel = summary?.personnel
  const isp = summary?.isp_accounts
  const transactions = summary?.recent_transactions
  const hasAnySection = !!summary && (equipment !== undefined || personnel !== undefined || isp !== undefined || transactions !== undefined)

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Dashboard</h1>
          <p className="text-sm text-gray-500">Division ICT inventory at a glance{user ? ` for ${user.name}` : ''}.</p>
        </div>
        <div className="page-actions">
          <Button variant="outline" size="sm" isLoading={refreshing} disabled={loading} leftIcon={<RefreshCw className="h-4 w-4" />} onClick={() => loadSummary(true)}>
            Refresh data
          </Button>
        </div>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {loading ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true" aria-label="Loading dashboard">
          {[1, 2, 3, 4].map((item) => (
            <div key={item} className="h-32 animate-pulse rounded-lg bg-gray-200" />
          ))}
        </div>
      ) : summary && !hasAnySection ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
            <Sparkles className="h-8 w-8 text-primary-500" aria-hidden="true" />
            <h2 className="text-lg font-semibold text-gray-900">Welcome to the Division ICT Inventory System</h2>
            <p className="max-w-md text-sm text-gray-500">
              Your account doesn&apos;t have access to any dashboard section yet. Once an administrator gives your role view access, summaries of
              equipment, personnel or ISP accounts will appear here.
            </p>
          </CardContent>
        </Card>
      ) : summary ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {equipment && (
              <>
                <MetricCard icon={Monitor} label="Equipment on record" value={equipment.total.toLocaleString()} detail={`${equipment.by_category.length} categories`} color="bg-primary-600" />
                <MetricCard
                  icon={PhilippinePeso}
                  label="Total acquisition cost"
                  value={pesoFormatter.format(equipment.total_acquisition_cost)}
                  detail={pesoFormatterExact.format(equipment.total_acquisition_cost)}
                  color="bg-sky-600"
                />
                <MetricCard
                  icon={AlertTriangle}
                  label="Non-functional"
                  value={equipment.non_functional_count.toLocaleString()}
                  detail="Items flagged as not working"
                  color="bg-rose-600"
                  warn={equipment.non_functional_count > 0}
                />
                <MetricCard
                  icon={FileClock}
                  label="Warranty expiring soon"
                  value={equipment.warranty_expiring_soon_count.toLocaleString()}
                  detail="Ending in the next 30 days"
                  color="bg-amber-600"
                  warn={equipment.warranty_expiring_soon_count > 0}
                />
              </>
            )}
            {personnel && (
              <MetricCard
                icon={UserCheck}
                label="Active personnel"
                value={personnel.active_count.toLocaleString()}
                detail={`${personnel.inactive_count.toLocaleString()} inactive · ${personnel.oic_count.toLocaleString()} OIC`}
                color="bg-violet-600"
              />
            )}
            {isp && (
              <MetricCard
                icon={Wifi}
                label="ISP monthly cost"
                value={pesoFormatter.format(isp.total_monthly_cost)}
                detail={`${isp.contracts_expiring_soon_count.toLocaleString()} contract${isp.contracts_expiring_soon_count === 1 ? '' : 's'} ending within 30 days`}
                color="bg-emerald-600"
              />
            )}
          </div>

          {equipment && (
            <div className="space-y-3">
              <SectionTitle title="Equipment" description={`${equipment.total.toLocaleString()} items across the division.`} />
              <EquipmentSection summary={equipment} />
            </div>
          )}

          {(personnel || isp) && (
            <div className="grid gap-4 lg:grid-cols-2">
              {personnel && <PersonnelSection summary={personnel} />}
              {isp && <IspAccountsSection summary={isp} />}
            </div>
          )}

          {transactions && <RecentTransactions entries={transactions} />}
        </>
      ) : null}
    </div>
  )
}

