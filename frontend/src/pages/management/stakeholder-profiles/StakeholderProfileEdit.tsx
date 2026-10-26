import { ArrowLeft, ChevronLeft, ChevronRight, School } from 'lucide-react'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import {
  STAKEHOLDER_SECTIONS,
  StakeholderProfileFields,
  StakeholderProfileStepper,
  isFieldFilled,
  sectionOfField,
  type SectionId,
} from '@/components/stakeholder-profiles/StakeholderProfileFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope, scopedTitle } from '@/lib/dataScope'
import { extractError, extractFieldErrors, extractLoadError, isValidationError, OUT_OF_REACH_MESSAGE, type FieldErrors } from '@/lib/errors'
import { stakeholderProfileFormFrom, stakeholderProfileService } from '@/services/stakeholderProfileService'
import type { ProfileOffice, StakeholderProfile, StakeholderProfileFormData, StakeholderProfileOptions } from '@/types/stakeholder-profile'

type Feedback = { type: 'success' | 'error' | 'info'; message: string } | null

const COMPLETION_FIELDS = STAKEHOLDER_SECTIONS.flatMap((section) => section.fields)

export function StakeholderProfileEdit() {
  const { officeId: officeIdParam } = useParams<{ officeId: string }>()
  const officeId = Number(officeIdParam)
  const navigate = useNavigate()
  const { can, user } = useAuth()
  const isAll = hasAllScope(user)
  const heading = isAll ? 'Stakeholder Profile' : scopedTitle('stakeholder_profile', user?.data_scope)
  // Mirrors StakeholderProfilePolicy::update(); the server still re-checks the office scope.
  const canEdit = can('stakeholder_profile.edit')

  const [office, setOffice] = useState<ProfileOffice | null>(null)
  const [profile, setProfile] = useState<StakeholderProfile | null>(null)
  const [options, setOptions] = useState<StakeholderProfileOptions | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [form, setForm] = useState<StakeholderProfileFormData | null>(null)
  const [savedForm, setSavedForm] = useState<StakeholderProfileFormData | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback>(null)
  const [section, setSection] = useState<SectionId>('location')

  usePageTitle(office ? `${heading} - ${office.office_name}` : heading)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadError(null)
    Promise.all([stakeholderProfileService.show(officeId), stakeholderProfileService.options()])
      .then(([envelope, loadedOptions]) => {
        if (cancelled) return
        const initial = stakeholderProfileFormFrom(envelope.profile, envelope.office)
        setOffice(envelope.office)
        setProfile(envelope.profile)
        setOptions(loadedOptions)
        setForm(initial)
        setSavedForm(initial)
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractLoadError(error, 'Unable to load this stakeholder profile.'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [officeId])

  const completion = useMemo(() => {
    if (!form) return { percent: 0, sections: {} as Record<SectionId, boolean> }
    const filled = COMPLETION_FIELDS.filter((field) => isFieldFilled(form[field])).length
    return {
      percent: Math.round((filled / COMPLETION_FIELDS.length) * 100),
      sections: Object.fromEntries(
        STAKEHOLDER_SECTIONS.map((s) => [s.id, s.fields.every((field) => isFieldFilled(form[field]))]),
      ) as Record<SectionId, boolean>,
    }
  }, [form])

  const sectionsWithErrors = useMemo(
    () => new Set(Object.keys(errors).map(sectionOfField).filter((id): id is SectionId => id !== null)),
    [errors],
  )

  if (loading) return <DetailPageSkeleton />
  if (loadError || !office || !profile || !form) {
    return (
      <div>
        {isAll && (
          <Button variant="ghost" size="sm" onClick={() => navigate('/stakeholder-profiles')} aria-label="Back to stakeholder profiles" className="mb-4">
            <ArrowLeft className="h-4 w-4" />
          </Button>
        )}
        <Alert type="error">
          <span role="alert">{loadError ?? OUT_OF_REACH_MESSAGE}</span>
        </Alert>
      </div>
    )
  }

  const dirty = JSON.stringify(form) !== JSON.stringify(savedForm)
  const sectionIndex = STAKEHOLDER_SECTIONS.findIndex((s) => s.id === section)

  function setField<K extends keyof StakeholderProfileFormData>(field: K, value: StakeholderProfileFormData[K]) {
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev))
  }

  function goTo(index: number) {
    const target = STAKEHOLDER_SECTIONS[index]
    if (target) setSection(target.id)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!form || !canEdit) return
    setSaving(true)
    setErrors({})
    setFeedback(null)
    try {
      const response = await stakeholderProfileService.update(officeId, form)
      const next = stakeholderProfileFormFrom(response.data.profile, response.data.office)
      setOffice(response.data.office)
      setProfile(response.data.profile)
      setForm(next)
      setSavedForm(next)
      setFeedback({ type: 'success', message: response.message })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) {
        setErrors(fieldErrors)
        // Only the active step is rendered, so jump to the first one holding an error.
        const first = STAKEHOLDER_SECTIONS.find((s) => Object.keys(fieldErrors).some((field) => sectionOfField(field) === s.id))
        if (first) setSection(first.id)
      }
      setFeedback({
        type: 'error',
        message: fieldErrors ? 'Unable to save changes. Please review the highlighted fields and try again.' : extractError(error),
      })
    } finally {
      setSaving(false)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }

  return (
    <div>
      <div className="mb-4 flex min-w-0 items-start gap-3">
        {isAll && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/stakeholder-profiles')}
            aria-label="Back to stakeholder profiles"
            className="mt-1.5"
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        )}
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-700" aria-hidden="true">
          <School className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-gray-900">
            {heading} — {office.office_name}
          </h1>
          <p className="text-sm text-gray-600">Identity, location, contacts, and community context for this school/office.</p>
        </div>
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}
      {!canEdit && (
        <Alert type="info" className="mb-4">
          You can view this profile but not edit it.
        </Alert>
      )}

      <div className="mb-4 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-xs">
        <div className="mb-2 flex items-center justify-between text-sm">
          <span id="profile-completion-label" className="font-medium text-gray-900">
            Profile completion
          </span>
          <span className="tabular-nums text-gray-500">{completion.percent}%</span>
        </div>
        <div
          role="progressbar"
          aria-labelledby="profile-completion-label"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={completion.percent}
          className="h-2 w-full overflow-hidden rounded-full bg-gray-100"
        >
          <div className="h-full rounded-full bg-primary-600 transition-[width] duration-300" style={{ width: `${completion.percent}%` }} />
        </div>
      </div>

      <StakeholderProfileStepper active={section} onChange={setSection} complete={completion.sections} withErrors={sectionsWithErrors} />

      <form noValidate onSubmit={handleSubmit} aria-label={`Stakeholder profile of ${office.office_name}`}>
        <Card>
          <CardContent className="p-0">
            <StakeholderProfileFields
              section={section}
              form={form}
              onChange={setField}
              options={options}
              errors={errors}
              completeAddress={profile.complete_address}
              disabled={!canEdit || saving}
            />
          </CardContent>
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3">
            <div className="flex items-center gap-2">
              <Button size="sm" variant="outline" leftIcon={<ChevronLeft className="h-4 w-4" />} disabled={sectionIndex <= 0} onClick={() => goTo(sectionIndex - 1)}>
                Previous
              </Button>
              <Button
                size="sm"
                variant="outline"
                rightIcon={<ChevronRight className="h-4 w-4" />}
                disabled={sectionIndex >= STAKEHOLDER_SECTIONS.length - 1}
                onClick={() => goTo(sectionIndex + 1)}
              >
                Next
              </Button>
            </div>
            {canEdit && (
              <div className="flex flex-wrap items-center gap-3">
                {dirty && (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setForm(savedForm)
                      setErrors({})
                    }}
                  >
                    Discard changes
                  </Button>
                )}
                {dirty ? (
                  <span className="text-xs font-medium text-amber-700">Unsaved changes</span>
                ) : (
                  <span className="text-xs text-gray-400">All changes saved</span>
                )}
                <Button type="submit" isLoading={saving} disabled={!dirty}>
                  Save changes
                </Button>
              </div>
            )}
          </div>
        </Card>
      </form>
    </div>
  )
}
