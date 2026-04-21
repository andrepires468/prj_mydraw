export type GallerySort = 'newest' | 'popular' | 'oldest'

export interface GalleryItem {
  id: string | number
  title: string
  category?: string | null
  imageUrl?: string | null
  alt?: string
}
