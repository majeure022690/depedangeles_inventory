import { CheckSquare, Database, Square } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import type { FundKey, GspIrisData } from '@/types/gsp-profile'

const LEVELS = [
  { key: 'elementary', label: 'Elementary' },
  { key: 'junior_high', label: 'Junior High School' },
  { key: 'senior_high', label: 'Senior High School' },
]

const GROUPS = [
  { key: 'learner', label: 'Number of Functional Computers Used by Learners', levels: true },
  { key: 'teacher', label: 'Number of Functional Computers Used by Teachers', levels: true },
  { key: 'personnel', label: 'Number of Functional Computers Used by Teaching-Related and Non-Teaching Personnel', levels: false },
]

const cell = 'border border-gray-200 px-3 py-1.5'
const head = `${cell} bg-primary-50 text-center text-xs font-semibold uppercase tracking-wide text-gray-700`
const num = `${cell} text-center tabular-nums`

/** Read-only notice naming where the numbers come from and where to change them. */
function Source({ children }: { children: ReactNode }) {
  return (
    <p className="mb-3 flex items-start gap-2 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-800">
      <Database className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{children}</span>
    </p>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="overflow-visible">
      <CardHeader className="py-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-600">{title}</h2>
      </CardHeader>
      <CardContent className="overflow-x-auto">{children}</CardContent>
    </Card>
  )
}

export function Table1View({ data, level }: { data: GspIrisData['table_1']; level: string }) {
  const count = (group: string, levelKey: string, type: string, fund: FundKey) => data.counts[group]?.[levelKey]?.[type]?.[fund] ?? 0
  const rowTotal = (group: string, levelKey: string, type: string) => data.funds.reduce((sum, fund) => sum + count(group, levelKey, type, fund.key), 0)
  const columnTotal = (group: string, levelKey: string, fund: FundKey) => data.types.reduce((sum, type) => sum + count(group, levelKey, type.key, fund), 0)

  return (
    <Section title="Table 1. Number of functional computers in the school by funding source & level">
      <Source>
        Counted from{' '}
        <Link to="/equipment" className="font-medium underline">
          Equipment
        </Link>
        : functional desktops, laptops, tablets and 2-in-1 tablets of this school, grouped by their <strong>Used by</strong> value. Learner and teacher
        computers are counted under the profile&rsquo;s school level.
      </Source>
      {GROUPS.map((group) => (
        <table key={group.key} className="mb-6 w-full min-w-[56rem] border-collapse text-sm last:mb-0">
          <thead>
            <tr>
              <th scope="col" className={`${head} w-64 text-left`}>
                Types of computer
              </th>
              {data.funds.map((fund) => (
                <th key={fund.key} scope="col" className={head}>
                  {fund.label}
                </th>
              ))}
              <th scope="col" className={head}>
                Total
              </th>
            </tr>
            <tr>
              <th colSpan={data.funds.length + 2} scope="colgroup" className={`${cell} bg-gray-50 text-center font-semibold text-gray-800`}>
                {group.label}
              </th>
            </tr>
          </thead>
          {(group.levels ? LEVELS : [{ key: 'all', label: '' }]).map((levelRow) => (
            <tbody key={levelRow.key} className={group.levels && levelRow.key !== level ? 'text-gray-400' : undefined}>
              {levelRow.label && (
                <tr>
                  <th colSpan={data.funds.length + 2} scope="rowgroup" className={`${cell} text-left font-semibold italic`}>
                    {levelRow.label}
                    {group.levels && levelRow.key === level && (
                      <span className="ml-2 text-xs font-normal not-italic text-primary-700">(this profile&rsquo;s level)</span>
                    )}
                  </th>
                </tr>
              )}
              {data.types.map((type) => (
                <tr key={type.key}>
                  <th scope="row" className={`${cell} pl-6 text-left font-normal`}>
                    {type.label}
                  </th>
                  {data.funds.map((fund) => (
                    <td key={fund.key} className={num}>
                      {count(group.key, levelRow.key, type.key, fund.key)}
                    </td>
                  ))}
                  <td className={`${num} font-semibold`}>{rowTotal(group.key, levelRow.key, type.key)}</td>
                </tr>
              ))}
              <tr className="font-semibold">
                <th scope="row" className={`${cell} text-left`}>
                  Total
                </th>
                {data.funds.map((fund) => (
                  <td key={fund.key} className={num}>
                    {columnTotal(group.key, levelRow.key, fund.key)}
                  </td>
                ))}
                <td className={num}>{data.funds.reduce((sum, fund) => sum + columnTotal(group.key, levelRow.key, fund.key), 0)}</td>
              </tr>
            </tbody>
          ))}
        </table>
      ))}
    </Section>
  )
}

