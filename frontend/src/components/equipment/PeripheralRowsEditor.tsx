import { ChevronDown, Plus, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState, type ChangeEvent, type ReactNode } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { PERIPHERAL_ITEMS } from '@/lib/equipmentBundles'
import { extractRowErrors, type FieldErrors, type RowErrors } from '@/lib/errors'
import { cn } from '@/lib/utils'
import { toPeripheralPayload } from '@/services/equipmentService'
import type { EquipmentFormOptions, EquipmentPeripheralFormData, EquipmentPeripheralPayload } from '@/types/equipment'
import type { Option } from '@/types/lookup'

export const MAX_PERIPHERAL_ROWS = 10

const DETAIL_FIELDS: (keyof EquipmentPeripheralFormData)[] = ['acquisition_cost', 'specifications', 'remarks']

export interface PeripheralRow extends EquipmentPeripheralFormData {
  key: number
}

let lastRowKey = 0

export function newPeripheralRow(itemTypeId: number | null = null): PeripheralRow {
  lastRowKey += 1
  return {
    key: lastRowKey,
    item_type_id: itemTypeId,
    serial_number: '',
    brand_id: null,
    model: '',
    specifications: '',
    equipment_condition_id: null,
    acquisition_cost: '',
    remarks: '',
  }
}

export function peripheralItemTypes(options: EquipmentFormOptions | null): Option<number>[] {
  return (options?.item_type ?? []).filter((option) => PERIPHERAL_ITEMS.includes(option.label))
}

/** One row per named item (Monitor, Keyboard, Mouse by default), in that order. */
export function defaultPeripheralRows(options: EquipmentFormOptions | null, names: readonly string[] = PERIPHERAL_ITEMS): PeripheralRow[] {
  const itemTypes = peripheralItemTypes(options)
  return names.map((name) => newPeripheralRow(itemTypes.find((option) => option.label === name)?.value ?? null))
}

export function toPeripheralPayloads(rows: PeripheralRow[]): EquipmentPeripheralPayload[] {
  return rows.map(({ key: _key, ...row }) => toPeripheralPayload(row))
}

/** `peripherals.N.<field>` errors re-keyed by each row's `key`, so removing a row doesn't shift them onto its neighbours. */
export function peripheralRowErrors(error: unknown, rows: PeripheralRow[]): RowErrors {
  const byIndex = extractRowErrors(error, 'peripherals')
  const byKey: RowErrors['rows'] = {}
  for (const [index, fieldErrors] of Object.entries(byIndex.rows)) {
    const row = rows[Number(index)]
    if (row) byKey[row.key] = fieldErrors
  }
  return { general: byIndex.general, rows: byKey }
}

export function formatPropertySuffix(position: number): string {
  return String(position).padStart(3, '0')
}

interface PeripheralRowsEditorProps {
  /** Prefix for element ids; unique per page. */
  idPrefix: string
  rows: PeripheralRow[]
  onChange: (rows: PeripheralRow[]) => void
  options: EquipmentFormOptions | null
  /** Keyed by row `key` (see `peripheralRowErrors`). */
  errors: RowErrors
  desktopPropertyNo?: string | null
  /**
   * `sequence`: a desktop with no peripherals yet, so rows are previewed as <no>-001, -002, …
   * `continue`: an existing desktop, where the next free suffix isn't known client-side.
   * `generated`: a new desktop whose property number is generated when it is saved.
   */
  numbering: 'sequence' | 'continue' | 'generated'
  /** Shown instead of number previews when the desktop has no property number. */
  missingPropertyNoNote?: ReactNode
  /** Accessible name of the rows group. */
  label?: string
}

