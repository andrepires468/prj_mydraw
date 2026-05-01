import type { Page } from '@playwright/test'

const svgPlaceholder =
  'data:image/svg+xml,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect fill="#4f46e5" width="800" height="600"/><text x="400" y="310" text-anchor="middle" fill="white" font-family="sans-serif" font-size="42">mock</text></svg>',
  )

function galleryRow(id: number, postId: number) {
  const created = '2026-01-01T12:00:00.000000Z'
  return {
    id,
    post_id: postId,
    user_id: 1,
    draft_uuid: null,
    object_key: `mock/${id}`,
    mime_type: 'image/svg+xml',
    size: 512,
    position: id - 1,
    url: svgPlaceholder,
    thumbs: { '320x320': svgPlaceholder, '180x180': svgPlaceholder },
    created_at: created,
    updated_at: created,
  }
}

const postFixture = {
  id: 1,
  user_id: 1,
  title:
    'Título deliberadamente longo para validar quebra de linha e fluidez em telas estreitas',
  description:
    'Descrição com texto contínuo e palavra-superlonga-para-teste-de-overflow-abcdefghijklmnopqrstuvwxyz0123456789. ' +
    'Repetição para preencher blocos de metadados.\n'.repeat(4).trim(),
  status: 'published' as const,
  draft_uuid: null,
  author_name: 'Nome de autor extremamente longo para stressar o layout do cartão',
  created_at: '2026-01-15T10:30:00.000000Z',
  updated_at: '2026-01-15T10:30:00.000000Z',
  user: {
    id: 1,
    name: 'Nome de autor extremamente longo para stressar o layout do cartão',
  },
  images: [galleryRow(1, 1), galleryRow(2, 1)],
}

const listPageFixture = {
  data: [postFixture],
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 1,
  next_page_url: null,
  prev_page_url: null,
  has_more: false,
  counts: { published: 1, draft: 0 },
}

/**
 * Intercepta chamadas à API de posts para o app não depender do Lumen/MinIO
 * durante os testes de layout (fluidez).
 */
export async function installPostsApiMocks(page: Page): Promise<void> {
  await page.route('**/api/v1/posts**', async (route) => {
    const req = route.request()
    if (req.method() !== 'GET') {
      await route.continue()
      return
    }
    const path = new URL(req.url()).pathname
    if (/^\/api\/v1\/posts\/\d+$/.test(path)) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ post: postFixture }),
      })
      return
    }
    if (path === '/api/v1/posts') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(listPageFixture),
      })
      return
    }
    await route.continue()
  })
}
