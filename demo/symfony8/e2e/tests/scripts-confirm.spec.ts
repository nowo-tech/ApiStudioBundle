import { test, expect, type Page } from '@playwright/test';
import { openCreatePostConsole } from './helpers';

/**
 * scripts.confirm_stored (1.13.2): a stored endpoint script is shown and only runs once approved in this browser
 * session; "send without scripts" runs no code; a changed script asks again; code typed by the user is not asked.
 */
const MARKER = 'E2E-STORED-RAN';

async function setStoredPreScript(page: Page, consoleUrl: string, code: string): Promise<void> {
  await page.goto(`${consoleUrl}/edit`);
  const field = page.locator('textarea[name$="[preRequestScript]"]');
  await field.fill(code);
  await field.locator('xpath=ancestor::form').locator('[type="submit"]').first().click();
  await expect(page).toHaveURL(new RegExp(`${consoleUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
}

async function send(page: Page): Promise<void> {
  await page.locator('#tester-send').click();
}

/** Runs the action and waits until the console has posted the request and rendered the answer. */
async function sent(page: Page, action: () => Promise<void>): Promise<void> {
  const answered = page.waitForResponse((r) => r.request().method() === 'POST' && /\/execute\/\d+$/.test(r.url()), { timeout: 30_000 });
  await action();
  await answered;
  await expect(page.locator('#tester-send')).toBeEnabled();
}

test.describe('Stored script confirmation', () => {
  let consoleUrl = '';

  test.beforeEach(async ({ page }) => {
    await openCreatePostConsole(page);
    consoleUrl = new URL(page.url()).pathname;
    const mode = await page.locator('#api-studio-tester').getAttribute('data-scripts-mode');
    test.skip(mode !== 'inline', 'confirmation applies to scripts.mode: inline');
  });

  test.afterEach(async ({ page }) => {
    await setStoredPreScript(page, consoleUrl, '');
  });

  test('shows a stored script and sends without running it', async ({ page }) => {
    await setStoredPreScript(page, consoleUrl, `pm.console.log('${MARKER}')`);
    let executed = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST' && /\/execute\/\d+/.test(request.url())) {
        executed++;
      }
    });

    await send(page);
    const dialog = page.locator('dialog#tester-scripts-confirm-modal');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('pre.as-script-confirm-code')).toContainText(MARKER);
    await expect(dialog.locator('[data-script-stats="pre-request"]')).toContainText('1');
    expect(executed).toBe(0);

    await sent(page, () => dialog.locator('#tester-scripts-confirm-skip').click());
    await expect(dialog).toBeHidden();
    expect(executed).toBe(1);
    await expect(page.locator('#tester-script-log')).not.toContainText(MARKER);
    await expect(page.locator('#tester-script-log')).toContainText('Sent without scripts');
  });

  test('remembers an approval until the stored script changes', async ({ page }) => {
    await setStoredPreScript(page, consoleUrl, `pm.console.log('${MARKER}')`);
    const dialog = page.locator('dialog#tester-scripts-confirm-modal');

    await send(page);
    await expect(dialog).toBeVisible();
    await sent(page, () => dialog.locator('#tester-scripts-confirm-run').click());
    await expect(page.locator('#tester-script-log')).toContainText(`[pre-request] ${MARKER}`);

    // Approved in this session: no dialog.
    await sent(page, () => send(page));
    await expect(dialog).toBeHidden();
    await expect(page.locator('#tester-script-log')).toContainText(`[pre-request] ${MARKER}`);

    // Another author changes the stored script: asked again.
    await setStoredPreScript(page, consoleUrl, `pm.console.log('${MARKER}-CHANGED')`);
    await send(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('pre')).toContainText(`${MARKER}-CHANGED`);
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();
  });

  test('shows hidden characters of a stored script as code points', async ({ page }) => {
    await setStoredPreScript(page, consoleUrl, `pm.console.log('${MARKER}')/*\u202E*/`);
    const dialog = page.locator('dialog#tester-scripts-confirm-modal');

    await send(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('pre.as-script-confirm-code')).toContainText('⟨U+202E⟩');
    await expect(dialog.locator('[data-script-warning="hidden"]')).toBeVisible();
    await dialog.locator('[data-nowo-ui-dialog-close]').first().click();
    await expect(dialog).toBeHidden();
  });

  test('does not ask for code the user typed', async ({ page }) => {
    await page.goto(consoleUrl);
    await page.locator('[data-subtab="pre"]').click();
    await page.locator('#tester-pre-script').fill(`pm.console.log('${MARKER}-MINE')`);

    await sent(page, () => send(page));
    await expect(page.locator('dialog#tester-scripts-confirm-modal')).toBeHidden();
    await expect(page.locator('#tester-script-log')).toContainText(`[pre-request] ${MARKER}-MINE`);
  });
});
