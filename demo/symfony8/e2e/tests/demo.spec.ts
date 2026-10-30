import { test, expect } from '@playwright/test';
import { openCreatePostConsole, openReferenceWorkspace } from './helpers';

/** REQ-DEMO-013 — API Studio demo happy path (landing → dashboard → workspace → request console). */
test.describe('API Studio demo', () => {
  test('landing links to the API Studio dashboard', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await page.locator('main').getByRole('link', { name: 'Abrir API Studio' }).click();
    await expect(page).toHaveURL(/\/api-studio\/?$/);
    await expect(page.locator('[data-testid="api-studio-dashboard"]')).toBeVisible();
    await expect(page.locator('[data-testid="api-studio-projects"]')).toContainText('APIs de referencia');
  });

  test('workspace lists the seeded REST and SOAP services', async ({ page }) => {
    await openReferenceWorkspace(page);
    const content = page.locator('[data-testid="api-studio-service-collections"]');
    await expect(content.getByText('JSONPlaceholder').first()).toBeVisible();
    await expect(content.getByText('Catastro — OVCCallejero (SOAP)').first()).toBeVisible();
    await expect(content.getByText('Google Cloud Translation API').first()).toBeVisible();
  });

  test('request console shows the endpoint body and documented examples', async ({ page }) => {
    await openCreatePostConsole(page);
    await expect(page.getByRole('heading', { name: 'Create post' })).toBeVisible();
    await expect(page.locator('[data-subpanel="body"] textarea').first()).toHaveValue(/"userId": 1/);
    await page.locator('[data-subtab="docs"]').click();
    await expect(page.locator('#tester-request-examples')).toContainText('Minimal JSON body');
    await expect(page.locator('#tester-response-examples')).toContainText('201 Created');
  });

  test('response body uses the theme text colour in the light theme', async ({ page }) => {
    await openCreatePostConsole(page);
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');
    const colours = await page.locator('#tester-response').evaluate((pre) => {
      const text = getComputedStyle(pre).color;
      // --as-text maps the UiKit --nowo-ui-text token (host theme) on the workbench.
      const expected = getComputedStyle(pre).getPropertyValue('--as-text').trim();
      const probe = document.createElement('span');
      probe.style.color = expected;
      document.body.append(probe);
      const resolved = getComputedStyle(probe).color;
      probe.remove();
      return { text, resolved };
    });
    expect(colours.text).toBe(colours.resolved);
  });

  test('service delete asks for the typed name in a native dialog', async ({ page }) => {
    await openReferenceWorkspace(page);
    const collections = page.locator('[data-testid="api-studio-service-collections"]');
    await collections.locator('[data-nowo-ui-kebab] summary').first().click();
    await collections.locator('[data-service-action="delete"]').first().click();
    const dialog = page.locator('dialog#as-service-delete-modal');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#as-service-delete-submit')).toBeDisabled();
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();
  });
});
