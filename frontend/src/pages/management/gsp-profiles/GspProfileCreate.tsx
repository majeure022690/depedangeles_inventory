import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { GspProfileForm } from '@/components/gsp-profiles/GspProfileForm'
import { Alert } from '@/components/ui/Alert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { emptyGspProfileForm, gspProfileService } from '@/services/gspProfileService'
import type { GspProfileOptions } from '@/types/gsp-profile'

export function GspProfileCreate() {
  usePageTitle('New GSP ICT profile')
  const navigate = useNavigate()
  const { user } = useAuth()
  const officeLocked = !hasAllScope(user)

  const [options, setOptions] = useState<GspProfileOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    gspProfileService
      .options()
      .then(setOptions)
      .catch((error: unknown) => setLoadError(extractError(error, 'Unable to load the form options. Please refresh the page.')))
  }, [])

  const ownOffice = officeLocked && typeof user?.office_id === 'number' ? String(user.office_id) : ''
  const defaultLevel = options?.office.find((office) => String(office.value) === ownOffice)?.default_level ?? 'elementary'

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">New GSP ICT profile</h1>
        <p className="text-sm text-gray-600">Government School Profile, Part VI (ICT in Schools) for one school year.</p>
      </div>
      {loadError && (
        <Alert type="error">
          <span role="alert">{loadError}</span>
        </Alert>
      )}
      {!options && !loadError && <DetailPageSkeleton />}
      {options && (
        <GspProfileForm
          initial={{ ...emptyGspProfileForm(ownOffice), level: defaultLevel }}
          options={options}
          officeLocked={officeLocked}
          submit={gspProfileService.create}
          onSaved={(profile, message) =>
            navigate(`/gsp-profiles/${profile.id}/edit`, {
              state: { flash: message },
            })
          }
        />
      )}
    </div>
  )
}
