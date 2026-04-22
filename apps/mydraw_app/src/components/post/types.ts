export interface QueuedImage {
  id: string
  file: File
  previewUrl: string
  /**
   * Miniatura 320×320 da API quando existir; o card usa isto no lugar da URL em alta.
   */
  thumbnailUrl?: string | null
  status: 'idle' | 'queued' | 'uploading' | 'done' | 'error'
  progress?: number
  error?: string | null
  /** ID retornado pelo backend após upload bem-sucedido */
  galleryId?: number
}
