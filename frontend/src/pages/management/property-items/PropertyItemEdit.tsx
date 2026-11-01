import { useEffect, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'

import { DetailPageSkeleton } from '@/components/DetailPageSkeleton'
import { PropertyItemForm } from '@/components/property-items/PropertyItemForm'
import { Alert } from '@/components/ui/Alert'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { hasAllScope } from '@/lib/dataScope'
import { extractLoadError } from '@/lib/errors'
import { propertyItemFormFrom, propertyItemService } from '@/services/propertyItemService'
import type { PropertyItem, PropertyItemOptions } from '@/types/property-item'

export function PropertyItemEdit() {
  const { id } = useParams<{ id: string }>()
  const itemId = Number(id)
  const location = useLocation()
  const { user, can } = useAuth()
  const canEdit = can('property_items.edit')

  const [item, setItem] = useState<PropertyItem | null>(null)
  const [options, setOptions] = useState<PropertyItemOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [flash, setFlash] = useState<string | null>((location.state as { flash?: string } | null)?.flash ?? null)

  usePageTitle(item ? `${item.article} - School property` : 'School property')

  useEffect(() => {
    let cancelled = false
    Promise.all([propertyItemService.show(itemId), propertyItemService.options()])
      .then(([loadedItem, loadedOptions]) => {
        if (cancelled) return
        setItem(loadedItem)
        setOptions(loadedOptions)
      })
      .catch((error: unknown) => {
        if (!cancelled) setLoadError(extractLoadError(error, 'Unable to load this property item.'))
      })
    return () => {
      cancelled = true
    }
  }, [itemId])

  return (
    <div>
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">{item ? item.article : 'School property'}</h1>
        {item && (
          <p className="text-sm text-gray-600">
            {item.category_label}
            {item.office ? ` · ${item.office.office_name}` : ''}
          </p>
        )}
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
      {!loadError && (!item || !options) && <DetailPageSkeleton />}
      {item && options && (
        <PropertyItemForm
          key={item.updated_at ?? item.id}
          initial={propertyItemFormFrom(item)}
          options={options}
          officeLocked={!hasAllScope(user)}
          readOnly={!canEdit}
          submit={(form) => propertyItemService.update(item.id, form)}
          onSaved={(saved, message) => {
            setItem(saved)
            setFlash(message)
          }}
        />
      )}
    </div>
  )
}
