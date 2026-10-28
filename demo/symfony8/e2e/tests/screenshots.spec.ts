import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { openCreatePostConsole, openReferenceWorkspace, waitForToolbar } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs).
 * API Studio is a full-viewport app shell (rail + sidebar tree + content), so captures are the
 * whole viewport with the Symfony WebProfiler toolbar at the bottom.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.use({ viewport: { width: 1440, height: 1200 } });

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('API Studio screenshots', () => {
  test('workspace-services — workspace with REST/SOAP services and endpoints', async ({ page }) => {
    await openReferenceWorkspace(page);
    await expect(page.locator('.as-content').getByText('JSONPlaceholder').first()).toBeVisible();
    // The first service is expanded by default and shows its endpoints.
    await expect(page.locator('.as-content').getByText('Consulta por referencia catastral').first()).toBeVisible();
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'workspace-services.png') });
  });

  test('request-console — live request sent from the console', async ({ page }) => {
    await openCreatePostConsole(page);
    await expect(page.locator('[data-subpanel="body"] textarea').first()).toHaveValue(/"userId": 1/);
    // JSONPlaceholder is a public echo API (needs outbound network); it answers 201 with id 101.
    // Dark theme: the light theme renders the response body with very low contrast.
    await page.locator('#as-theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.locator('#tester-send').click();
    await expect(page.locator('#tester-status')).toContainText('201', { timeout: 30_000 });
    await page.locator('#as-theme-toggle').blur();
    await page.evaluate(() => {
      document.querySelectorAll<HTMLElement>('*').forEach((el) => { if (el.scrollTop) el.scrollTop = 0; });
      window.scrollTo(0, 0);
    });
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'request-console.png') });
  });
});
