/** One office (school or division-level office/unit), as shipped by OfficeResource. */
export interface Office {
  id: number
  office_name: string
  office_type: string | null
  school_id: number | null
  address: string | null
  region: string | null
  district: string | null
  division: string | null
  contact_number: string | null
  email: string | null
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}

/** Body for POST /api/offices and PUT /api/offices/{id}. */
export interface OfficePayload {
  office_name: string
  office_type: string | null
  school_id: number | null
  address: string | null
  region: string | null
  district: string | null
  division: string | null
  contact_number: string | null
  email: string | null
  is_active: boolean
}

/** Form state: every text field is a string while editing; `toOfficePayload` normalizes blanks to null. */
export type OfficeFormData = { [K in Exclude<keyof OfficePayload, 'is_active'>]: string } & { is_active: boolean }
