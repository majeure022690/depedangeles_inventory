import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { InventoryCountForm } from '@/components/inventory-counts/InventoryCountForm'
import { Alert } from '@/components/ui/Alert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractError } from '@/lib/errors'
import { emptyInventoryCountForm, inventoryCountService } from '@/services/inventoryCountService'
import type { InventoryCountOptions } from '@/types/inventory-count'

export function InventoryCountCreate() {
  usePageTitle('New inventory count')
  const navigate = useNavigate()
  const { user } = useAuth()

  const [options, setOptions] = useState<InventoryCountOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    inventoryCountService
      .options()
      .then(setOptions)
      .catch((error: unknown) => setLoadError(extractError(error, 'Unable to load the form options. Please refresh the page.')))
  }, [])

  const ownOffice = !hasAllScope(user) && typeof user?.office_id === 'number' ? [user.office_id] : []

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">New inventory count</h1>
        <p className="text-sm text-gray-600">Saved as a draft. Opening it builds the list of items to count from the Equipment records.</p>
      </div>
      {loadError && (
        <Alert type="error">
          <span role="alert">{loadError}</span>
        </Alert>
      )}
      {!options && !loadError && <DetailPageSkeleton />}
      {options && (
        <InventoryCountForm
          initial={emptyInventoryCountForm(ownOffice)}
          options={options}
          officesLocked={false}
          submit={inventoryCountService.create}
          onSaved={(count, message) => navigate(`/inventory-counts/${count.id}`, { state: { flash: message } })}
          onCancel={() => navigate('/inventory-counts')}
        />
      )}
    </div>
  )
}
