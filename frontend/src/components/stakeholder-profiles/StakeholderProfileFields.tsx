import { Building2, Compass, FileText, HeartHandshake, MapPin, Phone, Route, UserRound } from 'lucide-react'
import type { ReactNode } from 'react'

import { CheckboxGroupField } from '@/components/forms/CheckboxGroupField'
import { DetailSection } from '@/components/ui/DetailSection'
import { Input } from '@/components/ui/Input'
import { LocationPicker } from '@/components/ui/LocationPicker'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import type { FieldErrors } from '@/lib/errors'
import { cn } from '@/lib/utils'
import type { StakeholderProfileFormData, StakeholderProfileOptions } from '@/types/stakeholder-profile'

export type SectionId = 'location' | 'organization' | 'personnel' | 'contacts' | 'coordinates' | 'accessibility' | 'community' | 'notes'

/**
 * The stepper's sections and the fields each one counts toward completion:
 * every field except the four boolean flags (false is a real answer, not
 * "not filled in") and the two "_other" companions (only relevant when their
 * checkbox group is used).
 */
export const STAKEHOLDER_SECTIONS: { id: SectionId; label: string; fields: (keyof StakeholderProfileFormData)[] }[] = [
  { id: 'location', label: 'Location', fields: ['province_id', 'municipality_id', 'legislative_district', 'barangay_id', 'street'] },
  { id: 'organization', label: 'Organization', fields: ['governance_level', 'ro', 'sdo', 'school_district', 'school_name', 'school_id'] },
  {
    id: 'personnel',
    label: 'Key personnel',
    fields: [
      'chief_name',
      'chief_position',
      'chief_email',
      'chief_mobile',
      'admin_staff_name',
      'admin_staff_position',
      'admin_staff_email',
      'admin_staff_mobile',
      'network_administrator_name',
    ],
  },
  { id: 'contacts', label: 'Contact numbers', fields: ['mobile_1', 'mobile_2', 'landline'] },
  { id: 'coordinates', label: 'Geographic coordinates', fields: ['longitude', 'latitude'] },
  {
    id: 'accessibility',
    label: 'Accessibility',
    fields: ['nearby_institutions', 'travel_time_to_center_minutes', 'access_paths', 'transportation_options', 'remote_context_notes'],
  },
  { id: 'community', label: 'Community engagement', fields: ['community_engagement', 'community_context_remarks'] },
  { id: 'notes', label: 'Submission & notes', fields: ['submitted_at', 'transaction_type', 'notes_corrections', 'notes_recent_development'] },
]

/** Fields that live in a section but aren't counted toward completion; used to route 422 errors to their step. */
const UNCOUNTED_FIELDS: Partial<Record<keyof StakeholderProfileFormData, SectionId>> = {
  nearby_institutions_other: 'accessibility',
  transportation_other: 'accessibility',
  transportation_difficult: 'accessibility',
  considered_very_remote: 'accessibility',
  gidca: 'accessibility',
  lms: 'accessibility',
}

export function sectionOfField(field: string): SectionId | null {
  const counted = STAKEHOLDER_SECTIONS.find((section) => (section.fields as string[]).includes(field))
  return counted?.id ?? UNCOUNTED_FIELDS[field as keyof StakeholderProfileFormData] ?? null
}

export function isFieldFilled(value: StakeholderProfileFormData[keyof StakeholderProfileFormData]): boolean {
  if (Array.isArray(value)) return value.length > 0
  if (value === null) return false
  return String(value).trim() !== ''
}

interface StepperProps {
  active: SectionId
  onChange: (section: SectionId) => void
  complete: Record<SectionId, boolean>
  withErrors: Set<SectionId>
}