export function PeripheralRowsEditor({
  idPrefix,
  rows,
  onChange,
  options,
  errors,
  desktopPropertyNo,
  numbering,
  missingPropertyNoNote,
  label = 'Peripherals',
}: PeripheralRowsEditorProps) {
  const itemTypes = peripheralItemTypes(options)
  const propertyNo = numbering === 'generated' ? null : desktopPropertyNo?.trim() || null
  const addButtonRef = useRef<HTMLButtonElement>(null)
  const [focusTarget, setFocusTarget] = useState<number | 'add' | null>(null)

  useEffect(() => {
    if (focusTarget === null) return
    if (focusTarget === 'add') addButtonRef.current?.focus()
    else document.getElementById(`${idPrefix}-${focusTarget}-item`)?.focus()
    setFocusTarget(null)
  }, [focusTarget, idPrefix])

  function update(key: number, patch: Partial<EquipmentPeripheralFormData>) {
    onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  }

  function append(added: PeripheralRow[]) {
    onChange([...rows, ...added])
    setFocusTarget(added[0].key)
  }

  function remove(key: number) {
    onChange(rows.filter((row) => row.key !== key))
    setFocusTarget('add')
  }

  const atLimit = rows.length >= MAX_PERIPHERAL_ROWS
  const setDoesNotFit = rows.length + PERIPHERAL_ITEMS.length > MAX_PERIPHERAL_ROWS
  const generalErrorId = `${idPrefix}-error`

  return (
    <div role="group" aria-label={label} aria-describedby={errors.general ? generalErrorId : undefined} className="@container space-y-3">
      {numbering === 'generated' ? (
        <p className="text-sm text-gray-600">
          Each peripheral is numbered after the desktop&apos;s property number once it is generated on save: its number followed by -001, -002, and so on.
        </p>
      ) : !propertyNo ? (
        rows.length > 0 ? (
          <Alert type="warning">{missingPropertyNoNote}</Alert>
        ) : (
          <p className="text-sm text-gray-600">{missingPropertyNoNote}</p>
        )
      ) : (
        numbering === 'continue' && (
          <p className="text-sm text-gray-600">
            New peripherals are numbered automatically after <span className="font-mono text-xs font-medium text-gray-900">{propertyNo}</span>, continuing from
            this desktop&apos;s existing peripheral numbers.
          </p>
        )
      )}
      {errors.general && (
        <p id={generalErrorId} className="text-sm text-error-600">
          {errors.general}
        </p>
      )}

      {rows.length > 0 && (
        <div
          aria-hidden="true"
          className="hidden gap-3 border-x border-transparent px-3 text-xs font-semibold uppercase tracking-wide text-gray-500 @3xl:grid @3xl:grid-cols-[minmax(0,9rem)_repeat(4,minmax(0,1fr))]"
        >
          <span>Item</span>
          <span>Brand</span>
          <span>Model</span>
          <span>Serial no.</span>
          <span>Condition</span>
        </div>
      )}

      <ol className="space-y-3">
        {rows.map((row, index) => (
          <PeripheralRowFields
            key={row.key}
            idPrefix={`${idPrefix}-${row.key}`}
            position={index + 1}
            row={row}
            itemTypes={itemTypes}
            options={options}
            errors={errors.rows[row.key] ?? {}}
            preview={
              numbering === 'generated'
                ? `[desktop no.]-${formatPropertySuffix(index + 1)}`
                : propertyNo && numbering === 'sequence'
                  ? `${propertyNo}-${formatPropertySuffix(index + 1)}`
                  : null
            }
            onChange={(patch) => update(row.key, patch)}
            onRemove={() => remove(row.key)}
          />
        ))}
      </ol>

      {rows.length === 0 && <p className="text-sm text-gray-500">No peripherals will be added.</p>}

      <div className="flex flex-wrap items-center gap-3">
        <Button variant="outline" size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => append(defaultPeripheralRows(options))} disabled={!options || setDoesNotFit}>
          Add monitor, keyboard and mouse
        </Button>
        <Button ref={addButtonRef} variant="outline" size="sm" leftIcon={<Plus className="h-4 w-4" />} onClick={() => append([newPeripheralRow()])} disabled={atLimit}>
          Add another
        </Button>
        <span className="text-xs text-gray-500" aria-live="polite">
          {rows.length} of {MAX_PERIPHERAL_ROWS} rows{atLimit ? ' — the most you can add at once' : ''}
        </span>
      </div>
    </div>
  )
}

interface PeripheralRowFieldsProps {
  idPrefix: string
  position: number
  row: PeripheralRow
  itemTypes: Option<number>[]
  options: EquipmentFormOptions | null
  errors: FieldErrors
  preview: string | null
  onChange: (patch: Partial<EquipmentPeripheralFormData>) => void
  onRemove: () => void
}

