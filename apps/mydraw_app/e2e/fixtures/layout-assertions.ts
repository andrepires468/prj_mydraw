import { expect, type Page } from '@playwright/test'

/** Pixel margin for subpixel rounding / vertical scrollbar width. */
const HORIZONTAL_TOLERANCE = 3

export type OverflowReport = {
  innerWidth: number
  scrollWidth: number
  delta: number
}

/**
 * Measures horizontal overflow at the document root (common when mobile layouts burst).
 */
export async function measureHorizontalOverflow(page: Page): Promise<OverflowReport> {
  return page.evaluate(() => {
    const root = document.documentElement
    const body = document.body
    const innerWidth = window.innerWidth
    const scrollWidth = Math.max(
      root.scrollWidth,
      body.scrollWidth,
      root.offsetWidth,
      body.offsetWidth,
    )
    return {
      innerWidth,
      scrollWidth,
      delta: scrollWidth - innerWidth,
    }
  })
}

export async function expectNoHorizontalOverflow(
  page: Page,
  contextLabel: string,
): Promise<void> {
  const r = await measureHorizontalOverflow(page)
  expect(
    r.delta,
    `${contextLabel}: scrollable width ${r.scrollWidth}px > viewport ${r.innerWidth}px (delta ${r.delta}px)`,
  ).toBeLessThanOrEqual(HORIZONTAL_TOLERANCE)
}

/**
 * Scrolls the full page in steps and re-checks overflow (some bugs only show after scroll).
 */
export async function scrollDocumentAndAssertFluid(
  page: Page,
  contextLabel: string,
): Promise<void> {
  await expectNoHorizontalOverflow(page, `${contextLabel} (top)`)

  const steps = 5
  for (let i = 1; i <= steps; i++) {
    await page.evaluate((frac) => {
      const max = Math.max(
        document.documentElement.scrollHeight - window.innerHeight,
        0,
      )
      window.scrollTo({ top: (max * frac) | 0, behavior: 'auto' })
    }, i / steps)
    await expectNoHorizontalOverflow(page, `${contextLabel} (scroll ${i}/${steps})`)
  }

  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'auto' }))
}