/** Numbered section stepper; an amber dot marks a section with empty fields, a red dot one with validation errors. */
export function StakeholderProfileStepper({ active, onChange, complete, withErrors }: StepperProps) {
  return (
    <nav aria-label="Profile sections" className="mb-4 overflow-x-auto rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-xs">
      <ol className="flex min-w-max items-start">
        {STAKEHOLDER_SECTIONS.map((section, index) => {
          const isActive = section.id === active
          const hasErrors = withErrors.has(section.id)
          const status = hasErrors ? 'has errors' : complete[section.id] ? 'complete' : 'has empty fields'
          return (
            <li key={section.id} className="flex flex-1 items-start last:flex-none">
              <button
                type="button"
                onClick={() => onChange(section.id)}
                aria-current={isActive ? 'step' : undefined}
                className="group flex flex-col items-center gap-1 rounded-md px-1 py-0.5 text-center text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
              >
                <span className="relative">
                  <span
                    className={cn(
                      'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium transition-colors',
                      isActive ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-300 bg-gray-50 text-gray-600 group-hover:border-primary-400',
                    )}
                  >
                    {index + 1}
                  </span>
                  <span
                    aria-hidden="true"
                    title={hasErrors ? 'This section has fields to fix' : 'This section still has empty fields'}
                    className={cn(
                      'absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white',
                      hasErrors ? 'bg-error-500' : 'bg-amber-500',
                      !hasErrors && complete[section.id] && 'invisible',
                    )}
                  />
                </span>
                <span className={cn('w-24', isActive ? 'font-medium text-primary-700' : 'text-gray-500 group-hover:text-gray-700')}>{section.label}</span>
                <span className="sr-only">({status})</span>
              </button>
              {index < STAKEHOLDER_SECTIONS.length - 1 && <div aria-hidden="true" className="mx-2 mt-4 h-px min-w-4 flex-1 bg-gray-200" />}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

interface StakeholderProfileFieldsProps {
  section: SectionId
  form: StakeholderProfileFormData
  onChange: <K extends keyof StakeholderProfileFormData>(field: K, value: StakeholderProfileFormData[K]) => void
  options: StakeholderProfileOptions | null
  errors: FieldErrors
  /** Read-only: built server-side from the saved street + PSGC names. */
  completeAddress: string | null
  disabled?: boolean
}

/** The active stepper section of the Stakeholder Profile form, as a `DetailSection`. */
export function StakeholderProfileFields({ section, form, onChange, options, errors, completeAddress, disabled = false }: StakeholderProfileFieldsProps) {
  const text = (field: keyof StakeholderProfileFormData) => ({
    id: field,
    name: field,
    value: form[field] as string,
    error: errors[field],
    disabled,
    onChange: (e: { target: { value: string } }) => onChange(field, e.target.value as never),
  })

  const toId = (value: string) => (value === '' ? null : Number(value))

  // Full PSGC tree ships once; children are filtered by the selected parent.
  const municipalities = form.province_id === null ? [] : (options?.municipality ?? []).filter((m) => m.parent_id === form.province_id)
  const barangays = form.municipality_id === null ? [] : (options?.barangay ?? []).filter((b) => b.parent_id === form.municipality_id)

  const index = STAKEHOLDER_SECTIONS.findIndex((s) => s.id === section)
  const title = `${index + 1}. ${STAKEHOLDER_SECTIONS[index].label}`

  const sections: Record<SectionId, { icon: ReactNode; body: ReactNode }> = {
    location: {
      icon: <MapPin className="h-4 w-4" />,
      body: (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select
            id="province_id"
            label="Province"
            placeholder="None"
            options={options?.province ?? []}
            value={form.province_id ?? ''}
            error={errors.province_id}
            disabled={disabled}
            onChange={(e) => {
              // A new parent invalidates the children picked under the old one.
              onChange('province_id', toId(e.target.value))
              onChange('municipality_id', null)
              onChange('barangay_id', null)
            }}
          />
          <Select
            id="municipality_id"
            label="City / Municipality"
            placeholder={form.province_id === null ? 'Select a province first' : 'None'}
            options={municipalities}
            value={form.municipality_id ?? ''}
            error={errors.municipality_id}
            disabled={disabled || form.province_id === null}
            onChange={(e) => {
              onChange('municipality_id', toId(e.target.value))
              onChange('barangay_id', null)
            }}
          />
          <Input {...text('legislative_district')} label="Legislative district" />
          <Select
            id="barangay_id"
            label="Barangay"
            placeholder={form.municipality_id === null ? 'Select a municipality first' : 'None'}
            options={barangays}
            value={form.barangay_id ?? ''}
            error={errors.barangay_id}
            disabled={disabled || form.municipality_id === null}
            onChange={(e) => onChange('barangay_id', toId(e.target.value))}
          />
          <Input {...text('street')} label="Street" />
          <div className="sm:col-span-2">
            <Input
              id="complete_address"
              label="Complete address"
              value={completeAddress ?? ''}
              readOnly
              disabled
              hint="Generated automatically from street, barangay, city/municipality, and province."
            />
          </div>
        </div>
      ),
    },
    organization: {
      icon: <Building2 className="h-4 w-4" />,
      body: (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select {...text('governance_level')} label="Governance level" placeholder="None" options={options?.governance_level ?? []} />
          <Input {...text('ro')} label="Regional Office" />
          <Input {...text('sdo')} label="Schools Division Office" />
          <Input {...text('school_district')} label="School district" />
          <Input {...text('school_name')} label="School name" />
          <Input {...text('school_id')} label="School ID" />
        </div>
      ),
    },
    personnel: {
      icon: <UserRound className="h-4 w-4" />,
      body: (
        <div className="space-y-5">
          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-gray-900">RO/SDO Chief / School Head</legend>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input {...text('chief_name')} label="Name" />
              <Input {...text('chief_position')} label="Position" />
              <Input {...text('chief_email')} label="Email" type="email" autoComplete="off" />
              <Input {...text('chief_mobile')} label="Mobile" type="tel" />
            </div>
          </fieldset>
          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-gray-900">Administrative Staff / Inventory Clerk</legend>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input {...text('admin_staff_name')} label="Name" />
              <Input {...text('admin_staff_position')} label="Position" />
              <Input {...text('admin_staff_email')} label="Email" type="email" autoComplete="off" />
              <Input {...text('admin_staff_mobile')} label="Mobile" type="tel" />
            </div>
          </fieldset>
          <Input {...text('network_administrator_name')} label="Network administrator" />
        </div>
      ),
    },
    contacts: {
      icon: <Phone className="h-4 w-4" />,
      body: (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Input {...text('mobile_1')} label="Mobile 1" type="tel" />
          <Input {...text('mobile_2')} label="Mobile 2" type="tel" />
          <Input {...text('landline')} label="Landline" type="tel" />
        </div>
      ),
    },
    coordinates: {
      icon: <Compass className="h-4 w-4" />,
      body: (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input {...text('longitude')} label="Longitude" type="number" step="0.0000001" min="-180" max="180" inputMode="decimal" />
            <Input {...text('latitude')} label="Latitude" type="number" step="0.0000001" min="-90" max="90" inputMode="decimal" />
          </div>
          
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Interactive Map
              <span className="ml-2 text-xs font-normal text-gray-500">(Click to set location, or drag the marker)</span>
            </label>
            <LocationPicker
              latitude={form.latitude === '' ? null : Number(form.latitude)}
              longitude={form.longitude === '' ? null : Number(form.longitude)}
              onChange={(lat, lng) => {
                onChange('latitude', String(lat))
                onChange('longitude', String(lng))
              }}
              disabled={disabled}
              height="450px"
              zoom={15}
            />
          </div>
        </div>
      ),
    },
    accessibility: {
      icon: <Route className="h-4 w-4" />,
      body: (
        <div className="space-y-5">
          <CheckboxGroupField
            id="nearby_institutions"
            label="Nearby institutions"
            options={options?.nearby_institution ?? []}
            value={form.nearby_institutions}
            onChange={(value) => onChange('nearby_institutions', value)}
            error={errors.nearby_institutions}
            disabled={disabled}
          />
          <div className="sm:max-w-sm">
            <Input {...text('nearby_institutions_other')} label="Other nearby institution" />
          </div>
          <div className="sm:max-w-sm">
            <Input {...text('travel_time_to_center_minutes')} label="Travel time to center (minutes)" type="number" min="0" inputMode="numeric" />
          </div>
          <CheckboxGroupField
            id="access_paths"
            label="Access paths"
            options={options?.type_of_access_road ?? []}
            value={form.access_paths}
            onChange={(value) => onChange('access_paths', value)}
            error={errors.access_paths}
            disabled={disabled}
          />
          <CheckboxGroupField
            id="transportation_options"
            label="Transportation options"
            options={options?.by_transportation ?? []}
            value={form.transportation_options}
            onChange={(value) => onChange('transportation_options', value)}
            error={errors.transportation_options}
            disabled={disabled}
          />
          <div className="sm:max-w-sm">
            <Input {...text('transportation_other')} label="Other transportation option" />
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Switch
              checked={form.transportation_difficult}
              id="transportation_difficult"
              error={errors.transportation_difficult}
              disabled={disabled}
              onChange={(checked) => onChange('transportation_difficult', checked)}
              label="Transportation is difficult"
            />
            <Switch
              checked={form.considered_very_remote}
              id="considered_very_remote"
              error={errors.considered_very_remote}
              disabled={disabled}
              onChange={(checked) => onChange('considered_very_remote', checked)}
              label="Considered very remote"
            />
            <Switch
              checked={form.gidca}
              id="gidca"
              error={errors.gidca}
              disabled={disabled}
              onChange={(checked) => onChange('gidca', checked)}
              label="GIDCA (Geographically Isolated, Disadvantaged, and Conflict-Affected)"
            />
            <Switch
              id="lms"
              checked={form.lms}
              disabled={disabled}
              onChange={(checked) => onChange('lms', checked)}
              label="LMS (Last Mile School)"
              error={errors.lms}
            />
          </div>
          <Textarea {...text('remote_context_notes')} label="Remote context notes" />
        </div>
      ),
    },
    community: {
      icon: <HeartHandshake className="h-4 w-4" />,
      body: (
        <div className="space-y-5">
          <CheckboxGroupField
            id="community_engagement"
            label="Community engagement"
            options={options?.community_engagement ?? []}
            value={form.community_engagement}
            onChange={(value) => onChange('community_engagement', value)}
            error={errors.community_engagement}
            disabled={disabled}
          />
          <Textarea {...text('community_context_remarks')} label="Community context remarks" />
        </div>
      ),
    },
    notes: {
      icon: <FileText className="h-4 w-4" />,
      body: (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input {...text('submitted_at')} label="Submitted at" type="datetime-local" />
          <Select {...text('transaction_type')} label="Transaction type" placeholder="None" options={options?.transaction_type ?? []} />
          <div className="sm:col-span-2">
            <Textarea {...text('notes_corrections')} label="Notes / corrections" />
          </div>
          <div className="sm:col-span-2">
            <Textarea {...text('notes_recent_development')} label="Notes on recent development" />
          </div>
        </div>
      ),
    },
  }

  return (
    <DetailSection icon={sections[section].icon} title={title} last>
      {sections[section].body}
    </DetailSection>
  )
}
