import { Link2, Link2Off, Plus, Search, Unplug } from "lucide-react";
import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";

import {
  PeripheralRowsEditor,
  defaultPeripheralRows,
  peripheralRowErrors,
  toPeripheralPayloads,
  type PeripheralRow,
} from "@/components/equipment/PeripheralRowsEditor";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardContent, CardHeader } from "@/components/ui/Card";
import { ConfirmDialog } from "@/components/ui/ConfirmDialog";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogTitle,
} from "@/components/ui/Dialog";
import { Input } from "@/components/ui/Input";
import { Spinner } from "@/components/ui/Spinner";
import { PERIPHERAL_ITEMS } from "@/lib/equipmentBundles";
import {
  extractError,
  extractFieldErrors,
  NO_ROW_ERRORS,
  type RowErrors,
} from "@/lib/errors";
import { equipmentService } from "@/services/equipmentService";
import type {
  Equipment,
  EquipmentFormOptions,
  EquipmentPeripheral,
} from "@/types/equipment";

function describe(item: { property_no: string | null }): string {
  return item.property_no ?? "pending property no.";
}

interface BundledPeripheralsProps {
  equipment: Equipment;
  options: EquipmentFormOptions | null;
  canLink: boolean;
  canAdd: boolean;
  canUnlink: boolean;
  onChanged: (message: string) => Promise<void>;
}

