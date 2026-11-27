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

  test('workbench spans a host grid cards slot (grid lg:grid-cols-2)', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/api-studio/');
    // Simulate a host that styles the UiKit cards region as a two-column grid (Beacon: 'grid gap-4 lg:grid-cols-2').
    await page.addStyleTag({
      content: '[data-nowo-ui-region="cards"]{display:grid;gap:1rem;grid-template-columns:repeat(2,minmax(0,1fr))}',
    });
    const m = await page.evaluate(() => {
      const region = document.querySelector('[data-nowo-ui-region="cards"]') as HTMLElement;
      const wb = document.querySelector('[data-testid="api-studio-workbench"]') as HTMLElement;
      const grid = document.querySelector('.as-project-grid') as HTMLElement;
      return {
        region: region.getBoundingClientRect().width,
        workbench: wb.getBoundingClientRect().width,
        projectColumns: getComputedStyle(grid).gridTemplateColumns.split(' ').length,
      };
    });
    expect(m.workbench).toBeGreaterThanOrEqual(m.region - 1);
    expect(m.projectColumns).toBeGreaterThan(1);
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
    await expect(page.getByRole('heading', { level: 1, name: 'Create post' })).toBeVisible();
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
    await expect(page.locator('form#as-service-delete-form')).toHaveCount(0);
    await collections.locator('[data-nowo-ui-dialog-open="as-service-dialog"][href*="/delete"]').first().click();
    const dialog = page.locator('dialog#as-service-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#as-service-delete-submit')).toBeDisabled();
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();
  });
});
