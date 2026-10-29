import { isAxiosError } from 'axios'
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataGrid, gridColumnHelper, type GridColumn } from '@/components/ui/DataGrid'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { FilterSearch, FilterSelect } from '@/components/ui/FilterControls'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Textarea } from '@/components/ui/Textarea'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useServerTable } from '@/hooks/useServerTable'
import { extractError, extractFieldErrors, isValidationError } from '@/lib/errors'
import { referenceDataService } from '@/services/referenceDataService'
import type { ReferenceDataRow, ReferenceDataTableMeta, ReferenceDataTier2Row } from '@/types/reference-data'

const column = gridColumnHelper<ReferenceDataRow>()

type Feedback = { type: 'success' | 'error'; message: string }

interface RowForm {
  name: string
  type: string
  value: string
  abbreviation: string
  dashboard_group: string
  default_uacs: string
  default_useful_life: string
  description: string
  sort_order: string
  is_active: boolean
}

type RowFormErrors = Partial<Record<keyof RowForm, string>>

const EMPTY_FORM: RowForm = {
  name: '',
  type: '',
  value: '',
  abbreviation: '',
  dashboard_group: '',
  default_uacs: '',
  default_useful_life: '',
  description: '',
  sort_order: '0',
  is_active: true,
}

const blankToNull = (value: string) => (value.trim() === '' ? null : value.trim())

function isTier2Row(row: ReferenceDataRow): row is ReferenceDataTier2Row {
  return 'value' in row
}

function rowLabel(row: ReferenceDataRow): string {
  return isTier2Row(row) ? row.value : row.name
}

/** Route `/reference-data/:table`. Keyed by table so switching tables resets paging, filters and dialogs. */
export function ReferenceDataTable() {
  const { table = '' } = useParams<{ table: string }>()
  return <ReferenceDataTableView key={table} table={table} />
}

