export type GallerySort = 'newest' | 'oldest'

export interface GalleryItem {
  id: string | number
  title: string
  category?: string | null
  imageUrl?: string | null
  alt?: string
}
