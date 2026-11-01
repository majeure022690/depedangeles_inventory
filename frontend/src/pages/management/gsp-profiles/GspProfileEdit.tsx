import { useEffect, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { GspProfileForm } from '@/components/gsp-profiles/GspProfileForm'
import { Alert } from '@/components/ui/Alert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractLoadError } from '@/lib/errors'
import { gspProfileFormFrom, gspProfileService } from '@/services/gspProfileService'
import type { GspProfile, GspProfileOptions } from '@/types/gsp-profile'

export function GspProfileEdit() {
  const { id } = useParams<{ id: string }>()
  const profileId = Number(id)
  const location = useLocation()
  const { user, can } = useAuth()

  const [profile, setProfile] = useState<GspProfile | null>(null)
  const [options, setOptions] = useState<GspProfileOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [flash, setFlash] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)

  usePageTitle(profile ? `GSP ICT profile - ${profile.office?.office_name ?? ''} ${profile.school_year}` : 'GSP ICT profile')

  useEffect(() => {
    let cancelled = false
    Promise.all([gspProfileService.show(profileId), gspProfileService.options()])
      .then(([loadedProfile, loadedOptions]) => {
        if (cancelled) return
        setProfile(loadedProfile)
        setOptions(loadedOptions)
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractLoadError(error, 'Unable to load this GSP ICT profile.'))
      })
    return () => {
      cancelled = true
    }
  }, [profileId])

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">
          {profile ? `${profile.office?.office_name ?? 'GSP ICT profile'} · SY ${profile.school_year}` : 'GSP ICT profile'}
        </h1>
        <p className="text-sm text-gray-600">Government School Profile, Part VI (ICT in Schools).</p>
      </div>
      {flash && (
        <Alert type="success" className="mb-4">
          <span role="status">{flash}</span>
        </Alert>
      )}
      {loadError && (
        <Alert type="error">
          <span role="alert">{loadError}</span>
        </Alert>
      )}
      {!loadError && (!profile || !options) && <DetailPageSkeleton />}
      {profile && options && (
        <GspProfileForm
          key={profile.updated_at ?? profile.id}
          initial={gspProfileFormFrom(profile)}
          options={options}
          officeLocked={!hasAllScope(user)}
          profileId={profile.id}
          readOnly={!can('gsp_profiles.edit')}
          canDownload={can('reports.gsp_ict')}
          submit={(form) => gspProfileService.update(profile.id, form)}
          onSaved={(saved, message) => {
            setProfile(saved)
            setFlash(message)
          }}
        />
      )}
    </div>
  )
}
