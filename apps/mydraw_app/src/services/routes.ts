/**
 * Paths relativos ao prefixo `{VITE_API_URL}/api/{VITE_API_VERSION}` (ver `src/api/http.ts`).
 */
export const routes = {
  auth: {
    login: '/auth/login',
    register: '/auth/register',
    me: '/auth/me',
    profile: '/auth/profile',
    password: '/auth/password',
    logout: '/auth/logout',
    google: '/auth/google',
    googleLink: '/auth/google/link',
    googleUnlink: '/auth/google/unlink',
  },
  editor: {
    profile: '/editor/profile',
  },
  drawings: {
    list: '/drawings',
    one: (id: number) => `/drawings/${id}`,
  },
  posts: {
    list: '/posts',
    one: (id: number | string) => `/posts/${id}`,
    metadataExtract: '/posts/metadata/extract',
    images: (postId: number) => `/posts/${postId}/images`,
    imageOne: (postId: number, galleryId: number) =>
      `/posts/${postId}/images/${galleryId}`,
    reorder: (postId: number) => `/posts/${postId}/images/reorder`,
    update: (postId: number) => `/posts/${postId}`,
  },
  createPost: {
    upload: '/create-post/upload',
    finalize: '/create-post',
  },
} as const
