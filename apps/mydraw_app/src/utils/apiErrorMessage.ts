import axios from 'axios'
import { ApiEnvelopeError } from '@/services/apiContract'

/**
 * Mensagem amigável em inglês para a UI a partir de falhas de API.
 */
export function getApiErrorMessage(err: unknown): string {
  if (err instanceof ApiEnvelopeError) {
    const payload = err.payload
    if (
      payload &&
      typeof payload === 'object' &&
      !Array.isArray(payload) &&
      'errors' in payload
    ) {
      const errors = (payload as { errors?: Record<string, string[]> }).errors
      const first = errors ? Object.values(errors)[0]?.[0] : undefined
      if (first) return first
    }
    return err.message || 'Something went wrong.'
  }

  if (axios.isAxiosError(err)) {
    const raw = err.response?.data
    if (raw && typeof raw === 'object') {
      const env = raw as {
        Message?: string
        Data?: { errors?: Record<string, string[]>; message?: string }
      }
      if (typeof env.Message === 'string' && env.Message) {
        const fieldErr =
          env.Data?.errors != null
            ? Object.values(env.Data.errors)[0]?.[0]
            : undefined
        return fieldErr ?? env.Message
      }
      const legacy = raw as {
        message?: string
        errors?: Record<string, string[]>
        error?: string
      }
      const firstField = legacy.errors
        ? Object.values(legacy.errors)[0]?.[0]
        : undefined
      return (
        firstField ??
        legacy.message ??
        legacy.error ??
        (err.response?.status === 401
          ? 'Session expired. Please sign in again.'
          : 'Failed to process the request.')
      )
    }
    return err.message || 'Network error.'
  }

  return err instanceof Error ? err.message : 'Unknown error.'
}
