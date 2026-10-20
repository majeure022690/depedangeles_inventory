import { ArrowLeft, Signal } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { ConnectivitySurveyFields } from '@/components/internet-connectivity-surveys/ConnectivitySurveyFields'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError, extractFieldErrors, isValidationError, type FieldErrors } from '@/lib/errors'
import type { PermissionName } from '@/lib/permissions'
import { connectivitySurveyFormFrom, connectivitySurveyService } from '@/services/connectivitySurveyService'
import type { ConnectivitySurveyFormData, ConnectivitySurveyOptions } from '@/types/internet-connectivity-survey'
import type { ProfileOffice } from '@/types/stakeholder-profile'

type Feedback = { type: 'success' | 'error'; message: string } | null

export function ConnectivitySurveyEdit() {
  const { officeId: officeIdParam } = useParams<{ officeId: string }>()
  const officeId = Number(officeIdParam)
  const navigate = useNavigate()
  const { can } = useAuth()
  const allowed = (permission: PermissionName) => can(permission)
  const canViewAll = allowed('internet_connectivity.view_all')
  // Mirrors InternetConnectivitySurveyPolicy::update(); the server still re-checks the office scope.
  const canEdit = allowed('internet_connectivity.edit') || canViewAll

  const [office, setOffice] = useState<ProfileOffice | null>(null)
  const [options, setOptions] = useState<ConnectivitySurveyOptions | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [form, setForm] = useState<ConnectivitySurveyFormData | null>(null)
  const [savedForm, setSavedForm] = useState<ConnectivitySurveyFormData | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [feedback, setFeedback] = useState<Feedback>(null)

  usePageTitle(office ? `Internet Connectivity Survey - ${office.office_name}` : 'Internet Connectivity Survey')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadError(null)
    Promise.all([connectivitySurveyService.show(officeId), connectivitySurveyService.options()])
      .then(([envelope, loadedOptions]) => {
        if (cancelled) return
        const initial = connectivitySurveyFormFrom(envelope.survey)
        setOffice(envelope.office)
        setOptions(loadedOptions)
        setForm(initial)
        setSavedForm(initial)
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractError(error, 'Unable to load this internet connectivity survey.'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [officeId])

  if (loading) return <DetailPageSkeleton />
  if (loadError || !office || !form) {
    return (
      <Alert type="error">
        <span role="alert">{loadError ?? 'Internet connectivity survey not found.'}</span>
      </Alert>
    )
  }

  const dirty = JSON.stringify(form) !== JSON.stringify(savedForm)

  function setField<K extends keyof ConnectivitySurveyFormData>(field: K, value: ConnectivitySurveyFormData[K]) {
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!form || !canEdit) return
    setSaving(true)
    setErrors({})
    setFeedback(null)
    try {
      const response = await connectivitySurveyService.update(officeId, form)
      const next = connectivitySurveyFormFrom(response.data.survey)
      setOffice(response.data.office)
      setForm(next)
      setSavedForm(next)
      setFeedback({ type: 'success', message: response.message })
    } catch (error) {
      const fieldErrors = isValidationError(error) ? extractFieldErrors(error) : null
      if (fieldErrors) setErrors(fieldErrors)
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
        {canViewAll && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/internet-connectivity-surveys')}
            aria-label="Back to internet connectivity surveys"
            className="mt-1.5"
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        )}
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-700" aria-hidden="true">
          <Signal className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-gray-900">Internet Connectivity Survey — {office.office_name}</h1>
          <p className="text-sm text-gray-600">ISP availability, mobile connectivity, coverage, and electricity for this school/office.</p>
        </div>
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          <span role={feedback.type === 'error' ? 'alert' : 'status'}>{feedback.message}</span>
        </Alert>
      )}
      {!canEdit && (
        <Alert type="info" className="mb-4">
          You can view this survey but not edit it.
        </Alert>
      )}

      <form noValidate onSubmit={handleSubmit} aria-label={`Internet connectivity survey of ${office.office_name}`}>
        <Card>
          <CardContent className="p-0">
            <ConnectivitySurveyFields form={form} onChange={setField} options={options} errors={errors} disabled={!canEdit || saving} />
          </CardContent>
          {canEdit && (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3">
              <div>
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
              </div>
              <div className="flex items-center gap-3">
                {dirty ? (
                  <span className="text-xs font-medium text-amber-700">Unsaved changes</span>
                ) : (
                  <span className="text-xs text-gray-400">All changes saved</span>
                )}
                <Button type="submit" isLoading={saving} disabled={!dirty}>
                  Save changes
                </Button>
              </div>
            </div>
          )}
        </Card>
      </form>
    </div>
  )
}
