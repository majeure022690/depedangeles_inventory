import { ArrowRight, type LucideIcon, PhilippinePeso, Wifi } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { BreakdownList } from '@/components/dashboard/BreakdownList'
import { DashboardSectionHeading } from '@/components/dashboard/DashboardSectionHeading'
import { formatPercentage, pesoExact } from '@/components/dashboard/format'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { cn } from '@/lib/utils'
import type { DashboardInternetConnectivity } from '@/types/dashboard'

function StatTile({ icon: Icon, label, children, caption, className }: { icon: LucideIcon; label: string; children: ReactNode; caption?: string; className: string }) {
  return (
    <div className={cn('flex items-center gap-3 rounded-lg border px-3 py-2.5', className)}>
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/80" aria-hidden="true">
        <Icon className="h-[1.125rem] w-[1.125rem]" />
      </span>
      <div className="min-w-0">
        <p className="text-xs font-medium text-gray-600">{label}</p>
        <p className="text-xl font-bold leading-tight tabular-nums text-gray-900 [overflow-wrap:anywhere]">{children}</p>
        {caption && <p className="text-[0.6875rem] text-gray-500">{caption}</p>}
      </div>
    </div>
  )
}

export function InternetConnectivitySection({ summary, canViewAccounts }: { summary: DashboardInternetConnectivity; canViewAccounts: boolean }) {
  const maxProvider = Math.max(1, ...summary.by_provider.map((provider) => provider.count))

  return (
    <Card>
      <CardHeader className="px-4 py-3">
        <DashboardSectionHeading
          id="internet-connectivity-heading"
          title="Internet Connectivity"
          description="Total number of ISP and connection details"
          action={
            canViewAccounts ? (
              <Link to="/isp-accounts" className="inline-flex items-center gap-1 text-sm font-medium text-primary-700 hover:underline focus-visible:underline">
                View ISP accounts <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
              </Link>
            ) : undefined
          }
        />
      </CardHeader>
      <CardContent className="grid gap-4 p-4 lg:grid-cols-[15rem_minmax(0,1fr)_14rem]">
        <div className="space-y-3">
          <StatTile icon={Wifi} label="Total number of ISP accounts" className="border-primary-100 bg-primary-50 text-primary-700">
            {summary.total_accounts.toLocaleString()}
          </StatTile>
          <StatTile icon={PhilippinePeso} label="Total cost / month" caption="Active DepEd Purchase only" className="border-cyan-100 bg-cyan-50 text-cyan-700">
            {pesoExact.format(summary.total_cost)}
          </StatTile>
        </div>

        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">ISP provider</h3>
          {summary.by_provider.length === 0 ? (
            <p className="text-sm text-gray-500">No ISP accounts recorded yet.</p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-gray-200">
              <table className="w-full text-left text-sm">
                <caption className="sr-only">ISP accounts by provider</caption>
                <thead className="border-b border-gray-200 bg-gray-50">
                  <tr>
                    <th scope="col" className="px-3 py-2 text-xs font-semibold text-gray-500">
                      Provider
                    </th>
                    <th scope="col" className="px-3 py-2 text-right text-xs font-semibold text-gray-500">
                      Count
                    </th>
                    <th scope="col" className="px-3 py-2 text-xs font-semibold text-gray-500">
                      Percentage
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {summary.by_provider.map((provider) => (
                    <tr key={provider.name}>
                      <th scope="row" className="px-3 py-1.5 font-medium text-gray-900">
                        {provider.name}
                      </th>
                      <td className="px-3 py-1.5 text-right tabular-nums text-gray-900">{provider.count.toLocaleString()}</td>
                      <td className="px-3 py-1.5">
                        <div className="flex items-center gap-3">
                          <div className="h-1.5 w-full min-w-16 overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
                            <div className="h-full rounded-full bg-primary-600" style={{ width: `${(provider.count / maxProvider) * 100}%` }} />
                          </div>
                          <span className="w-12 shrink-0 text-right text-xs tabular-nums text-gray-600">{formatPercentage(provider.percentage)}</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Connection type</h3>
          <BreakdownList items={summary.by_connection_type} emptyMessage="No connection types recorded yet." />
        </div>
      </CardContent>
    </Card>
  )
}
