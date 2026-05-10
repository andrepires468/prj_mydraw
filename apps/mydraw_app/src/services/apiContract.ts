/**
 * Contrato JSON padronizado da API (`apps/mydraw_api`).
 */
export interface ApiEnvelope<T = unknown> {
  Status: boolean
  Code: number
  Message: string
  Data: T
}

export type ApiErrorPayload = Record<string, unknown> | unknown[] | null

/** Erro de negócio retornado com `Status: false` no body (HTTP pode ser 200 ou 4xx). */
export class ApiEnvelopeError extends Error {
  readonly httpStatus: number
  readonly payload: ApiErrorPayload

  constructor(
    message: string,
    httpStatus: number,
    payload: ApiErrorPayload = null,
  ) {
    super(message)
    this.name = 'ApiEnvelopeError'
    this.httpStatus = httpStatus
    this.payload = payload
  }
}

export function unwrapEnvelope<T>(body: unknown): T {
  if (!body || typeof body !== 'object') {
    throw new Error('Invalid API response body.')
  }
  const e = body as ApiEnvelope<T>
  if (!('Status' in e) || !('Data' in e)) {
    throw new Error('Response is not a valid API envelope.')
  }
  if (!e.Status) {
    throw new ApiEnvelopeError(
      e.Message || 'Request failed.',
      e.Code,
      e.Data as ApiErrorPayload,
    )
  }
  return e.Data as T
}
