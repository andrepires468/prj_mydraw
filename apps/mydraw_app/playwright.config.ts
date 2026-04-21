import { defineConfig } from '@playwright/test'

/**
 * Viewports usados para validar fluidez web/mobile (largura × altura).
 * Cada item vira um projeto Playwright (execução isolada com o mesmo spec).
 */
const fluidityViewports = [
  { name: 'mobile-xs', width: 320, height: 568 },
  { name: 'mobile-s', width: 360, height: 740 },
  { name: 'mobile-m', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'laptop', width: 1280, height: 720 },
  { name: 'desktop', width: 1920, height: 1080 },
] as const

export default defineConfig({
  testDir: 'e2e',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? 'github' : [['list'], ['html', { open: 'never' }]],
  timeout: 60_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: 'http://127.0.0.1:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: fluidityViewports.map((v) => ({
    name: v.name,
    use: {
      viewport: { width: v.width, height: v.height },
    },
  })),
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5173',
    url: 'http://127.0.0.1:5173',
    // Em CI a porta deve estar livre. Se `vite` já estiver rodando (ex.: dev local),
    // defina `PW_REUSE_DEV=1` para reutilizar o servidor em vez de subir outro.
    reuseExistingServer:
      process.env.PW_REUSE_DEV === '1' || !process.env.CI,
    timeout: 120_000,
  },
})
