import { isAxiosError } from 'axios'

import type { ApiValidationError } from '@/types/api'

export function extractError(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (isAxiosError<ApiValidationError>(error) && error.response?.status === 422) {
    return Object.values(error.response.data.errors).flat()[0] ?? fallback
  }
  if (isAxiosError(error) && typeof error.response?.data?.message === 'string') {
    return error.response.data.message
  }
  return fallback
}
