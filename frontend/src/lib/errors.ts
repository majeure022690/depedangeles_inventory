import { isAxiosError, type AxiosError } from 'axios'

import type { ApiValidationError } from '@/types/api'

/** Per-field messages for form state; a missing key means the field has no error. */
export type FieldErrors = Partial<Record<string, string>>

export function extractError(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
    return Object.values(error.response.data.errors).flat()[0] ?? fallback
  }
  if (isAxiosError(error) && typeof error.response?.data?.message === 'string') {
    return error.response.data.message
  }
  return fallback
}

export const OUT_OF_REACH_MESSAGE = 'Record not found or outside your access.'

export function isNotFound(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 404
}

/** For detail-page loads: a 404 (missing, or outside the user's data scope) gets one neutral message. */
export function extractLoadError(error: unknown, fallback: string): string {
  return isNotFound(error) ? OUT_OF_REACH_MESSAGE : extractError(error, fallback)
}

export const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many attempts. Please wait a minute and try again.'

export function isTooManyRequests(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 429
}

/** True for a Laravel 422 validation response. */
export function isValidationError(error: unknown): error is AxiosError<ApiValidationError> {
  return isAxiosError(error) && error.response?.status === 422
}

/**
 * First 422 message per field, keyed by request field name. Array-element keys
 * (`fund_source.0`, `role_ids.2`) fold into their parent field (`fund_source`),
 * keeping the first message seen. Empty for anything that isn't a 422.
 */
export function extractFieldErrors(error: unknown): Record<string, string> {
  const fieldErrors: Record<string, string> = {}
  if (!isValidationError(error)) return fieldErrors
  for (const [key, messages] of Object.entries(error.response?.data?.errors ?? {})) {
    const field = key.split('.')[0]
    if (!(field in fieldErrors) && messages[0]) fieldErrors[field] = messages[0]
  }
  return fieldErrors
}

/** 422 messages for an array of objects: `general` is the array key's own, `rows[n]` holds `<field>.n.<column>` keyed by column. */
export interface RowErrors {
  general: string | null
  rows: Partial<Record<number, FieldErrors>>
}

export const NO_ROW_ERRORS: RowErrors = { general: null, rows: {} }

export function extractRowErrors(error: unknown, field: string): RowErrors {
  const result: RowErrors = { general: null, rows: {} }
  if (!isValidationError(error)) return result
  for (const [key, messages] of Object.entries(error.response?.data?.errors ?? {})) {
    const message = messages[0]
    if (!message) continue
    if (key === field) {
      result.general ??= message
      continue
    }
    const [root, index, column = ''] = key.split('.')
    if (root !== field || index === undefined || !/^\d+$/.test(index)) continue
    const row = (result.rows[Number(index)] ??= {})
    row[column] ??= message
  }
  return result
}

export function rowErrorCount(errors: RowErrors): number {
  return (errors.general ? 1 : 0) + Object.values(errors.rows).reduce((total, row) => total + Object.keys(row ?? {}).length, 0)
}