export function Table2View({ rows }: { rows: GspIrisData['table_2'] }) {
  return (
    <Section title="Table 2. Number of functional ICT equipment in the school by funding source">
      <Source>
        Counted from{' '}
        <Link to="/equipment" className="font-medium underline">
          Equipment
        </Link>
        : functional TVs, printers, projectors, external hard drives, lapels and charging carts of this school.
      </Source>
      <table className="w-full min-w-[40rem] border-collapse text-sm">
        <thead>
          <tr>
            {['Types of ICT equipment', 'DepEd DCP', 'DepEd Non-DCP', 'Others', 'Total'].map((label, index) => (
              <th key={label} scope="col" className={`${head} ${index === 0 ? 'text-left' : ''}`}>
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.label}>
              <th scope="row" className={`${cell} text-left font-normal`}>
                {row.label}
              </th>
              <td className={num}>{row.dcp}</td>
              <td className={num}>{row.deped_non_dcp}</td>
              <td className={num}>{row.others}</td>
              <td className={`${num} font-semibold`}>{row.dcp + row.deped_non_dcp + row.others}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Section>
  )
}

export function Table3View({ data }: { data: GspIrisData['table_3'] }) {
  return (
    <Section title="Table 3. Number of functional e-cart components under the DepEd Computerization Program (DCP)">
      <Source>
        Counted from{' '}
        <Link to="/equipment" className="font-medium underline">
          Equipment
        </Link>
        : functional DCP items whose package is <strong>eLearning Cart Package</strong>, by DCP year.
      </Source>
      <table className="w-full min-w-[40rem] border-collapse text-sm">
        <thead>
          <tr>
            <th scope="col" className={`${head} text-left`}>
              Types of e-cart component
            </th>
            {data.years.map((year) => (
              <th key={year} scope="col" className={head}>
                {year}-01
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.rows.map((row) => (
            <tr key={row.label}>
              <th scope="row" className={`${cell} text-left font-normal`}>
                {row.label}
              </th>
              {data.years.map((year) => (
                <td key={year} className={num}>
                  {row.counts[String(year)] ?? 0}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </Section>
  )
}

function CheckGrid({ options }: { options: { label: string; checked: boolean }[] }) {
  return (
    <ul className="grid gap-x-6 gap-y-1.5 sm:grid-cols-2 lg:grid-cols-3">
      {options.map((option) => (
        <li key={option.label} className="flex items-center gap-2 text-sm">
          {option.checked ? (
            <CheckSquare className="h-4 w-4 text-primary-600" aria-hidden="true" />
          ) : (
            <Square className="h-4 w-4 text-gray-400" aria-hidden="true" />
          )}
          <span className={option.checked ? 'font-medium text-gray-900' : 'text-gray-600'}>{option.label}</span>
          <span className="sr-only">{option.checked ? '(checked)' : '(not checked)'}</span>
        </li>
      ))}
    </ul>
  )
}

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function Box1IrisView({ data, reliability }: { data: GspIrisData['box_1']; reliability: Record<string, string> }) {
  const dash = (value: string | number | null) => (value === null || value === '' ? '—' : value)

  return (
    <>
      <Section title="Q1. Internet Service Providers (ISPs) available in the area">
        <Source>
          From the school&rsquo;s{' '}
          <Link to="/internet-connectivity-surveys" className="font-medium underline">
            Internet Connectivity survey
          </Link>
          {data.has_survey ? '.' : ' — not filled in yet, so nothing is checked.'}
        </Source>
        <CheckGrid options={data.available_isps} />
      </Section>

      <Section title="Q2. Internet service providers the school is subscribed to">
        <Source>
          From the school&rsquo;s active{' '}
          <Link to="/isp-accounts" className="font-medium underline">
            ISP Accounts
          </Link>{' '}
          and their latest speed test. Service quality is rated per Box 1 definition 9; set service reliability in the reliability section on this tab.
        </Source>
        {data.subscriptions.length === 0 ? (
          <p className="text-sm text-gray-500">No active ISP accounts for this school.</p>
        ) : (
          <table className="w-full min-w-[72rem] border-collapse text-sm">
            <thead>
              <tr>
                {['Internet service provider', 'Type of connection', 'Level of education', 'Purpose', 'Avg. monthly expense', 'Funding source'].map((label) => (
                  <th key={label} rowSpan={2} scope="col" className={head}>
                    {label}
                  </th>
                ))}
                <th colSpan={2} scope="colgroup" className={head}>
                  Subscriber speed (Mbps)
                </th>
                <th colSpan={3} scope="colgroup" className={head}>
                  Speed test (Mbps)
                </th>
                <th rowSpan={2} scope="col" className={head}>
                  Service quality
                </th>
                <th rowSpan={2} scope="col" className={head}>
                  Service reliability
                </th>
              </tr>
              <tr>
                {['Download', 'Upload', 'Ping', 'Download', 'Upload'].map((label, index) => (
                  <th key={`${label}-${index}`} scope="col" className={head}>
                    {label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.subscriptions.map((row) => (
                <tr key={row.account_id}>
                  <th scope="row" className={`${cell} text-left font-medium`}>
                    {dash(row.provider)}
                  </th>
                  <td className={cell}>{dash(row.connection_type)}</td>
                  <td className={cell}>{dash(row.level)}</td>
                  <td className={cell}>{dash(row.purpose)}</td>
                  <td className={`${cell} text-right tabular-nums`}>{row.monthly_expense === null ? '—' : currency.format(row.monthly_expense)}</td>
                  <td className={cell}>{dash(row.funding_source)}</td>
                  <td className={num}>{dash(row.subscribed_download)}</td>
                  <td className={num}>{dash(row.subscribed_upload)}</td>
                  <td className={num}>{dash(row.ping)}</td>
                  <td className={num}>{dash(row.download)}</td>
                  <td className={num}>{dash(row.upload)}</td>
                  <td className={cell}>{dash(row.service_quality)}</td>
                  <td className={cell}>{reliability[String(row.account_id)] || <span className="text-gray-400">Not rated</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      <Section title="Q3. Coverage area of the internet subscription">
        <Source>
          From the school&rsquo;s{' '}
          <Link to="/internet-connectivity-surveys" className="font-medium underline">
            Internet Connectivity survey
          </Link>
          .
        </Source>
        <CheckGrid options={data.coverage} />
      </Section>
    </>
  )
}
