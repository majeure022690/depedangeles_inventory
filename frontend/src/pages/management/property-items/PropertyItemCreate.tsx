import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { PropertyItemForm } from '@/components/property-items/PropertyItemForm'
import { Alert } from '@/components/ui/Alert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { emptyPropertyItemForm, propertyItemService } from '@/services/propertyItemService'
import type { PropertyItemOptions } from '@/types/property-item'

export function PropertyItemCreate() {
  usePageTitle('Add school property')
  const navigate = useNavigate()
  const { user } = useAuth()
  const officeLocked = !hasAllScope(user)

  const [options, setOptions] = useState<PropertyItemOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    propertyItemService
      .options()
      .then(setOptions)
      .catch((error: unknown) => setLoadError(extractError(error, 'Unable to load the form options. Please refresh the page.')))
  }, [])

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">Add school property</h1>
        <p className="text-sm text-gray-600">Record an item, or a batch of identical items, for the physical count report.</p>
      </div>
      {loadError && (
        <Alert type="error">
          <span role="alert">{loadError}</span>
        </Alert>
      )}
      {!options && !loadError && <DetailPageSkeleton />}
      {options && (
        <PropertyItemForm
          initial={emptyPropertyItemForm(officeLocked && typeof user?.office_id === 'number' ? String(user.office_id) : '')}
          options={options}
          officeLocked={officeLocked}
          submit={propertyItemService.create}
          onSaved={(item, message) =>
            navigate(`/school-property/${item.id}/edit`, {
              state: { flash: message },
            })
          }
        />
      )}
    </div>
  )
}