function PeripheralRowFields({ idPrefix, position, row, itemTypes, options, errors, preview, onChange, onRemove }: PeripheralRowFieldsProps) {
  const [detailsOpen, setDetailsOpen] = useState(() => DETAIL_FIELDS.some((field) => row[field] !== ''))
  const detailsHaveError = DETAIL_FIELDS.some((field) => errors[field])
  const rowLabel = `Peripheral ${position}`
  const headingId = `${idPrefix}-heading`
  const detailsId = `${idPrefix}-details`
  const otherErrors = Object.entries(errors).filter(([field]) => !(field in row))

  useEffect(() => {
    if (detailsHaveError) setDetailsOpen(true)
  }, [detailsHaveError])

  function idValue(event: ChangeEvent<HTMLSelectElement>): number | null {
    return event.target.value === '' ? null : Number(event.target.value)
  }

  return (
    <li className="rounded-lg border border-gray-200 bg-white p-3">
      <div role="group" aria-labelledby={headingId}>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <p id={headingId} className="text-sm font-medium text-gray-900">
            {rowLabel}
            {preview && (
              <span className="ml-2 text-xs font-normal text-gray-600">
                planned as <span className="font-mono font-medium text-gray-900">{preview}</span>
              </span>
            )}
          </p>
          <Button
            variant="ghost"
            size="sm"
            leftIcon={<Trash2 className="h-4 w-4 text-error-600" />}
            aria-label={`Remove ${rowLabel.toLowerCase()}`}
            onClick={onRemove}
          >
            Remove
          </Button>
        </div>

        <div className="grid gap-3 @md:grid-cols-2 @3xl:grid-cols-[minmax(0,9rem)_repeat(4,minmax(0,1fr))]">
          <RowField id={`${idPrefix}-item`} label="Item" rowLabel={rowLabel}>
            <Select
              id={`${idPrefix}-item`}
              aria-required="true"
              placeholder="Select an item"
              options={itemTypes}
              disabled={!options}
              value={row.item_type_id ?? ''}
              onChange={(e) => onChange({ item_type_id: idValue(e) })}
              error={errors.item_type_id}
            />
          </RowField>
          <RowField id={`${idPrefix}-brand`} label="Brand" rowLabel={rowLabel}>
            <Select
              id={`${idPrefix}-brand`}
              placeholder="Same as desktop"
              options={options?.brand ?? []}
              disabled={!options}
              value={row.brand_id ?? ''}
              onChange={(e) => onChange({ brand_id: idValue(e) })}
              error={errors.brand_id}
            />
          </RowField>
          <RowField id={`${idPrefix}-model`} label="Model" rowLabel={rowLabel}>
            <Input id={`${idPrefix}-model`} maxLength={255} value={row.model} onChange={(e) => onChange({ model: e.target.value })} error={errors.model} />
          </RowField>
          <RowField id={`${idPrefix}-serial`} label="Serial no." rowLabel={rowLabel}>
            <Input
              id={`${idPrefix}-serial`}
              maxLength={255}
              value={row.serial_number}
              onChange={(e) => onChange({ serial_number: e.target.value })}
              error={errors.serial_number}
            />
          </RowField>
          <RowField id={`${idPrefix}-condition`} label="Condition" rowLabel={rowLabel}>
            <Select
              id={`${idPrefix}-condition`}
              placeholder="Same as desktop"
              options={options?.condition ?? []}
              disabled={!options}
              value={row.equipment_condition_id ?? ''}
              onChange={(e) => onChange({ equipment_condition_id: idValue(e) })}
              error={errors.equipment_condition_id}
            />
          </RowField>
        </div>

        {otherErrors.map(([field, message]) => (
          <p key={field} className="mt-2 text-xs text-error-600">
            {message}
          </p>
        ))}

        <button
          type="button"
          aria-expanded={detailsOpen}
          aria-controls={detailsId}
          onClick={() => setDetailsOpen((open) => !open)}
          className="mt-3 inline-flex items-center gap-1 rounded-sm text-sm font-medium text-primary-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        >
          <ChevronDown className={cn('h-4 w-4 transition-transform', detailsOpen && 'rotate-180')} aria-hidden="true" />
          More details<span className="sr-only"> for {rowLabel.toLowerCase()}</span>
          <span className="text-xs font-normal text-gray-500">(cost, specifications, remarks)</span>
        </button>

        <div id={detailsId} hidden={!detailsOpen} className="mt-3 grid gap-3 @xl:grid-cols-2">
          <RowField id={`${idPrefix}-cost`} label="Acquisition cost" rowLabel={rowLabel} alwaysShowLabel>
            <Input
              id={`${idPrefix}-cost`}
              type="number"
              step="0.01"
              min="0"
              inputMode="decimal"
              value={row.acquisition_cost}
              onChange={(e) => onChange({ acquisition_cost: e.target.value })}
              error={errors.acquisition_cost}
            />
          </RowField>
          <div className="hidden @xl:block" />
          <RowField id={`${idPrefix}-specifications`} label="Specifications" rowLabel={rowLabel} alwaysShowLabel>
            <Textarea
              id={`${idPrefix}-specifications`}
              rows={2}
              value={row.specifications}
              onChange={(e) => onChange({ specifications: e.target.value })}
              error={errors.specifications}
            />
          </RowField>
          <RowField id={`${idPrefix}-remarks`} label="Remarks" rowLabel={rowLabel} alwaysShowLabel>
            <Textarea id={`${idPrefix}-remarks`} rows={2} value={row.remarks} onChange={(e) => onChange({ remarks: e.target.value })} error={errors.remarks} />
          </RowField>
        </div>
      </div>
    </li>
  )
}

function RowField({
  id,
  label,
  rowLabel,
  alwaysShowLabel = false,
  children,
}: {
  id: string
  label: string
  rowLabel: string
  alwaysShowLabel?: boolean
  children: ReactNode
}) {
  return (
    <div className="min-w-0">
      <label htmlFor={id} className={cn('mb-1 block text-xs font-medium text-gray-700', !alwaysShowLabel && '@3xl:sr-only')}>
        <span className="sr-only">{rowLabel} </span>
        {label}
      </label>
      {children}
    </div>
  )
}
