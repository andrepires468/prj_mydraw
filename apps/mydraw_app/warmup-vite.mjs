const origin = (process.env.VITE_WARMUP_ORIGIN || 'http://127.0.0.1:5173').trim()
const timeoutMs = Number.parseInt(process.env.VITE_WARMUP_TIMEOUT_MS || '45000', 10)
const pollIntervalMs = 700

const warmupTargets = [
  '/',
  '/@vite/client',
  '/src/main.ts',
  '/src/App.vue',
  '/src/router/index.ts',
  '/src/views/GalleryView.vue',
  '/src/components/layout/TopNavBar.vue',
  '/src/components/layout/BottomNavBar.vue',
  '/src/components/layout/FloatingActionButton.vue',
  '/src/components/gallery/GalleryFilters.vue',
  '/src/components/gallery/GalleryStatusToggle.vue',
  '/src/components/gallery/GalleryCard.vue',
  '/src/components/gallery/MasonryGrid.vue',
  '/src/components/gallery/GalleryEditorProfile.vue',
  '/src/assets/styles/main.css',
]

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function waitForServer() {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${origin}/`, { cache: 'no-store' })
      if (response.ok) {
        return true
      }
    } catch {
      // servidor ainda subindo
    }

    await sleep(pollIntervalMs)
  }

  return false
}

async function warmupTarget(target) {
  const response = await fetch(`${origin}${target}`, { cache: 'no-store' })
  console.log(`[vite-warmup] ${response.status} ${target}`)
}

async function main() {
  const ready = await waitForServer()
  if (!ready) {
    console.warn(`[vite-warmup] timeout waiting for ${origin}`)
    return
  }

  console.log(`[vite-warmup] server ready at ${origin}`)

  for (const target of warmupTargets) {
    try {
      await warmupTarget(target)
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error)
      console.warn(`[vite-warmup] failed ${target}: ${message}`)
    }
  }
}

void main()
