import { expect, type Page } from '@playwright/test';

/** Opens the seeded "APIs de referencia" workspace from the API Studio dashboard (ids change on every reseed). */
export async function openReferenceWorkspace(page: Page): Promise<void> {
  await page.goto('/api-studio/');
  await page.locator('[data-testid="api-studio-projects"]').getByText('APIs de referencia').first().click();
  await expect(page).toHaveURL(/\/api-studio\/workspaces\/\d+$/);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('APIs de referencia');
}

/** Opens the JSONPlaceholder "Create post" endpoint in the request console. */
export async function openCreatePostConsole(page: Page): Promise<void> {
  await openReferenceWorkspace(page);
  await page.locator('.as-sidebar, aside, nav').getByRole('link', { name: 'Create post' }).first().click();
  await expect(page).toHaveURL(/\/endpoints\/\d+/);
  await expect(page.locator('#tester-send')).toBeVisible();
}

/** Waits for the Symfony WebProfiler toolbar so it is part of the capture. */
export async function waitForToolbar(page: Page): Promise<void> {
  await expect(page.locator('.sf-toolbar .sf-toolbarreset').first()).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(300);
}
