import { isAxiosError } from 'axios'
import { Building2, MapPin, Phone } from 'lucide-react'
import type { ReactNode } from 'react'

import { FormField } from '@/components/forms/FormField'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { extractError } from '@/lib/errors'
import type { Office, OfficeFormData, OfficePayload } from '@/types/office'

export type OfficeFieldErrors = Partial<Record<keyof OfficeFormData, string>>

export function emptyOfficeForm(): OfficeFormData {
  return {
    office_name: '',
    office_type: '',
    school_id: '',
    address: '',
    region: '',
    district: '',
    division: '',
    contact_number: '',
    email: '',
    is_active: true,
  }
}

export function officeToForm(office: Office): OfficeFormData {
  return {
    office_name: office.office_name,
    office_type: office.office_type ?? '',
    school_id: office.school_id !== null ? String(office.school_id) : '',
    address: office.address ?? '',
    region: office.region ?? '',
    district: office.district ?? '',
    division: office.division ?? '',
    contact_number: office.contact_number ?? '',
    email: office.email ?? '',
    is_active: office.is_active,
  }
}

export function toOfficePayload(form: OfficeFormData): OfficePayload {
  const text = (value: string) => (value.trim() === '' ? null : value.trim())
  return {
    office_name: form.office_name.trim(),
    office_type: text(form.office_type),
    school_id: form.school_id.trim() === '' ? null : Number(form.school_id),
    address: text(form.address),
    region: text(form.region),
    district: text(form.district),
    division: text(form.division),
    contact_number: text(form.contact_number),
    email: text(form.email),
    is_active: form.is_active,
  }
}

/** 409 = still referenced by users/profiles/surveys; the server message says to deactivate instead. */
export function officeDeleteErrorMessage(error: unknown): string {
  if (isAxiosError(error) && error.response?.status === 409) {
    return extractError(error, 'This office is still in use and cannot be deleted. Deactivate it instead.')
  }
  return extractError(error, 'Unable to delete this office. Please try again.')
}

function Section({ icon, title, description, className, children }: { icon: ReactNode; title: string; description: string; className?: string; children: ReactNode }) {
  const headingId = `office-section-${title.toLowerCase()}`
  return (
    <Card className={className}>
      <section aria-labelledby={headingId}>
        <CardHeader className="py-3">
          <h2 id={headingId} className="flex items-center gap-2 text-sm font-semibold text-gray-900">
            <span className="text-gray-400">{icon}</span>
            {title}
          </h2>
          <p className="mt-0.5 text-xs text-gray-500">{description}</p>
        </CardHeader>
        <CardContent className="space-y-4">{children}</CardContent>
      </section>
    </Card>
  )
}

interface OfficeFieldsProps {
  value: OfficeFormData
  onChange: (patch: Partial<OfficeFormData>) => void
  errors: OfficeFieldErrors
  disabled?: boolean
  nameLocked?: boolean
}

/** Shared by OfficeCreate and OfficeEdit (port of offices/Partials/Form.vue). */
export function OfficeFields({ value, onChange, errors, disabled = false, nameLocked = false }: OfficeFieldsProps) {
  return (
    <div className="grid items-start gap-4 lg:grid-cols-2">
      <Section
        icon={<Building2 className="h-4 w-4" />}
        title="Identification"
        description="How this school or division office/unit is named and identified."
        className="lg:col-span-2"
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            id="office_name"
            label="Office name"
            required
            value={value.office_name}
            onChange={(e) => onChange({ office_name: e.target.value })}
            error={errors.office_name}
            disabled={disabled}
            readOnly={nameLocked}
            className={nameLocked ? 'bg-gray-50 text-gray-600' : undefined}
            hint={nameLocked ? 'Managed by PRIME' : undefined}
            maxLength={255}
          />
          <FormField id="office_type" label="Office type (optional)" hint="Free text, e.g. Elementary School, High School, Division Office or Unit." error={errors.office_type}>
            {(aria) => (
              <Input
                id="office_type"
                placeholder="e.g. Elementary School"
                value={value.office_type}
                onChange={(e) => onChange({ office_type: e.target.value })}
                disabled={disabled}
                maxLength={100}
                {...aria}
              />
            )}
          </FormField>
          <FormField id="school_id" label="School ID (optional)" hint="The DepEd-assigned School ID. Only schools have one; leave it blank for division offices and units." error={errors.school_id}>
            {(aria) => (
              <Input
                id="school_id"
                type="number"
                inputMode="numeric"
                min={0}
                placeholder="DepEd-assigned School ID, if applicable"
                value={value.school_id}
                onChange={(e) => onChange({ school_id: e.target.value })}
                disabled={disabled}
                {...aria}
              />
            )}
          </FormField>
          <div className="flex flex-col justify-end sm:pb-2.5">
            <Switch
              id="is_active"
              checked={value.is_active}
              onChange={(checked) => onChange({ is_active: checked })}
              label="Active"
              disabled={disabled}
              hint="Inactive offices stay on existing records but are hidden from office pickers."
              error={errors.is_active}
            />
          </div>
        </div>
      </Section>

      <Section icon={<MapPin className="h-4 w-4" />} title="Location" description="Where the office is and which district/division it belongs to.">
        <Textarea
          id="address"
          label="Address (optional)"
          value={value.address}
          onChange={(e) => onChange({ address: e.target.value })}
          error={errors.address}
          disabled={disabled}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            id="region"
            label="Region (optional)"
            value={value.region}
            onChange={(e) => onChange({ region: e.target.value })}
            error={errors.region}
            disabled={disabled}
            maxLength={100}
          />
          <Input
            id="division"
            label="Division (optional)"
            value={value.division}
            onChange={(e) => onChange({ division: e.target.value })}
            error={errors.division}
            disabled={disabled}
            maxLength={100}
          />
          <Input
            id="district"
            label="District (optional)"
            value={value.district}
            onChange={(e) => onChange({ district: e.target.value })}
            error={errors.district}
            disabled={disabled}
            maxLength={100}
          />
        </div>
      </Section>

      <Section icon={<Phone className="h-4 w-4" />} title="Contact" description="How to reach this office.">
        <Input
          id="contact_number"
          label="Contact number (optional)"
          type="tel"
          value={value.contact_number}
          onChange={(e) => onChange({ contact_number: e.target.value })}
          error={errors.contact_number}
          disabled={disabled}
          maxLength={50}
        />
        <Input
          id="email"
          label="Email (optional)"
          type="email"
          value={value.email}
          onChange={(e) => onChange({ email: e.target.value })}
          error={errors.email}
          disabled={disabled}
          maxLength={255}
        />
      </Section>
    </div>
  )
}
