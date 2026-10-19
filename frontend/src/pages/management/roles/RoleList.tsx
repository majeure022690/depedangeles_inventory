import { isAxiosError } from 'axios'
import { Lock, Pencil, Plus, ShieldCheck, Trash2, Users as UsersIcon } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'

import { PermissionPicker } from '@/components/management/PermissionPicker'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { FilterSearch } from '@/components/ui/FilterControls'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { Textarea } from '@/components/ui/Textarea'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { extractError } from '@/lib/errors'
import type { PermissionInfo } from '@/lib/permissions'
import { permissionService, roleService, type RolePayload } from '@/services/roleService'
import type { ApiValidationError } from '@/types/api'
import type { Role } from '@/types/role'

const column = gridColumnHelper<Role>()

/** First message per field from a 422, or null for any other error. */
function fieldErrors(error: unknown): Record<string, string> | null {
  if (!isAxiosError<ApiValidationError>(error) || error.response?.status !== 422) return null
  return Object.fromEntries(Object.entries(error.response.data.errors ?? {}).map(([field, messages]) => [field, messages[0] ?? '']))
}

const emptyForm: RolePayload = { name: '', label: '', description: '', permissions: [] }

// The server only restricts *who* edits roles (roles.manage), not which permissions they may grant.
const canGrantAny = () => true

