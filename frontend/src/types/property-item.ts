import type { Option } from '@/types/lookup'

export type PropertyCategory =
  | 'it_equipment'
  | 'office_equipment'
  | 'furniture_fixtures'
  | 'school_building'
  | 'other_structure'
  | 'tle_equipment'
  | 'science_math_equipment'
  | 'sports_music_equipment'
  | 'books'

/** `PropertyItemResource`. */
export interface PropertyItem {
  id: number
  office_id: number
  office?: { id: number; office_name: string } | null
  category: PropertyCategory
  category_label: string
  article: string
  description: string | null
  property_no: string | null
  unit_of_measure: string | null
  date_acquired: string | null
  unit_value: number | null
  quantity: number
  on_hand_count: number
  total_value: number | null
  grade_level: string | null
  source: string | null
  condition: string | null
  with_are: boolean
  accountable_officer_id: number | null
  accountable_officer?: string | null
  end_user_id: number | null
  end_user?: string | null
  location: string | null
  remarks: string | null
  created_at: string | null
  updated_at: string | null
}

/** `GET /api/property-items/options`; `personnel` only comes back when `office_id` is sent. */
export interface PropertyItemOptions {
  category: (Option<PropertyCategory> & { articles: string[] })[]
  source: Option<string>[]
  condition: Option<string>[]
  grade_level: Option<string>[]
  office: Option<number>[]
  unit_of_measure: Option<string>[]
  personnel?: Option<number>[]
}

/** Form state: every input is a string, converted on submit. */
export interface PropertyItemFormData {
  office_id: string
  category: PropertyCategory | ''
  article: string
  description: string
  property_no: string
  unit_of_measure: string
  date_acquired: string
  unit_value: string
  quantity: string
  on_hand_count: string
  grade_level: string
  source: string
  condition: string
  with_are: boolean
  accountable_officer_id: string
  end_user_id: string
  location: string
  remarks: string
}

export type PropertyItemFilters = {
  search: string
  category: PropertyCategory | ''
  office_id: string
}
