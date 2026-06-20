import axios from 'axios'
import { ApiEnvelopeError } from '@/services/apiContract'

const GEMINI_TIMEOUT_UI_MESSAGE =
  'Gemini did not respond in time (up to 4 minutes per post). The request timed out. Try again with fewer posts per run or wait and retry.'

const CLIENT_TIMEOUT_UI_MESSAGE =
  'The request timed out before the server finished. Metadata extraction and Gemini can take several minutes; try again with posts per run = 1.'

function isTimeoutLikeAxiosError(err: import('axios').AxiosError): boolean {
  return (
    err.code === 'ECONNABORTED' ||
    err.message.toLowerCase().includes('timeout')
  )
}

function envelopeDataError(
  data: unknown,
): string | undefined {
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return undefined
  }
  const err = (data as { error?: unknown }).error
  return typeof err === 'string' ? err : undefined
}

/**
 * Mensagem amigável em inglês para a UI a partir de falhas de API.
 */
export function getApiErrorMessage(err: unknown): string {
  if (err instanceof ApiEnvelopeError) {
    const payload = err.payload
    if (
      payload &&
      typeof payload === 'object' &&
      !Array.isArray(payload)
    ) {
      const code = envelopeDataError(payload)
      if (code === 'gemini_timeout') {
        return GEMINI_TIMEOUT_UI_MESSAGE
      }
      if ('errors' in payload) {
        const errors = (payload as { errors?: Record<string, string[]> }).errors
        const first = errors ? Object.values(errors)[0]?.[0] : undefined
        if (first) return first
      }
    }
    if (err.httpStatus === 504) {
      return GEMINI_TIMEOUT_UI_MESSAGE
    }
    return err.message || 'Something went wrong.'
  }

  if (axios.isAxiosError(err)) {
    if (isTimeoutLikeAxiosError(err)) {
      return CLIENT_TIMEOUT_UI_MESSAGE
    }

    const raw = err.response?.data
    if (raw && typeof raw === 'object') {
      const env = raw as {
        Message?: string
        Data?: {
          error?: string
          errors?: Record<string, string[]>
          message?: string
          detail?: string
        }
        Code?: number
      }
      if (env.Data?.error === 'gemini_timeout' || env.Code === 504) {
        return GEMINI_TIMEOUT_UI_MESSAGE
      }
      if (typeof env.Message === 'string' && env.Message) {
        const fieldErr =
          env.Data?.errors != null
            ? Object.values(env.Data.errors)[0]?.[0]
            : undefined
        const detail =
          typeof env.Data?.detail === 'string' && env.Data.detail
            ? env.Data.detail
            : undefined
        const dataMsg =
          typeof env.Data?.message === 'string' && env.Data.message
            ? env.Data.message
            : undefined
        return fieldErr ?? detail ?? dataMsg ?? env.Message
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
