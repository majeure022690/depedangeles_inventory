/**
 * One dropdown/checkbox option, as shipped by every `GET /api/<resource>/options`
 * endpoint. Tier 1 reference tables send numeric ids as `value`; Tier 2 library
 * tables send the stored string. `parent_id` is only present on cascading
 * options (PSGC municipality to province, barangay to municipality).
 */
export interface Option<TValue extends string | number = string | number> {
  value: TValue
  label: string
  parent_id?: number
}

/** A keyed bag of option lists, e.g. `{ item_type: Option<number>[], unit_of_measure: Option<string>[] }`. */
export type OptionSet = Record<string, Option[]>
