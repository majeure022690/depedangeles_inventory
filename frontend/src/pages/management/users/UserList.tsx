import { Filter, Pencil, RotateCcw, ShieldCheck, ShieldOff, UserX } from 'lucide-react'
import { useEffect, useMemo, useState, type FormEvent } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { FilterChips, FilterSearch, FilterSelect, type FilterChip } from '@/components/ui/FilterControls'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SelectPicker } from '@/components/ui/SelectPicker'
import { Switch } from '@/components/ui/Switch'
import { useAuth } from '@/context/AuthContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError, extractFieldErrors, isValidationError } from '@/lib/errors'
import { roleService } from '@/services/roleService'
import { userService } from '@/services/userService'
import type { Role } from '@/types/role'
import type { ManagedUser, UserOptions } from '@/types/user'

const column = gridColumnHelper<ManagedUser>()

function initials(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

interface FormState {
  is_active: boolean
  role_id: number | null
}

function officeName(office: ManagedUser['office']): string | null {
  if (!office) return null
  return office.school_id ? `${office.office_name} (${office.school_id})` : office.office_name
}

const emptyFilters = { search: '', role: '', is_active: '', office_id: '' }

type FilterKey = keyof typeof emptyFilters

export function UserList() {
  usePageTitle('Users')
  const { can, fetchUser } = useAuth()

  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [officeFilter, setOfficeFilter] = useState('')
  const [appliedFilters, setAppliedFilters] = useState(emptyFilters)

  const { items: users, meta, loading, page, setPage, perPage, setPerPage, sortBy, sortDir, onSortChange, reload } = useServerTable(
    (params) => userService.list(params),
    { filters: appliedFilters, waitForPerPage: true },
  )

  const draftFilters = { search, role: roleFilter, is_active: statusFilter, office_id: officeFilter }
  const dirty = JSON.stringify(draftFilters) !== JSON.stringify(appliedFilters)

  function syncDraft(next: typeof emptyFilters) {
    setSearch(next.search)
    setRoleFilter(next.role)
    setStatusFilter(next.is_active)
    setOfficeFilter(next.office_id)
  }

  function applyFilters() {
    setAppliedFilters(draftFilters)
  }

  function clearFilters() {
    syncDraft(emptyFilters)
    setAppliedFilters(emptyFilters)
  }

  function removeFilter(key: FilterKey) {
    const next = { ...appliedFilters, [key]: '' }
    setAppliedFilters(next)
    syncDraft(next)
  }

  // Every role (for the filter) vs. only the roles this admin may assign (for the form).
  const [allRoles, setAllRoles] = useState<Role[]>([])
  const [options, setOptions] = useState<UserOptions>({ roles: [], offices: [] })
  const [optionsError, setOptionsError] = useState<string | null>(null)

  const canViewRoles = can('roles.view')

  useEffect(() => {
    userService
      .options()
      .then(setOptions)
      .catch((loadError: unknown) => setOptionsError(extractError(loadError, 'Unable to load roles and offices.')))
    if (!canViewRoles) return
    roleService
      .list()
      .then(setAllRoles)
      .catch(() => setAllRoles([]))
  }, [canViewRoles])

  const officePickerOptions = useMemo(() => options.offices.map((o) => ({ label: o.label, value: String(o.value) })), [options.offices])

  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [pageError, setPageError] = useState<string | null>(null)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<ManagedUser | null>(null)
  const [form, setForm] = useState<FormState>({ is_active: true, role_id: null })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function openEdit(user: ManagedUser) {
    if (!user.can_edit) return
    setEditing(user)
    setForm({ is_active: user.is_active, role_id: user.role?.id ?? null })
    setErrors({})
    setFormError(null)
    setDialogOpen(true)
  }

  const editingSelf = editing?.is_self === true

  // The user's current role stays selectable even if it is not in the assignable list, so opening the form doesn't blank it.
  const roleChoices = useMemo(() => {
    const choices = options.roles.map((role) => ({ label: role.label, value: role.id }))
    const current = editing?.role
    if (current && !options.roles.some((role) => role.id === current.id)) choices.push({ label: current.label, value: current.id })
    return choices
  }, [options.roles, editing])

  async function handleSave(event: FormEvent) {
    event.preventDefault()
    if (!editing) return
    if (form.role_id === null) {
      setErrors({ role_id: 'Choose a role.' })
      setFormError('Please correct the highlighted fields.')
      return
    }
    setSaving(true)
    setErrors({})
    setFormError(null)

    try {
      await userService.update(editing.id, { is_active: form.is_active, role_id: form.role_id })
      setSuccessMessage(`${editing.name} was updated.`)
      setPageError(null)
      setDialogOpen(false)
      await reload()
      if (editingSelf) await fetchUser()
    } catch (saveError) {
      const fields = isValidationError(saveError) ? extractFieldErrors(saveError) : null
      if (fields) {
        setErrors(fields)
        setFormError('Please correct the highlighted fields.')
      } else {
        setFormError(extractError(saveError))
      }
    } finally {
      setSaving(false)
    }
  }

  // Delete
  const [deleting, setDeleting] = useState<ManagedUser | null>(null)
  const [deleteLoading, setDeleteLoading] = useState(false)

  async function handleDelete() {
    if (!deleting) return
    setDeleteLoading(true)
    try {
      await userService.remove(deleting.id)
      setSuccessMessage(`${deleting.name} was deleted.`)
      setPageError(null)
      await reload()
    } catch (deleteError) {
      setSuccessMessage(null)
      setPageError(extractError(deleteError, 'This user could not be deleted.'))
    } finally {
      setDeleteLoading(false)
      setDeleting(null)
    }
  }

  // Reset 2FA
  const [resetting2fa, setResetting2fa] = useState<ManagedUser | null>(null)
  const [reset2faPassword, setReset2faPassword] = useState('')
  const [reset2faLoading, setReset2faLoading] = useState(false)
  const [reset2faError, setReset2faError] = useState<string | null>(null)

  function confirmReset2fa(user: ManagedUser) {
    setResetting2fa(user)
    setReset2faPassword('')
    setReset2faError(null)
  }

  async function handleReset2fa(event: FormEvent) {
    event.preventDefault()
    if (!resetting2fa) return
    setReset2faLoading(true)
    setReset2faError(null)
    try {
      const result = await userService.resetTwoFactor(resetting2fa.id, reset2faPassword)
      setSuccessMessage(result.message)
      setPageError(null)
      setResetting2fa(null)
      await reload()
    } catch (resetError) {
      setReset2faError(extractError(resetError, 'Unable to reset two-factor authentication.'))
    } finally {
      setReset2faLoading(false)
    }
  }

  const officeLabel = (id: string) => options.offices.find((o) => String(o.value) === id)?.label ?? 'Office'
  const filterRoles = allRoles.length > 0 ? allRoles : options.roles
  const roleLabel = (name: string) => filterRoles.find((r) => r.name === name)?.label ?? name

  const activeChips: FilterChip<FilterKey>[] = [
    { key: 'search' as const, label: `Search: ${appliedFilters.search}`, removable: true },
    { key: 'role' as const, label: `Role: ${roleLabel(appliedFilters.role)}`, removable: true },
    { key: 'is_active' as const, label: appliedFilters.is_active === '1' ? 'Active' : 'Inactive', removable: true },
    { key: 'office_id' as const, label: officeLabel(appliedFilters.office_id), removable: true },
  ].filter((chip) => appliedFilters[chip.key] !== '')

  const columns = useMemo<GridColumn<ManagedUser>[]>(
    () => [
      column.accessor('name', {
        header: 'Name',
        enableHiding: false,
        cell: ({ row }) => (
          <div className="flex items-center gap-3">
            {row.original.avatar_url ? (
              <img src={row.original.avatar_url} alt="" className="h-8 w-8 shrink-0 rounded-full object-cover" />
            ) : (
              <span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">
                {initials(row.original.name)}
              </span>
            )}
            <div className="min-w-0">
              <p className="truncate font-medium text-gray-900">
                {row.original.name}
                {row.original.is_self && <span className="ml-1.5 text-xs font-normal text-gray-500">(you)</span>}
              </p>
              <p className="truncate text-xs text-gray-500">{row.original.email}</p>
            </div>
          </div>
        ),
      }),
      column.accessor('email', {
        header: 'Email',
        meta: { defaultHidden: true },
        cell: (info) => info.getValue(),
      }),
      column.display({
        id: 'roles',
        header: 'Role',
        cell: ({ row }) => row.original.role?.label ?? <span className="text-gray-500">No role</span>,
      }),
      column.display({
        id: 'office',
        header: 'Office',
        cell: ({ row }) => {
          const office = row.original.office
          if (!office) return <span className="text-gray-500">—</span>
          return (
            <span>
              {office.office_name}
              {office.school_id && <span className="ml-1 text-xs text-gray-500">({office.school_id})</span>}
            </span>
          )
        },
      }),
      column.accessor('employee_no', {
        header: 'Employee no.',
        enableSorting: false,
        cell: ({ row }) => {
          const { employee_no: employeeNo, personnel } = row.original
          if (!employeeNo) return <span className="text-gray-500">—</span>
          return (
            <div className="min-w-0">
              <p className="whitespace-nowrap tabular-nums">{employeeNo}</p>
              {personnel && <p className="truncate text-xs text-gray-500">{personnel.full_name}</p>}
            </div>
          )
        },
      }),
      column.display({
        id: 'two_factor',
        header: '2FA',
        meta: { className: 'whitespace-nowrap' },
        cell: ({ row }) =>
          row.original.two_factor_enabled ? (
            <span className="inline-flex items-center gap-1 text-xs font-medium text-success-700">
              <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
              On
            </span>
          ) : (
            <span className="text-xs text-gray-500">Off</span>
          ),
      }),
      column.accessor('is_active', {
        header: 'Status',
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <Badge color={info.getValue() ? 'success' : 'gray'}>{info.getValue() ? 'Active' : 'Inactive'}</Badge>,
      }),
      column.accessor('created_at', {
        header: 'Created',
        meta: { defaultHidden: true, className: 'whitespace-nowrap' },
        cell: (info) => new Date(info.getValue()).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }),
      }),
      column.display({
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        enableHiding: false,
        meta: { align: 'right' },
        cell: ({ row }) => {
          const user = row.original
          if (!user.can_edit && !user.can_delete && !user.can_reset_two_factor) return null
          return (
            <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
              {user.can_edit && (
                <Button variant="ghost" size="sm" onClick={() => openEdit(user)} aria-label={`Edit ${user.name}`} title="Edit">
                  <Pencil className="h-4 w-4 text-primary-600" aria-hidden="true" />
                </Button>
              )}
              {user.can_reset_two_factor && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => confirmReset2fa(user)}
                  aria-label={`Reset two-factor authentication for ${user.name}`}
                  title="Reset two-factor authentication"
                >
                  <ShieldOff className="h-4 w-4 text-warning-600" aria-hidden="true" />
                </Button>
              )}
              {user.can_delete && (
                <Button variant="ghost" size="sm" onClick={() => setDeleting(user)} aria-label={`Delete user ${user.name}`} title="Delete user">
                  <UserX className="h-4 w-4 text-error-600" aria-hidden="true" />
                </Button>
              )}
            </div>
          )
        },
      }),
    ],
    // openEdit/confirmReset2fa only read state setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Users</h1>
          <p className="text-sm text-gray-600">Portal accounts synced from PRIME. Manage roles, office assignments, and account status.</p>
        </div>
      </div>

      {successMessage && (
        <Alert type="success" className="mb-4">
          {successMessage}
        </Alert>
      )}
      {(pageError || optionsError) && (
        <Alert type="error" className="mb-4">
          {pageError ?? optionsError}
        </Alert>
      )}

      <div className="mb-4 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-12">
          <div className="sm:col-span-2 lg:col-span-4">
            <FilterSearch label="Search by name or email" value={search} onChange={setSearch} onSubmit={applyFilters} />
          </div>
          <FilterSelect label="Role" value={roleFilter} onChange={setRoleFilter} className="lg:col-span-3">
            <option value="">All roles</option>
            {filterRoles.map((role) => (
              <option key={role.id} value={role.name}>
                {role.label}
              </option>
            ))}
          </FilterSelect>
          <FilterSelect label="Status" value={statusFilter} onChange={setStatusFilter} className="lg:col-span-2">
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </FilterSelect>
          <div className="sm:col-span-2 lg:col-span-3">
            <SelectPicker
              aria-label="Office"
              placeholder="All offices"
              options={officePickerOptions}
              selectedValue={officeFilter || null}
              onSelect={(option) => setOfficeFilter(option ? option.value : '')}
            />
          </div>
        </div>

        <div className="mt-3 flex flex-col-reverse gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:items-center sm:justify-between">
          <FilterChips chips={activeChips} onRemove={removeFilter} />
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={clearFilters}
              size="sm"
              leftIcon={<RotateCcw className="h-3.5 w-3.5" />}
              className="flex-1 sm:flex-none"
              disabled={!dirty && activeChips.length === 0}
            >
              Reset
            </Button>
            <Button onClick={applyFilters} size="sm" leftIcon={<Filter className="h-3.5 w-3.5" />} className="flex-1 sm:flex-none">
              Apply Filters
            </Button>
          </div>
        </div>
      </div>

      <DataGrid
        id="users"
        columns={columns}
        rows={users}
        rowKey={(row) => row.id}
        loading={loading}
        meta={meta}
        page={page}
        onPageChange={setPage}
        perPage={perPage}
        onPerPageChange={setPerPage}
        sortBy={sortBy}
        sortDir={sortDir}
        onSortChange={onSortChange}
        rowHeights={{ comfortable: 61, compact: 49 }}
        emptyMessage="No users match these filters."
        onRowClick={openEdit}
      />

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent size="lg">
          <DialogTitle>Edit User</DialogTitle>
          <DialogDescription>Each user holds one role, which decides what they can see and do.</DialogDescription>

          <form onSubmit={handleSave} className="space-y-3" noValidate>
            {formError && <Alert type="error">{formError}</Alert>}

            <dl className="grid gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm sm:grid-cols-2">
              <div className="min-w-0">
                <dt className="text-xs font-medium text-gray-500">Name</dt>
                <dd className="truncate text-gray-900">{editing?.name}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-xs font-medium text-gray-500">Email</dt>
                <dd className="truncate text-gray-900">{editing?.email}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-xs font-medium text-gray-500">School / Office</dt>
                <dd className="text-gray-900">{officeName(editing?.office ?? null) ?? <span className="text-gray-500">No office</span>}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-xs font-medium text-gray-500">Employee no.</dt>
                <dd className="text-gray-900">
                  {editing?.employee_no ?? <span className="text-gray-500">None</span>}
                  {editing?.personnel && <span className="block truncate text-xs text-gray-500">{editing.personnel.full_name}</span>}
                </dd>
              </div>
            </dl>
            <p className="text-xs text-gray-500">
              Name, email, office, and employee number come from PRIME. Passwords are managed in PRIME. Users change or recover their password there.
            </p>

            <Select
              id="user-role"
              label="Role"
              required
              placeholder="Select role"
              options={roleChoices}
              value={form.role_id ?? ''}
              error={errors.role_id}
              hint={roleChoices.length === 0 ? 'There are no roles you can assign.' : undefined}
              onChange={(e) => setForm((f) => ({ ...f, role_id: e.target.value ? Number(e.target.value) : null }))}
            />

            <Switch
              id="user-is-active"
              checked={form.is_active}
              onChange={(checked) => setForm((f) => ({ ...f, is_active: checked }))}
              label="Active (can sign in)"
              disabled={editingSelf}
              hint={editingSelf ? "You can't deactivate your own account." : undefined}
              error={errors.is_active}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" isLoading={saving}>
                Save Changes
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        onConfirm={handleDelete}
        title="Delete user?"
        description={`This permanently deletes ${deleting?.name ?? 'this user'}'s account. Records and audit history they created are kept. This cannot be undone.`}
        confirmText="Delete"
        loading={deleteLoading}
      />

      <Dialog open={resetting2fa !== null} onOpenChange={(open) => !open && setResetting2fa(null)}>
        <DialogContent size="sm">
          <DialogTitle>Reset two-factor authentication?</DialogTitle>
          <DialogDescription>
            {resetting2fa?.name} will be asked to set up two-factor authentication again the next time they sign in, and their trusted
            devices are forgotten. Use this for a lost or replaced authenticator device. Enter your own password to confirm.
          </DialogDescription>
          <form onSubmit={handleReset2fa} className="space-y-3">
            {reset2faError && <Alert type="error">{reset2faError}</Alert>}
            <Input
              label="Your current password"
              type="password"
              autoComplete="current-password"
              value={reset2faPassword}
              onChange={(event) => setReset2faPassword(event.target.value)}
              required
            />
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setResetting2fa(null)}>
                Cancel
              </Button>
              <Button type="submit" variant="danger" isLoading={reset2faLoading} disabled={!reset2faPassword}>
                Reset 2FA
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}