export function RoleList() {
  usePageTitle('Roles')
  const { fetchUser } = useAuth()

  const [roles, setRoles] = useState<Role[]>([])
  const [permissions, setPermissions] = useState<PermissionInfo[]>([])
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [pageError, setPageError] = useState<string | null>(null)

  async function loadRoles() {
    setLoading(true)
    setLoadError(null)
    try {
      setRoles(await roleService.list())
    } catch (error) {
      setLoadError(extractError(error, 'Unable to load roles. Please try again.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    permissionService
      .list()
      .then(setPermissions)
      .catch((error: unknown) => setPageError(extractError(error, 'Unable to load the permission catalog.')))
    loadRoles()
  }, [])

  // Create/edit dialog
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Role | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const detailRequest = useRef(0)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState<RolePayload>(emptyForm)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)

  const isProtected = editing?.is_protected ?? false

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    setErrors({})
    setFormError(null)
    setDialogOpen(true)
  }

  async function openEdit(role: Role) {
    setEditing(role)
    setForm({ name: role.name, label: role.label, description: role.description ?? '', permissions: [] })
    setErrors({})
    setFormError(null)
    setDialogOpen(true)
    // The list carries only permission_count; the role's permission names come from its detail.
    const requestId = ++detailRequest.current
    setDetailLoading(true)
    try {
      const detail = await roleService.show(role.id)
      if (requestId !== detailRequest.current) return
      setEditing(detail)
      setForm({ name: detail.name, label: detail.label, description: detail.description ?? '', permissions: [...(detail.permissions ?? [])] })
    } catch (error) {
      if (requestId === detailRequest.current) setFormError(extractError(error, 'Unable to load this role.'))
    } finally {
      if (requestId === detailRequest.current) setDetailLoading(false)
    }
  }

  async function handleSave(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setFormError(null)

    const payload: RolePayload = {
      name: form.name.trim(),
      label: form.label.trim(),
      description: form.description?.trim() ? form.description.trim() : null,
      // A protected role must always have zero permissions; never send any for it.
      permissions: isProtected ? [] : form.permissions,
    }

    try {
      if (editing) {
        await roleService.update(editing.id, payload)
        setSuccessMessage(`The ${payload.label} role was updated.`)
        // Editing a role you hold changes your own permissions.
        await fetchUser()
      } else {
        await roleService.create(payload)
        setSuccessMessage(`The ${payload.label} role was created.`)
      }
      setPageError(null)
      setDialogOpen(false)
      await loadRoles()
    } catch (error) {
      const fields = fieldErrors(error)
      if (fields) {
        setErrors(fields)
        setFormError(fields.permissions ? null : 'Please correct the highlighted fields.')
      } else {
        setFormError(extractError(error))
      }
    } finally {
      setSaving(false)
    }
  }

  // Delete (409 for protected or still-assigned roles)
  const [deleting, setDeleting] = useState<Role | null>(null)
  const [deleteLoading, setDeleteLoading] = useState(false)

  async function handleDelete() {
    if (!deleting) return
    setDeleteLoading(true)
    try {
      await roleService.remove(deleting.id)
      setSuccessMessage(`The ${deleting.label} role was deleted.`)
      setPageError(null)
      await loadRoles()
    } catch (error) {
      setSuccessMessage(null)
      setPageError(extractError(error, 'This role cannot be deleted.'))
    } finally {
      setDeleteLoading(false)
      setDeleting(null)
    }
  }

  const [search, setSearch] = useState('')
  const visibleRoles = useMemo(() => {
    const term = search.trim().toLowerCase()
    return term
      ? roles.filter((role) => [role.label, role.name, role.description ?? ''].some((text) => text.toLowerCase().includes(term)))
      : roles
  }, [roles, search])

  const columns = useMemo<GridColumn<Role>[]>(
    () => [
      column.accessor('label', {
        header: 'Role',
        enableHiding: false,
        enableSorting: false,
        cell: ({ row }) => (
          <div className="min-w-0">
            <p className="inline-flex items-center gap-2 font-medium text-gray-900">
              <ShieldCheck className="h-4 w-4 shrink-0 text-primary-500" aria-hidden="true" />
              {row.original.label}
              {row.original.is_protected && (
                <Badge color="warning" className="gap-1">
                  <Lock className="h-3 w-3" aria-hidden="true" />
                  Protected
                </Badge>
              )}
            </p>
            <p className="text-xs text-gray-500">
              <code>{row.original.name}</code>
            </p>
          </div>
        ),
      }),
      column.accessor('description', {
        header: 'Description',
        enableSorting: false,
        cell: (info) => info.getValue() || <span className="text-gray-400">—</span>,
      }),
      column.display({
        id: 'permissions',
        header: 'Permissions',
        meta: { className: 'whitespace-nowrap' },
        cell: ({ row }) => {
          const count = row.original.permission_count ?? 0
          return (
            <Badge color={count === 0 ? 'gray' : 'primary'}>
              {count} of {permissions.length || '…'}
            </Badge>
          )
        },
      }),
      column.accessor('user_count', {
        header: 'Users',
        enableSorting: false,
        meta: { align: 'center' },
        cell: (info) => (
          <span className="inline-flex items-center gap-1.5 text-gray-700">
            <UsersIcon className="h-3.5 w-3.5 text-gray-400" aria-hidden="true" />
            {info.getValue() ?? 0}
          </span>
        ),
      }),
      column.display({
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        enableHiding: false,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
            <Button variant="ghost" size="sm" onClick={() => openEdit(row.original)} aria-label={`Edit ${row.original.label} role`} title="Edit role">
              <Pencil className="h-4 w-4 text-primary-600" />
            </Button>
            {!row.original.is_protected && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setDeleting(row.original)}
                aria-label={`Delete ${row.original.label} role`}
                title="Delete role"
              >
                <Trash2 className="h-4 w-4 text-error-600" />
              </Button>
            )}
          </div>
        ),
      }),
    ],
    // openEdit only reads state setters and services.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [permissions.length],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Roles</h1>
          <p className="text-sm text-gray-600">Each role is a set of permissions. A user can hold several roles; they can do everything those roles grant together.</p>
        </div>
        <div className="page-actions">
          <Button leftIcon={<Plus className="h-4 w-4" />} onClick={openCreate} size="sm">
            New Role
          </Button>
        </div>
      </div>

      {successMessage && (
        <Alert type="success" className="mb-4">
          {successMessage}
        </Alert>
      )}
      {pageError && (
        <Alert type="error" className="mb-4">
          {pageError}
        </Alert>
      )}

      <DataGrid
        id="roles"
        columns={columns}
        rows={visibleRoles}
        rowKey={(row) => row.id}
        loading={loading}
        error={loadError}
        page={1}
        onPageChange={() => {}}
        emptyMessage={search ? 'No roles match this search.' : 'No roles found.'}
        onRowClick={(row) => openEdit(row)}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-3">
            <div className="w-full sm:w-64">
              <FilterSearch label="Search roles" value={search} onChange={setSearch} onSubmit={() => {}} />
            </div>
            <span>
              <span className="font-semibold text-gray-900">{visibleRoles.length}</span> of {roles.length} roles
            </span>
          </div>
        }
      />

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent size="xl">
          <DialogTitle>{editing ? 'Edit Role' : 'New Role'}</DialogTitle>
          <DialogDescription>Pick what people with this role can see and do. Which school or office they belong to is set on each user account.</DialogDescription>

          <form onSubmit={handleSave} className="space-y-3" noValidate>
            {formError && <Alert type="error">{formError}</Alert>}

            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                label="Display Label"
                required
                value={form.label}
                error={errors.label}
                onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
              />
              <Input
                label="Name (key)"
                required
                autoComplete="off"
                value={form.name}
                error={errors.name}
                disabled={isProtected}
                hint={isProtected ? "This role's name is fixed and cannot be changed." : 'Lowercase letters, numbers, dashes and underscores only.'}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              />
            </div>
            <Textarea
              label="Description"
              rows={2}
              value={form.description ?? ''}
              error={errors.description}
              onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            />

            {isProtected ? (
              <Alert type="warning">
                This role is protected. It must always have zero permissions: it is the deny-by-default role for accounts that have not been given
                access yet. Only its label and description can be changed.
              </Alert>
            ) : detailLoading ? (
              <div className="flex min-h-48 items-center justify-center" role="status" aria-label="Loading permissions">
                <Spinner />
              </div>
            ) : (
              <PermissionPicker
                available={permissions}
                value={form.permissions}
                onChange={(next) => setForm((f) => ({ ...f, permissions: next }))}
                canGrant={canGrantAny}
              />
            )}
            {errors.permissions && <Alert type="error">{errors.permissions}</Alert>}

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" isLoading={saving} disabled={detailLoading}>
                {editing ? 'Save Changes' : 'Create Role'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        onConfirm={handleDelete}
        title="Delete role?"
        description={`Delete the ${deleting?.label ?? ''} role? A role still assigned to users cannot be deleted. This cannot be undone.`}
        confirmText="Delete"
        loading={deleteLoading}
      />
    </div>
  )
}
