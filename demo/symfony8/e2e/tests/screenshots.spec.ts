import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { openCreatePostConsole, openReferenceWorkspace, waitForToolbar } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs).
 * API Studio pages render in the demo host layout (UiKit page contract): demo masthead, page header,
 * the workbench (API tree + content) and the Symfony WebProfiler toolbar at the bottom.
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
    const collections = page.locator('[data-testid="api-studio-service-collections"]');
    await expect(collections.getByText('JSONPlaceholder').first()).toBeVisible();
    // The first service is expanded by default and shows its endpoints.
    await expect(collections.getByText('Consulta por referencia catastral').first()).toBeVisible();
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'workspace-services.png') });
  });

  test('request-console — live request sent from the console', async ({ page }) => {
    await openCreatePostConsole(page);
    await expect(page.locator('[data-subpanel="body"] textarea').first()).toHaveValue(/"userId": 1/);
    // JSONPlaceholder is a public echo API (needs outbound network); it answers 201 with id 101.
    await page.locator('#tester-send').click();
    await expect(page.locator('#tester-status')).toContainText('201', { timeout: 30_000 });
    await page.locator('#tester-send').blur();
    await page.evaluate(() => {
      document.querySelectorAll<HTMLElement>('*').forEach((el) => { if (el.scrollTop) el.scrollTop = 0; });
      window.scrollTo(0, 0);
    });
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'request-console.png') });
  });
});
