import { Lock, Search } from 'lucide-react'
import { useMemo, useState } from 'react'

import { groupPermissions, type PermissionGroup, type PermissionInfo } from '@/lib/permissions'
import { cn } from '@/lib/utils'

interface PermissionPickerProps {
  /** The server's permission catalog (`GET /api/permissions`). */
  available: PermissionInfo[]
  value: string[]
  onChange: (value: string[]) => void
  canGrant: (permission: string) => boolean
  /** Read-only: shows the current `value` with every checkbox disabled. */
  locked?: boolean
  /** Shown above the list when `locked` is set. */
  lockedMessage?: string
}

function matches(permission: PermissionGroup['permissions'][number], term: string): boolean {
  return [permission.label, permission.description, permission.name].some((text) => text.toLowerCase().includes(term))
}

export function PermissionPicker({
  available,
  value,
  onChange,
  canGrant,
  locked = false,
  lockedMessage = 'This role is protected. Its permissions cannot be changed.',
}: PermissionPickerProps) {
  const [search, setSearch] = useState('')
  const groups = useMemo(() => groupPermissions(available), [available])
  const term = search.trim().toLowerCase()
  const selected = new Set(value)
  const allNames = available.map((p) => p.name)
  const grantable = allNames.filter(canGrant)

  function setMany(names: string[], on: boolean) {
    const next = new Set(value)
    names.forEach((name) => (on ? next.add(name) : next.delete(name)))
    onChange(allNames.filter((name) => next.has(name)))
  }

  return (
    <div>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium text-gray-700">
          Permissions{' '}
          <span className="font-normal text-gray-500">
            · {value.length} of {available.length} selected
          </span>
        </p>
        {!locked && (
          <div className="flex items-center gap-3 text-xs font-medium">
            <button type="button" className="text-primary-700 hover:underline" onClick={() => setMany(grantable, true)}>
              Select all
            </button>
            <button type="button" className="text-gray-500 hover:underline" onClick={() => setMany(allNames, false)}>
              Clear
            </button>
          </div>
        )}
      </div>

      {locked ? (
        <p className="mb-2 flex items-center gap-1.5 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <Lock className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {lockedMessage}
        </p>
      ) : (
        <div className="relative mb-2">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Find a permission"
            aria-label="Find a permission"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="h-9 w-full rounded-lg border border-gray-300 bg-white pl-9 pr-3 text-sm placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
          />
        </div>
      )}

      <div className="columns-1 gap-3 lg:columns-2">
        {groups.map((group) => {
          const shown = term ? group.permissions.filter((p) => matches(p, term)) : group.permissions
          if (shown.length === 0) return null
          const names = group.permissions.map((p) => p.name)
          const grantableNames = names.filter(canGrant)
          const count = names.filter((n) => selected.has(n)).length
          const all = count === names.length
          const some = count > 0 && !all

          return (
            <section key={group.key} className="mb-3 break-inside-avoid overflow-hidden rounded-lg border border-gray-200">
              <label className={cn('flex items-center gap-2 bg-gray-50 px-3 py-2', !locked && 'cursor-pointer')}>
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300 text-primary-600"
                  checked={all}
                  ref={(el) => {
                    if (el) el.indeterminate = some
                  }}
                  disabled={locked || grantableNames.length === 0}
                  onChange={(e) => setMany(grantableNames, e.target.checked)}
                />
                <span className="flex-1 text-sm font-semibold text-gray-900">{group.title}</span>
                <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', count > 0 ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-500')}>
                  {count}/{names.length}
                </span>
              </label>
              <ul className="divide-y divide-gray-100">
                {shown.map((permission) => {
                  const allowed = canGrant(permission.name)
                  const checked = selected.has(permission.name)
                  return (
                    <li key={permission.name}>
                      <label
                        className={cn(
                          'flex items-start gap-3 px-3 py-2',
                          !locked && allowed && 'cursor-pointer hover:bg-gray-50',
                          (!allowed || locked) && 'opacity-70',
                        )}
                        title={!allowed && !locked ? 'You can only grant permissions you have yourself.' : undefined}
                      >
                        <input
                          type="checkbox"
                          className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600"
                          checked={checked}
                          disabled={locked || (!allowed && !checked)}
                          onChange={(e) => setMany([permission.name], e.target.checked)}
                        />
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-center gap-x-2">
                            <span className="text-sm font-medium text-gray-900">{permission.label}</span>
                            <code className="text-[11px] text-gray-400">{permission.name}</code>
                            {!allowed && !locked && <Lock className="h-3 w-3 text-gray-400" aria-label="You do not have this permission" />}
                          </span>
                          {permission.description && <span className="block text-xs text-gray-500">{permission.description}</span>}
                        </span>
                      </label>
                    </li>
                  )
                })}
              </ul>
            </section>
          )
        })}
        {term && groups.every((group) => !group.permissions.some((p) => matches(p, term))) && (
          <p className="py-6 text-center text-sm text-gray-500" style={{ columnSpan: 'all' }}>No permission matches “{search}”.</p>
        )}
      </div>
    </div>
  )
}