export function BundledPeripherals({
  equipment,
  options,
  canLink,
  canAdd,
  canUnlink,
  onChanged,
}: BundledPeripheralsProps) {
  const children = equipment.children ?? [];
  const [addOpen, setAddOpen] = useState(false);
  const [linkOpen, setLinkOpen] = useState(false);
  const [pendingUnlink, setPendingUnlink] =
    useState<EquipmentPeripheral | null>(null);
  const [unlinking, setUnlinking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirmUnlink() {
    if (!pendingUnlink) return;
    setUnlinking(true);
    setError(null);
    try {
      await equipmentService.unlinkPeripheral(equipment.id, pendingUnlink.id);
      setPendingUnlink(null);
      await onChanged(
        `${pendingUnlink.item ?? "Peripheral"} ${describe(pendingUnlink)} was unlinked.`,
      );
    } catch (e) {
      setError(
        extractError(e, "Unable to unlink this peripheral. Please try again."),
      );
      setPendingUnlink(null);
    } finally {
      setUnlinking(false);
    }
  }

  const canAddNew = canAdd && equipment.property_no !== null;

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="flex items-center gap-2 text-base font-semibold text-gray-900">
            <Unplug className="h-4 w-4 text-gray-400" aria-hidden="true" />
            Bundled peripherals
          </h2>
          <p className="mt-1 text-sm text-gray-600">
            Monitors, keyboards and mice that belong to this desktop. They are
            issued, transferred and returned together with it.
          </p>
        </div>
        {canLink && (
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              leftIcon={<Link2 className="h-4 w-4" />}
              onClick={() => setLinkOpen(true)}
            >
              Link existing
            </Button>
            {canAdd && (
              <Button
                size="sm"
                leftIcon={<Plus className="h-4 w-4" />}
                onClick={() => setAddOpen(true)}
                disabled={!canAddNew || !options}
              >
                Add new peripherals
              </Button>
            )}
          </div>
        )}
      </CardHeader>

      <div role="alert" className="empty:hidden">
        {error && (
          <Alert type="error" className="m-4">
            {error}
          </Alert>
        )}
      </div>
      {canAdd && equipment.property_no === null && (
        <p className="px-5 pt-4 text-sm text-gray-600">
          Give this desktop a property number before adding new peripherals;
          their numbers are based on it.
        </p>
      )}

      {children.length === 0 ? (
        <CardContent>
          <p className="text-sm text-gray-500">
            No peripherals are linked to this desktop yet.
          </p>
        </CardContent>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">
              Peripherals bundled with property no. {describe(equipment)}
            </caption>
            <thead className="border-b border-gray-200 bg-gray-50">
              <tr>
                {[
                  "Property No.",
                  "Item",
                  "Serial No.",
                  "Brand",
                  "Model",
                  "Condition",
                ].map((heading) => (
                  <th
                    key={heading}
                    scope="col"
                    className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500"
                  >
                    {heading}
                  </th>
                ))}
                {canUnlink && (
                  <th scope="col" className="px-4 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {children.map((child) => {
                const outOfReach = child.item === null;
                return (
                  <tr key={child.id}>
                    <td className="whitespace-nowrap px-4 py-3">
                      {outOfReach ? (
                        <span className="font-mono text-xs font-medium text-gray-700">
                          {child.property_no ?? "Pending"}
                        </span>
                      ) : (
                        <Link
                          to={`/equipment/${child.id}/edit`}
                          className="font-mono text-xs font-medium text-primary-700 hover:underline focus-visible:underline"
                        >
                          {child.property_no ?? "Pending"}
                        </Link>
                      )}
                    </td>
                    <td className="px-4 py-3 text-gray-900">
                      {outOfReach ? (
                        <span className="text-gray-500">
                          Outside your access
                        </span>
                      ) : (
                        child.item
                      )}
                    </td>
                    <td className="px-4 py-3 text-gray-700">
                      {child.serial_number ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-gray-700">
                      {child.brand_manufacturer ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-gray-700">
                      {child.model ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge color="gray">
                        {child.equipment_condition ?? "—"}
                      </Badge>
                    </td>
                    {canUnlink && (
                      <td className="px-4 py-3 text-right">
                        {!outOfReach && (
                          <Button
                            variant="ghost"
                            size="sm"
                            leftIcon={
                              <Link2Off className="h-4 w-4 text-error-600" />
                            }
                            aria-label={`Unlink ${child.item ?? "peripheral"} ${describe(child)}`}
                            onClick={() => setPendingUnlink(child)}
                          >
                            Unlink
                          </Button>
                        )}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {canAdd && (
        <AddPeripheralsDialog
          open={addOpen}
          onOpenChange={setAddOpen}
          equipment={equipment}
          options={options}
          onAdded={async (message) => {
            setAddOpen(false);
            await onChanged(message);
          }}
        />
      )}
      {canLink && (
        <LinkPeripheralDialog
          open={linkOpen}
          onOpenChange={setLinkOpen}
          equipment={equipment}
          options={options}
          onLinked={async (message) => {
            setLinkOpen(false);
            await onChanged(message);
          }}
        />
      )}

      <ConfirmDialog
        open={pendingUnlink !== null}
        onOpenChange={(open) => !open && !unlinking && setPendingUnlink(null)}
        onConfirm={confirmUnlink}
        loading={unlinking}
        title="Unlink peripheral?"
        description={`${pendingUnlink?.item ?? "This peripheral"} ${pendingUnlink ? describe(pendingUnlink) : ""} will become a standalone equipment record. Its property number and accountability stay as they are.`}
        confirmText="Unlink"
      />
    </Card>
  );
}

function AddPeripheralsDialog({
  open,
  onOpenChange,
  equipment,
  options,
  onAdded,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  equipment: Equipment;
  options: EquipmentFormOptions | null;
  onAdded: (message: string) => Promise<void>;
}) {
  const [rows, setRows] = useState<PeripheralRow[]>([]);
  const [errors, setErrors] = useState<RowErrors>(NO_ROW_ERRORS);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const hasChildren = (equipment.children?.length ?? 0) > 0;

  useEffect(() => {
    if (!open) return;
    setRows(
      defaultPeripheralRows(
        options,
        hasChildren ? [PERIPHERAL_ITEMS[0]] : PERIPHERAL_ITEMS,
      ),
    );
    setErrors(NO_ROW_ERRORS);
    setFormError(null);
  }, [open, options, hasChildren]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (rows.length === 0) {
      setFormError("Add at least one peripheral row.");
      return;
    }
    setSaving(true);
    setErrors(NO_ROW_ERRORS);
    setFormError(null);
    try {
      const response = await equipmentService.addPeripherals(
        equipment.id,
        toPeripheralPayloads(rows),
      );
      const numbers = response.data
        .map((peripheral) => peripheral.property_no)
        .filter((number): number is string => number !== null);
      await onAdded(
        numbers.length > 0
          ? `${response.message} New numbers: ${numbers.join(", ")}.`
          : response.message,
      );
    } catch (e) {
      const rowErrors = peripheralRowErrors(e, rows);
      const otherError = Object.entries(extractFieldErrors(e)).find(
        ([field]) => field !== "peripherals",
      );
      setErrors(rowErrors);
      setFormError(
        Object.keys(rowErrors.rows).length > 0
          ? "Some rows need attention. Check the highlighted fields."
          : otherError
            ? otherError[1]
            : rowErrors.general
              ? null
              : extractError(
                  e,
                  "Unable to add these peripherals. Please try again.",
                ),
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent size="xl">
        <DialogTitle>Add new peripherals</DialogTitle>
        <DialogDescription>
          Creates new Monitor, Keyboard or Mouse records bundled with this
          desktop. Their property numbers are generated from the desktop&apos;s,
          and they share the desktop&apos;s accountability. A blank brand or
          condition takes the desktop&apos;s.
        </DialogDescription>

        <div role="alert" className="empty:hidden">
          {formError && <Alert type="error">{formError}</Alert>}
        </div>

        <form id="add-peripherals-form" onSubmit={submit} noValidate>
          <PeripheralRowsEditor
            idPrefix="add-peripheral"
            label="New peripherals"
            rows={rows}
            onChange={setRows}
            options={options}
            errors={errors}
            desktopPropertyNo={equipment.property_no}
            numbering={hasChildren ? "continue" : "sequence"}
            missingPropertyNoNote="Give this desktop a property number before adding new peripherals; their numbers are based on it."
          />
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={saving}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="add-peripherals-form"
            isLoading={saving}
            disabled={rows.length === 0}
          >
            {rows.length === 1 ? "Add peripheral" : `Add ${rows.length} peripherals`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function LinkPeripheralDialog({
  open,
  onOpenChange,
  equipment,
  options,
  onLinked,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  equipment: Equipment;
  options: EquipmentFormOptions | null;
  onLinked: (message: string) => Promise<void>;
}) {
  const peripheralTypeIds = useMemo(
    () =>
      (options?.item_type ?? [])
        .filter((option) => PERIPHERAL_ITEMS.includes(option.label))
        .map((option) => option.value)
        .join(","),
    [options],
  );
  const [searchDraft, setSearchDraft] = useState("");
  const [search, setSearch] = useState("");
  const [results, setResults] = useState<Equipment[]>([]);
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [linkError, setLinkError] = useState<string | null>(null);
  const [linking, setLinking] = useState(false);

  useEffect(() => {
    if (!open) return;
    setSearchDraft("");
    setSearch("");
    setSelectedId(null);
    setLinkError(null);
  }, [open]);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearch(searchDraft.trim()), 300);
    return () => window.clearTimeout(timer);
  }, [searchDraft]);

  useEffect(() => {
    if (!open || peripheralTypeIds === "") return;
    let cancelled = false;
    setSearching(true);
    setSearchError(null);
    const params: Record<string, string | number> = {
      item_type: peripheralTypeIds,
      per_page: 50,
    };
    if (search) params.search = search;
    equipmentService
      .list(params)
      .then((page) => {
        if (cancelled) return;
        setResults(
          page.data.filter(
            (row) =>
              row.id !== equipment.id && row.parent_equipment_id === null,
          ),
        );
      })
      .catch(
        (e: unknown) =>
          !cancelled &&
          setSearchError(
            extractError(e, "Unable to search equipment. Please try again."),
          ),
      )
      .finally(() => !cancelled && setSearching(false));
    return () => {
      cancelled = true;
    };
  }, [open, search, equipment.id, peripheralTypeIds]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (selectedId === null) {
      setLinkError("Choose the monitor, keyboard or mouse to link.");
      return;
    }
    setLinking(true);
    setLinkError(null);
    try {
      const response = await equipmentService.linkPeripheral(
        equipment.id,
        selectedId,
      );
      await onLinked(
        `${response.data.item ?? "Peripheral"} ${describe(response.data)} was linked to this desktop.`,
      );
    } catch (e) {
      setLinkError(
        extractError(e, "Unable to link this peripheral. Please try again."),
      );
    } finally {
      setLinking(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !linking && onOpenChange(next)}>
      <DialogContent size="lg">
        <DialogTitle>Link existing peripheral</DialogTitle>
        <DialogDescription>
          Attach a Monitor, Keyboard or Mouse that is already on record and not
          part of another desktop. Its property number stays the same.
        </DialogDescription>

        <form
          id="link-peripheral-form"
          onSubmit={submit}
          noValidate
          className="space-y-3"
        >
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
              aria-hidden="true"
            />
            <Input
              id="peripheral-search"
              type="search"
              aria-label="Search by property no., brand or serial no."
              placeholder="Search by property no., brand or serial no."
              value={searchDraft}
              onChange={(e) => setSearchDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  setSearch(searchDraft.trim());
                }
              }}
              className="pl-9"
            />
          </div>

          {searchError && <Alert type="error">{searchError}</Alert>}

          <fieldset
            aria-describedby={linkError ? "link-peripheral-error" : undefined}
            aria-busy={searching}
          >
            <legend className="sr-only">Peripheral to link</legend>
            <div className="max-h-72 overflow-y-auto rounded-lg border border-gray-200">
              {searching && results.length === 0 ? (
                <div className="flex items-center justify-center gap-2 p-6 text-sm text-gray-500">
                  <Spinner className="h-4 w-4" /> Searching…
                </div>
              ) : results.length === 0 ? (
                <p className="p-6 text-center text-sm text-gray-500">
                  {search
                    ? "No unlinked monitors, keyboards or mice match this search."
                    : "No unlinked monitors, keyboards or mice found."}
                </p>
              ) : (
                <ul className="divide-y divide-gray-100">
                  {results.map((row) => (
                    <li key={row.id}>
                      <label className="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-gray-50 has-[:checked]:bg-primary-50">
                        <input
                          type="radio"
                          name="equipment_id"
                          value={row.id}
                          checked={selectedId === row.id}
                          onChange={() => setSelectedId(row.id)}
                          className="mt-1 h-4 w-4 accent-primary-600"
                        />
                        <span className="min-w-0 text-sm">
                          <span className="block font-medium text-gray-900">
                            <span className="font-mono text-xs">
                              {row.property_no ?? "Pending property no."}
                            </span>{" "}
                            · {row.item}
                          </span>
                          <span className="block text-xs text-gray-500">
                            {[
                              row.brand_manufacturer,
                              row.model,
                              row.serial_number && `S/N ${row.serial_number}`,
                            ]
                              .filter(Boolean)
                              .join(" — ") || "—"}
                          </span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </fieldset>
          {linkError && (
            <p
              id="link-peripheral-error"
              role="alert"
              className="text-sm text-error-600"
            >
              {linkError}
            </p>
          )}
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={linking}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="link-peripheral-form"
            isLoading={linking}
            disabled={selectedId === null}
          >
            Link peripheral
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