function ReferenceDataTableView({ table }: { table: string }) {
  const navigate = useNavigate()
  const [tableMeta, setTableMeta] = useState<ReferenceDataTableMeta | null>(null)
  usePageTitle(tableMeta?.label ?? 'Reference Data')

  const isTier1 = (tableMeta?.tier ?? 1) === 1
  const types = useMemo(() => tableMeta?.types ?? [], [tableMeta])
  const typeLabels = useMemo(() => new Map(types.map((type) => [type.value, type.label])), [types])
  const dashboardGroups = tableMeta?.dashboard_groups ?? null
  const dashboardGroupLabels = useMemo(() => new Map((dashboardGroups ?? []).map((group) => [group.value, group.label])), [dashboardGroups])
  const hasClassificationDefaults = tableMeta?.classification_defaults ?? false
  const hasAbbreviation = table === 'item-types'

  const [typeFilter, setTypeFilter] = useState('')
  const { items, meta, loading, error, page, setPage, perPage, setPerPage, setSearch, reload } = useServerTable(
    (params) =>
      referenceDataService.list(table, params).then((response) => {
        setTableMeta(response.meta.table)
        return response
      }),
    { waitForPerPage: true, filters: { type: typeFilter } },
  )

  const [searchDraft, setSearchDraft] = useState('')
  useEffect(() => {
    const timer = window.setTimeout(() => setSearch(searchDraft.trim()), 300)
    return () => window.clearTimeout(timer)
    // setSearch is recreated every render; only the typed value should restart the debounce.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchDraft])

  const [feedback, setFeedback] = useState<Feedback | null>(null)

  // Create/edit dialog. `editing` null + open = create.
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<ReferenceDataRow | null>(null)
  const [form, setForm] = useState<RowForm>(EMPTY_FORM)
  const [formErrors, setFormErrors] = useState<RowFormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function openCreate() {
    setEditing(null)
    setForm({ ...EMPTY_FORM, type: typeFilter })
    setFormErrors({})
    setFormError(null)
    setDialogOpen(true)
  }

  function openEdit(row: ReferenceDataRow) {
    setEditing(row)
    setForm({
      name: isTier2Row(row) ? '' : row.name,
      type: isTier2Row(row) ? row.type : '',
      value: isTier2Row(row) ? row.value : '',
      abbreviation: isTier2Row(row) ? '' : (row.abbreviation ?? ''),
      dashboard_group: isTier2Row(row) ? '' : (row.dashboard_group ?? ''),
      default_uacs: isTier2Row(row) ? '' : (row.default_uacs ?? ''),
      default_useful_life: isTier2Row(row) || row.default_useful_life == null ? '' : String(row.default_useful_life),
      description: row.description ?? '',
      sort_order: String(row.sort_order),
      is_active: row.is_active,
    })
    setFormErrors({})
    setFormError(null)
    setDialogOpen(true)
  }

  function patchForm(patch: Partial<RowForm>) {
    setForm((current) => ({ ...current, ...patch }))
  }

  async function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setFormErrors({})
    setFormError(null)

    const common = {
      description: form.description.trim() === '' ? null : form.description.trim(),
      sort_order: form.sort_order.trim() === '' ? 0 : Number(form.sort_order),
      is_active: form.is_active,
      ...(hasAbbreviation ? { abbreviation: form.abbreviation.trim().toUpperCase() } : {}),
      ...(dashboardGroups ? { dashboard_group: form.dashboard_group === '' ? null : form.dashboard_group } : {}),
      ...(hasClassificationDefaults
        ? {
            default_uacs: blankToNull(form.default_uacs),
            default_useful_life: form.default_useful_life.trim() === '' ? null : Number(form.default_useful_life),
          }
        : {}),
    }

    try {
      if (editing) {
        await referenceDataService.update(table, editing.id, isTier1 ? { ...common, name: form.name.trim() } : common)
        setFeedback({ type: 'success', message: `"${isTier1 ? form.name.trim() : rowLabel(editing)}" was updated.` })
      } else {
        await referenceDataService.create(table, isTier1 ? { ...common, name: form.name.trim() } : { ...common, type: form.type, value: form.value.trim() })
        setFeedback({ type: 'success', message: `"${isTier1 ? form.name.trim() : form.value.trim()}" was added.` })
      }
      setDialogOpen(false)
      await reload()
    } catch (saveError) {
      const fieldErrors = isValidationError(saveError) ? extractFieldErrors(saveError) : null
      setFormErrors(fieldErrors ?? {})
      setFormError(fieldErrors ? null : extractError(saveError, 'Unable to save. Please try again.'))
    } finally {
      setSaving(false)
    }
  }

  // Delete confirmation. A 409 means some record still uses the value.
  const [pendingDelete, setPendingDelete] = useState<ReferenceDataRow | null>(null)
  const [deleting, setDeleting] = useState(false)

  async function handleDelete() {
    if (!pendingDelete) return
    setDeleting(true)
    try {
      await referenceDataService.remove(table, pendingDelete.id)
      setFeedback({ type: 'success', message: `"${rowLabel(pendingDelete)}" was deleted.` })
      await reload()
    } catch (deleteError) {
      const inUse = isAxiosError(deleteError) && deleteError.response?.status === 409
      setFeedback({
        type: 'error',
        message: inUse
          ? extractError(deleteError, 'This value is still in use and cannot be deleted. Deactivate it instead.')
          : extractError(deleteError, 'Unable to delete this value. Please try again.'),
      })
    } finally {
      setDeleting(false)
      setPendingDelete(null)
    }
  }

  const columns = useMemo<GridColumn<ReferenceDataRow>[]>(() => {
    const identity: GridColumn<ReferenceDataRow>[] = isTier1
      ? [
          column.display({
            id: 'name',
            header: 'Name',
            enableHiding: false,
            cell: ({ row }) => <span className="font-medium text-gray-900">{rowLabel(row.original)}</span>,
          }),
        ]
      : [
          column.display({
            id: 'type',
            header: 'Type',
            cell: ({ row }) => {
              const type = isTier2Row(row.original) ? row.original.type : ''
              return typeLabels.get(type) ?? type
            },
          }),
          column.display({
            id: 'value',
            header: 'Value',
            enableHiding: false,
            cell: ({ row }) => <span className="font-medium text-gray-900">{rowLabel(row.original)}</span>,
          }),
        ]

    const abbreviationColumn: GridColumn<ReferenceDataRow>[] = hasAbbreviation
      ? [
          column.display({
            id: 'abbreviation',
            header: 'Abbreviation',
            meta: { className: 'whitespace-nowrap' },
            cell: ({ row }) => {
              const abbreviation = isTier2Row(row.original) ? null : row.original.abbreviation
              return abbreviation ? <span className="font-mono text-xs font-medium text-gray-900">{abbreviation}</span> : <span className="text-gray-400">—</span>
            },
          }),
        ]
      : []

    const dashboardGroupColumn: GridColumn<ReferenceDataRow>[] = dashboardGroups
      ? [
          column.display({
            id: 'dashboard_group',
            header: 'Dashboard group',
            cell: ({ row }) => {
              const group = isTier2Row(row.original) ? null : row.original.dashboard_group
              return group ? (dashboardGroupLabels.get(group) ?? group) : <span className="text-gray-400">Other equipment</span>
            },
          }),
        ]
      : []

    const classificationDefaultColumns: GridColumn<ReferenceDataRow>[] = hasClassificationDefaults
      ? [
          column.display({
            id: 'default_uacs',
            header: 'Default UACS',
            cell: ({ row }) => (isTier2Row(row.original) ? null : row.original.default_uacs) || <span className="text-gray-400">—</span>,
          }),
          column.display({
            id: 'default_useful_life',
            header: 'Default useful life',
            meta: { align: 'center', className: 'tabular-nums whitespace-nowrap' },
            cell: ({ row }) => {
              const years = isTier2Row(row.original) ? null : row.original.default_useful_life
              return years != null ? `${years} yr${years === 1 ? '' : 's'}` : <span className="text-gray-400">—</span>
            },
          }),
        ]
      : []

    return [
      ...identity,
      ...abbreviationColumn,
      ...dashboardGroupColumn,
      ...classificationDefaultColumns,
      column.accessor('description', {
        header: 'Description',
        enableSorting: false,
        cell: (info) => info.getValue() || <span className="text-gray-400">—</span>,
      }),
      column.accessor('sort_order', {
        header: 'Sort order',
        enableSorting: false,
        meta: { align: 'center', className: 'tabular-nums' },
      }),
      column.accessor('is_active', {
        header: 'Status',
        enableSorting: false,
        meta: { className: 'whitespace-nowrap' },
        cell: (info) => <Badge color={info.getValue() ? 'success' : 'gray'}>{info.getValue() ? 'Active' : 'Inactive'}</Badge>,
      }),
      column.display({
        id: 'actions',
        header: '',
        enableHiding: false,
        meta: { align: 'right' },
        cell: ({ row }) => (
          <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
            <Button variant="ghost" size="sm" onClick={() => openEdit(row.original)} aria-label={`Edit ${rowLabel(row.original)}`} title="Edit">
              <Pencil className="h-4 w-4 text-primary-600" />
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setPendingDelete(row.original)} aria-label={`Delete ${rowLabel(row.original)}`} title="Delete">
              <Trash2 className="h-4 w-4 text-error-600" />
            </Button>
          </div>
        ),
      }),
    ]
    // openEdit only closes over state setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isTier1, typeLabels, hasAbbreviation, dashboardGroups, dashboardGroupLabels, hasClassificationDefaults])

  const noun = isTier1 ? 'entry' : 'library value'

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <Button variant="ghost" size="sm" onClick={() => navigate('/reference-data')} aria-label="Back to reference data" className="mt-1">
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-bold text-gray-900">{tableMeta?.label ?? 'Reference Data'}</h1>
              {tableMeta && <Badge color={isTier1 ? 'primary' : 'gray'}>Tier {tableMeta.tier}</Badge>}
            </div>
            {tableMeta && (
              <p className="text-sm text-gray-600">
                {isTier1
                  ? 'Dedicated reference table. Name, description, sort order and status are editable; renames show on every linked record.'
                  : 'Grouped library table. Type and value are fixed once created; description, sort order and status are editable.'}
              </p>
            )}
          </div>
        </div>
        {tableMeta && (
          <div className="page-actions">
            <Button leftIcon={<Plus className="h-4 w-4" />} onClick={openCreate} size="sm">
              Add {noun}
            </Button>
          </div>
        )}
      </div>

      {feedback && (
        <Alert type={feedback.type} className="mb-4">
          {feedback.message}
        </Alert>
      )}

      <DataGrid
        id={`reference-data.${table}`}
        columns={columns}
        rows={items}
        rowKey={(row) => row.id}
        loading={loading}
        error={error}
        meta={meta}
        page={page}
        onPageChange={setPage}
        perPage={perPage}
        onPerPageChange={setPerPage}
        emptyMessage={searchDraft || typeFilter ? 'No rows match these filters.' : 'No rows yet.'}
        onRowClick={openEdit}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-3">
            <div className="w-full sm:w-64">
              <FilterSearch
                label={isTier1 ? 'Search by name or description' : 'Search by value or description'}
                value={searchDraft}
                onChange={setSearchDraft}
                onSubmit={() => setSearch(searchDraft.trim())}
              />
            </div>
            {!isTier1 && types.length > 0 && (
              <FilterSelect label="Filter by type" value={typeFilter} onChange={setTypeFilter} className="w-full sm:w-56">
                <option value="">All types</option>
                {types.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </FilterSelect>
            )}
            {meta && (
              <span>
                <span className="font-semibold text-gray-900">{meta.total.toLocaleString()}</span> row{meta.total === 1 ? '' : 's'}
              </span>
            )}
          </div>
        }
      />

      <Dialog open={dialogOpen} onOpenChange={(open) => !saving && setDialogOpen(open)}>
        <DialogContent>
          <DialogTitle>{editing ? `Edit ${noun}` : `Add ${noun}`}</DialogTitle>
          <DialogDescription>
            {isTier1
              ? editing
                ? 'Rename this entry or update its description, sort order and status. Every record that uses it shows the new name immediately.'
                : 'Add a new entry to this table. The name must be unique within the table.'
              : editing
                ? 'Only the description, sort order and status can change. The type and value stay fixed so existing records keep matching.'
                : 'Add a value under one of this library’s types. The value must be unique within its type and cannot be changed later.'}
          </DialogDescription>

          {formError && <Alert type="error">{formError}</Alert>}

          <form id="reference-data-form" onSubmit={handleSave} noValidate className="space-y-4">
            {isTier1 ? (
              <Input
                id="reference-name"
                label="Name"
                required
                autoFocus
                maxLength={255}
                value={form.name}
                onChange={(e) => patchForm({ name: e.target.value })}
                error={formErrors.name}
              />
            ) : editing ? (
              <dl className="grid grid-cols-2 gap-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm">
                <div>
                  <dt className="text-xs text-gray-500">Type</dt>
                  <dd className="font-medium text-gray-900">{typeLabels.get(form.type) ?? form.type}</dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-xs text-gray-500">Value</dt>
                  <dd className="font-medium break-words text-gray-900">{form.value}</dd>
                </div>
              </dl>
            ) : (
              <>
                <Select
                  id="reference-type"
                  label="Type"
                  required
                  placeholder="Select a type"
                  options={types.map((type) => ({ value: type.value, label: type.label }))}
                  value={form.type}
                  onChange={(e) => patchForm({ type: e.target.value })}
                  error={formErrors.type}
                />
                <Input
                  id="reference-value"
                  label="Value"
                  required
                  maxLength={255}
                  value={form.value}
                  onChange={(e) => patchForm({ value: e.target.value })}
                  error={formErrors.value}
                />
              </>
            )}

            {hasAbbreviation && (
              <Input
                id="reference-abbreviation"
                label="Abbreviation"
                required
                maxLength={3}
                autoCapitalize="characters"
                spellCheck={false}
                hint="Three letters or digits, unique per item type (e.g. LPT). Used in generated property numbers (AC-YYYY-IT-LPT-000001); a change only affects numbers generated afterward."
                className="font-mono uppercase"
                value={form.abbreviation}
                onChange={(e) => patchForm({ abbreviation: e.target.value.toUpperCase() })}
                error={formErrors.abbreviation}
              />
            )}

            {dashboardGroups && (
              <Select
                id="reference-dashboard-group"
                label="Dashboard group (optional)"
                placeholder="None (Other equipment)"
                hint="Where this item type is counted on the dashboard's ICT equipment overview."
                options={dashboardGroups.map((group) => ({ value: group.value, label: group.label }))}
                value={form.dashboard_group}
                onChange={(e) => patchForm({ dashboard_group: e.target.value })}
                error={formErrors.dashboard_group}
              />
            )}

            {hasClassificationDefaults && (
              <fieldset aria-describedby="reference-defaults-hint" className="space-y-3 rounded-lg border border-gray-200 p-3">
                <legend className="px-1 text-sm font-medium text-gray-700">Equipment defaults (optional)</legend>
                <p id="reference-defaults-hint" className="text-xs text-gray-500">
                  Filled into equipment saved under this classification when the field is left blank.
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Input
                    id="reference-default-uacs"
                    label="UACS (optional)"
                    maxLength={255}
                    value={form.default_uacs}
                    onChange={(e) => patchForm({ default_uacs: e.target.value })}
                    error={formErrors.default_uacs}
                  />
                  <Input
                    id="reference-default-useful-life"
                    label="Useful life in years (optional)"
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={100}
                    step={1}
                    value={form.default_useful_life}
                    onChange={(e) => patchForm({ default_useful_life: e.target.value })}
                    error={formErrors.default_useful_life}
                  />
                </div>
              </fieldset>
            )}

            <Textarea
              id="reference-description"
              label="Description (optional)"
              rows={3}
              value={form.description}
              onChange={(e) => patchForm({ description: e.target.value })}
              error={formErrors.description}
            />

            <Input
              id="reference-sort-order"
              label="Sort order"
              type="number"
              inputMode="numeric"
              min={0}
              hint="Lower numbers appear first in dropdowns."
              value={form.sort_order}
              onChange={(e) => patchForm({ sort_order: e.target.value })}
              error={formErrors.sort_order}
            />

            <Switch
              id="reference-is-active"
              checked={form.is_active}
              onChange={(checked) => patchForm({ is_active: checked })}
              label="Active"
              hint="Inactive values stay on existing records but are no longer offered in forms."
              error={formErrors.is_active}
            />
          </form>

          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" form="reference-data-form" isLoading={saving}>
              {editing ? 'Save changes' : 'Add'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && !deleting && setPendingDelete(null)}
        onConfirm={handleDelete}
        title={`Delete ${noun}?`}
        description={`This permanently deletes "${pendingDelete ? rowLabel(pendingDelete) : ''}". Values still used by any record can't be deleted; deactivate them instead.`}
        confirmText="Delete"
        loading={deleting}
      />
    </div>
  )
}
