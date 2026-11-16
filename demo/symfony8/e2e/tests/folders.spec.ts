import { test, expect, type Page } from '@playwright/test';
import { openCreatePostConsole } from './helpers';

/** 1.14 folders: create a folder, drag an endpoint into it, keyboard move through the endpoint form, delete it. */
const FOLDER = 'E2E folder';

async function servicePath(page: Page): Promise<string> {
  await openCreatePostConsole(page);
  return new URL(page.url()).pathname.replace(/\/endpoints\/\d+$/, '');
}

test.describe('Folders', () => {
  test('create a folder, drag an endpoint into it, move it back with the form, delete the folder', async ({ page }) => {
    const service = await servicePath(page);
    const consoleUrl = new URL(page.url()).pathname;

    await page.goto(`${service}/folders/new`);
    await page.locator('input[name="api_folder_form[name]"]').fill(FOLDER);
    await page.locator('form[name="api_folder_form"] [type="submit"]').first().click();
    await expect(page).toHaveURL(new RegExp(`${service}$`));
    await expect(page.locator('[data-testid="api-studio-service-folders"]')).toContainText(FOLDER);

    const folder = page.locator('#as-nav-tree [data-tree-folder]', { hasText: FOLDER }).first();
    await expect(folder).toBeVisible();
    const endpoint = page.locator('#as-nav-tree [data-tree-node="endpoint"]', { hasText: 'Create post' }).first();
    const moved = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/folders/move'));
    await endpoint.dragTo(folder.locator('.as-tree-folder-label'));
    expect((await moved).ok()).toBeTruthy();

    await page.reload();
    await expect(page.locator('#as-nav-tree [data-tree-folder]', { hasText: FOLDER }).locator('[data-tree-node="endpoint"]', { hasText: 'Create post' })).toHaveCount(1);

    // Keyboard alternative: the Folder select of the endpoint form.
    await page.goto(`${consoleUrl}/edit`);
    await page.locator('select[name="api_endpoint_form[folder]"]').selectOption('');
    await page.locator('form[name="api_endpoint_form"] [type="submit"]').first().click();
    await expect(page).toHaveURL(new RegExp(`${consoleUrl}$`));
    await expect(page.locator('#as-nav-tree [data-tree-folder]', { hasText: FOLDER }).locator('[data-tree-node="endpoint"]')).toHaveCount(0);

    // Delete the folder (UiKit confirm dialog).
    await page.goto(service);
    await page.locator('[data-testid="api-studio-service-folders"] a', { hasText: FOLDER }).first().click();
    await page.locator('[data-testid="api-studio-folder-delete"]').first().click();
    await page.locator('dialog[open] form[action$="/delete"] [type="submit"]').first().click();
    await expect(page).toHaveURL(new RegExp(`${service}$`));
    await expect(page.locator('[data-testid="api-studio-service-folders"]')).not.toContainText(FOLDER);
  });
});
