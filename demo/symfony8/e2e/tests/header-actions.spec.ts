import { test, expect } from '@playwright/test';

/** ui.header_actions: page_actions (1.23) — the create actions are entries of the UiKit page-actions menu. */
test.describe('Header actions (page_actions)', () => {
  test('"New workspace" is an entry of the page-actions menu, not a header button', async ({ page }) => {
    await page.goto('/api-studio/workspaces');
    const menu = page.locator('[data-nowo-ui-header-more]').first();
    await expect(menu).toBeVisible();
    await menu.getByRole('button').first().click();
    await expect(page.locator('[data-nowo-ui-header-more] [data-testid="api-studio-new-workspace"]')).toBeVisible();
  });
});
