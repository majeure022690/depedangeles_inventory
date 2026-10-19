import type { InertiaForm } from '@inertiajs/vue3';

/**
 * Binds a single JSON array field on an Inertia `useForm()` object to a
 * multi-select checkbox group: each checkbox toggles membership of its
 * `value` in that array field (add on check, remove on uncheck).
 *
 * This is the first codebase-wide reusable pattern for "checkbox group
 * backed by a JSON array field" — introduced for the Stakeholder Profile
 * and Internet Connectivity Survey forms, which together have 11 such
 * fields (nearby_institutions, access_paths, transportation_options,
 * community_engagement, available_isps, mobile_signal_types,
 * subscribed_isps, coverage_areas, electricity_sources). Extracted here
 * per this project's "same pattern used 2+ times" DRY rule rather than
 * re-deriving the toggle logic in every form partial.
 *
 * `TValue` defaults to `string` (every original caller's array elements are
 * value strings from a Tier 2 library). Personnel's `fund_source` (ADR
 * Question 4 — a JSON array of `personnel_libraries` row ids, not value
 * strings) instantiates this with `TValue = number` instead — the toggle
 * logic itself is identical either way, only the element type differs.
 *
 * Mutates the given field on `form` directly, same as every other field
 * binding in this codebase's forms — `useForm()`'s return value is shared
 * reactive state, not a one-way prop.
 */
export function useCheckboxGroupField<
    TForm extends Record<string, unknown>,
    TValue extends string | number = string,
>(
    form: InertiaForm<TForm>,
    field: keyof TForm,
) {
    function isChecked(value: TValue): boolean {
        return ((form[field] as unknown as TValue[] | null) ?? []).includes(value);
    }

    function toggle(value: TValue, checked: boolean): void {
        const current = (form[field] as unknown as TValue[] | null) ?? [];
        const next = checked
            ? (current.includes(value) ? current : [...current, value])
            : current.filter((item) => item !== value);

        // Generic `keyof TForm` assignment can't be proven sound against
        // InertiaForm<TForm>'s intersected method signatures at this
        // arity — safe in practice because this composable is only ever
        // used on JSON array fields (see file-level doc-comment).
        (form as unknown as Record<keyof TForm, TValue[]>)[field] = next;
    }

    return { isChecked, toggle };
}
