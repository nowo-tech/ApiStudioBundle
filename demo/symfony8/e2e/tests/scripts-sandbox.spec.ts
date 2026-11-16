import { test, expect, type Page } from '@playwright/test';
import { openCreatePostConsole } from './helpers';

/**
 * Spec 003 (1.15), `scripts.mode: sandbox` (skipped in other modes): a stored script runs in the opaque-origin frame,
 * can change the request through `pm`, and cannot reach the page, the cookies, storage or the network.
 */
const HOSTILE = `
const r = [];
try { r.push('cookie:' + document.cookie.length); } catch (e) { r.push('cookie:blocked'); }
try { r.push('parent:' + parent.document.title); } catch (e) { r.push('parent:blocked'); }
try { r.push('top:' + top.location.href); } catch (e) { r.push('top:blocked'); }
try { r.push('storage:' + localStorage.length); } catch (e) { r.push('storage:blocked'); }
try { const x = new XMLHttpRequest(); x.open('GET', '/api-studio/', false); x.send(); r.push('xhr:' + x.status); } catch (e) { r.push('xhr:blocked'); }
pm.console.log(r.join(','));
pm.request.setHeader('X-Sandboxed', 'yes');
pm.sendRequest('http://127.0.0.1/', (err) => pm.console.log('send-private:' + (err ? 'blocked' : 'open')));
`;

async function setStoredPreScript(page: Page, consoleUrl: string, code: string): Promise<void> {
  await page.goto(`${consoleUrl}/edit`);
  const field = page.locator('textarea[name$="[preRequestScript]"]');
  await field.fill(code);
  await field.locator('xpath=ancestor::form').locator('[type="submit"]').first().click();
  await expect(page).toHaveURL(new RegExp(`${consoleUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
}

test.describe('Sandboxed scripts', () => {
  test('a hostile stored script is contained and still shapes the request', async ({ page }) => {
    await openCreatePostConsole(page);
    const consoleUrl = new URL(page.url()).pathname;
    const mode = await page.locator('#api-studio-tester').getAttribute('data-scripts-mode');
    test.skip(mode !== 'sandbox', 'needs scripts.mode: sandbox');

    await setStoredPreScript(page, consoleUrl, HOSTILE);
    try {
      const sent = page.waitForRequest((r) => r.method() === 'POST' && /\/execute\/\d+$/.test(r.url()));
      await page.locator('#tester-send').click();
      const payload = JSON.parse((await sent).postData() ?? '{}') as { headers?: Record<string, string> };

      expect(payload.headers?.['X-Sandboxed']).toBe('yes');
      await expect(page.locator('#tester-script-log')).toContainText('cookie:blocked,parent:blocked,top:blocked,storage:blocked,xhr:blocked');
      // pm.sendRequest goes through the server guards: a private address is refused.
      await expect(page.locator('#tester-script-log')).toContainText('send-private:blocked');
      await expect(page.locator('dialog#tester-scripts-confirm-modal')).toHaveCount(0);
    } finally {
      await setStoredPreScript(page, consoleUrl, '');
    }
  });
});
