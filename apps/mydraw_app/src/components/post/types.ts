export interface QueuedImage {
  id: string
  file: File
  previewUrl: string
  status: 'idle' | 'uploading' | 'done' | 'error'
  progress?: number
  error?: string | null
  /** ID retornado pelo backend após upload bem-sucedido */
  galleryId?: number
}
