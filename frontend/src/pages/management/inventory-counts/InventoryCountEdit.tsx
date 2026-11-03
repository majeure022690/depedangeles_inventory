import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { InventoryCountForm } from '@/components/inventory-counts/InventoryCountForm'
import { Alert } from '@/components/ui/Alert'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractLoadError } from '@/lib/errors'
import { inventoryCountFormFrom, inventoryCountService } from '@/services/inventoryCountService'
import type { InventoryCount, InventoryCountOptions } from '@/types/inventory-count'

export function InventoryCountEdit() {
  usePageTitle('Edit inventory count')
  const navigate = useNavigate()
  const id = Number(useParams().id)

  const [count, setCount] = useState<InventoryCount | null>(null)
  const [options, setOptions] = useState<InventoryCountOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    inventoryCountService
      .show(id)
      .then(async (loaded) => {
        setOptions(await inventoryCountService.options(loaded.office_ids))
        setCount(loaded)
      })
      .catch((error: unknown) => setLoadError(extractLoadError(error, 'Unable to load the count. Please refresh the page.')))
  }, [id])

  const editable = count !== null && (count.status === 'draft' || count.status === 'open')

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">Edit inventory count</h1>
        {count && <p className="text-sm text-gray-600">{count.name}</p>}
      </div>
      {loadError && (
        <Alert type="error">
          <span role="alert">{loadError}</span>
        </Alert>
      )}
      {!loadError && (!count || !options) && <DetailPageSkeleton />}
      {count && options && !editable && (
        <Alert type="info">
          <span role="status">A closed or cancelled count can no longer be edited.</span>
        </Alert>
      )}
      {count && options && editable && (
        <InventoryCountForm
          initial={inventoryCountFormFrom(count)}
          options={options}
          officesLocked={count.status !== 'draft'}
          current={count}
          submit={(form) => inventoryCountService.update(id, form)}
          onSaved={(saved, message) => navigate(`/inventory-counts/${saved.id}`, { state: { flash: message } })}
          onCancel={() => navigate(`/inventory-counts/${id}`)}
        />
      )}
    </div>
  )
}
