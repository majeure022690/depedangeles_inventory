import { RotateCcw } from 'lucide-react'

import { Button } from '@/components/ui/Button'
import { FilterSelect } from '@/components/ui/FilterControls'
import { EMPTY_DASHBOARD_FILTERS } from '@/services/dashboardService'
import type { DashboardFilterOptions, DashboardFilters } from '@/types/dashboard'

interface DashboardFilterBarProps {
  options: DashboardFilterOptions
  value: DashboardFilters
  onChange: (next: DashboardFilters) => void
  disabled?: boolean
}

/** Office, office type and district narrowing for users who see every office. */
export function DashboardFilterBar({ options, value, onChange, disabled = false }: DashboardFilterBarProps) {
  const offices = options.offices.filter(
    (office) => (value.office_type === '' || office.office_type === value.office_type) && (value.district === '' || office.district === value.district),
  )
  const active = value.office_id !== '' || value.office_type !== '' || value.district !== ''

  function narrow(next: Partial<DashboardFilters>) {
    const merged = { ...value, ...next }
    const office = options.offices.find((option) => String(option.value) === merged.office_id)
    const officeStillFits =
      office !== undefined &&
      (merged.office_type === '' || office.office_type === merged.office_type) &&
      (merged.district === '' || office.district === merged.district)
    onChange({ ...merged, office_id: officeStillFits ? merged.office_id : '' })
  }

  return (
    <section aria-label="Dashboard filters" className="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_auto]">
        <FilterSelect label="Office type" value={value.office_type} disabled={disabled} onChange={(office_type) => narrow({ office_type })}>
          <option value="">All office types</option>
          {options.office_types.map((type) => (
            <option key={type} value={type}>
              {type}
            </option>
          ))}
        </FilterSelect>
        <FilterSelect
          label="District"
          value={value.district}
          disabled={disabled || options.districts.length === 0}
          onChange={(district) => narrow({ district })}
        >
          <option value="">{options.districts.length === 0 ? 'No districts recorded' : 'All districts'}</option>
          {options.districts.map((district) => (
            <option key={district} value={district}>
              {district}
            </option>
          ))}
        </FilterSelect>
        <FilterSelect label="Office" value={value.office_id} disabled={disabled} onChange={(office_id) => narrow({ office_id })}>
          <option value="">{offices.length === options.offices.length ? 'All offices' : `All ${offices.length} matching offices`}</option>
          {offices.map((office) => (
            <option key={office.value} value={String(office.value)}>
              {office.label}
            </option>
          ))}
        </FilterSelect>
        <Button
          variant="outline"
          size="sm"
          leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
          disabled={disabled || !active}
          onClick={() => onChange(EMPTY_DASHBOARD_FILTERS)}
        >
          Clear filters
        </Button>
      </div>
    </section>
  )
}
