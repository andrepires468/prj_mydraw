import { test, expect } from '@playwright/test'
import { installPostsApiMocks } from './fixtures/mock-posts-api'
import {
  expectNoHorizontalOverflow,
  scrollDocumentAndAssertFluid,
} from './fixtures/layout-assertions'

test.describe('Fluid layout (no horizontal overflow)', () => {
  test.beforeEach(async ({ page }) => {
    await installPostsApiMocks(page)
  })

  test('gallery — loads, scrolls, keeps layout', async ({ page }, testInfo) => {
    const label = `gallery [${testInfo.project.name}]`
    await page.goto('/gallery')
    await expect(page.locator('main')).toBeVisible()
    await expect(page.getByRole('link', { name: /Open post/i })).toBeVisible({
      timeout: 20_000,
    })
    await scrollDocumentAndAssertFluid(page, label)
  })

  test('gallery — embedded post modal', async ({ page }, testInfo) => {
    const label = `modal post [${testInfo.project.name}]`
    await page.goto('/gallery/published/post/1')
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 20_000 })
    await expect(page.locator('.post-view')).toBeVisible()
    await expectNoHorizontalOverflow(page, `${label} (modal open)`)
    await page.getByRole('button', { name: 'Close' }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
  })

  test('post page', async ({ page }, testInfo) => {
    const label = `post view [${testInfo.project.name}]`
    await page.goto('/posts/1')
    await expect(page.locator('.post-view__title')).toBeVisible({ timeout: 20_000 })
    await scrollDocumentAndAssertFluid(page, label)
  })

  test('login (guest)', async ({ page }, testInfo) => {
    const label = `login [${testInfo.project.name}]`
    await page.goto('/login')
    await expect(page.getByRole('main')).toBeVisible()
    await expect(page.getByLabel(/Email/i)).toBeVisible()
    await scrollDocumentAndAssertFluid(page, label)
  })

  test('create redirects to login — form visible', async ({ page }, testInfo) => {
    const label = `redirect create→login [${testInfo.project.name}]`
    await page.goto('/posts/new/edit')
    await expect(page).toHaveURL(/\/login/)
    await expect(page.getByRole('main')).toBeVisible()
    await scrollDocumentAndAssertFluid(page, label)
  })
})
