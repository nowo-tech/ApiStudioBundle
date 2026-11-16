import { test, expect, type Page } from '@playwright/test';
import { openCreatePostConsole } from './helpers';

/**
 * Spec 003 (1.15), `scripts.mode: sandbox` (skipped in other modes): a stored script runs in the opaque-origin frame,
 * can change the request through `pm`, and cannot reach the page, the cookies, storage, popups or the network: the
 * frame removes fetch, XHR, WebSocket, EventSource, WebRTC, beacons and workers, and its CSP blocks the rest (dynamic
 * import, images, styles, scripts, frames, forms). The dev demo's web profiler strips the frame's CSP header, so this
 * also proves the policy the document carries in its own <head>.
 */
const HOSTILE = `
const r = [];
try { r.push('cookie:' + document.cookie.length); } catch (e) { r.push('cookie:blocked'); }
try { r.push('parent:' + parent.document.title); } catch (e) { r.push('parent:blocked'); }
try { r.push('top:' + top.location.href); } catch (e) { r.push('top:blocked'); }
try { r.push('storage:' + localStorage.length); } catch (e) { r.push('storage:blocked'); }
try { const x = new XMLHttpRequest(); x.open('GET', '/api-studio/', false); x.send(); r.push('xhr:' + x.status); } catch (e) { r.push('xhr:blocked'); }
try { require('fs'); r.push('fs:open'); } catch (e) { r.push('fs:blocked'); }
try { r.push('opener:' + (window.opener === null ? 'blocked' : 'open')); } catch (e) { r.push('opener:blocked'); }
try { r.push('open:' + (window.open('/leak-open') === null ? 'blocked' : 'open')); } catch (e) { r.push('open:blocked'); }
try { r.push('parentfn:' + typeof parent.fetch); } catch (e) { r.push('parentfn:blocked'); }
r.push('net:' + [typeof fetch, typeof XMLHttpRequest, typeof WebSocket, typeof EventSource, typeof RTCPeerConnection, typeof navigator.sendBeacon, typeof Worker].join('/'));
// Asynchronous channels the frame cannot remove (the CSP blocks them): their requests must never reach the server.
try { import('/leak-import.js').catch(() => undefined); } catch (e) { /* blocked */ }
try { new Image().src = '/leak-img'; } catch (e) { /* blocked */ }
try { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/leak-css'; document.head.appendChild(l); } catch (e) { /* blocked */ }
try { const s = document.createElement('script'); s.src = '/leak-script.js'; document.head.appendChild(s); } catch (e) { /* blocked */ }
try { const f = document.createElement('iframe'); f.src = '/leak-frame'; document.body.appendChild(f); } catch (e) { /* blocked */ }
try { const f = document.createElement('form'); f.action = '/leak-form'; f.method = 'post'; document.body.appendChild(f); f.submit(); } catch (e) { /* blocked */ }
pm.console.log(r.join(','));
pm.console.log('lib:' + require('lodash').chunk([1, 2, 3], 2).length);
pm.console.log('env:' + JSON.stringify(pm.environment.toObject()));
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
    // No page.route() here: Playwright request interception turns CSP enforcement off. A request that reached the
    // server has a response (404 for these paths); one the frame CSP blocked never does.
    const leaks: string[] = [];
    page.context().on('response', (r) => {
      if (r.url().includes('leak')) {
        let from = '?';
        try {
          from = r.frame().url();
        } catch {
          // a request without a frame (beacon after the frame is gone)
        }
        leaks.push(`${r.request().method()} ${r.url()} ${r.status()} origin=${r.request().headers()['origin'] ?? '-'} frame=${from}`);
      }
    });
    const sockets: string[] = [];
    page.on('websocket', (ws) => {
      // Playwright reports the socket object even when CSP refuses it; a handshake that reached the server (404 here)
      // would end in a socket error, an open one in frames.
      ws.on('socketerror', (e) => sockets.push(`${ws.url()} error ${e}`));
      ws.on('framesent', () => sockets.push(`${ws.url()} framesent`));
      ws.on('framereceived', () => sockets.push(`${ws.url()} framereceived`));
    });
    try {
      const sent = page.waitForRequest((r) => r.method() === 'POST' && /\/execute\/\d+$/.test(r.url()));
      await page.locator('#tester-send').click();
      const payload = JSON.parse((await sent).postData() ?? '{}') as { headers?: Record<string, string> };

      expect(payload.headers?.['X-Sandboxed']).toBe('yes');
      await expect(page.locator('#tester-script-log')).toContainText('cookie:blocked,parent:blocked,top:blocked,storage:blocked,xhr:blocked,fs:blocked,opener:blocked,open:blocked,parentfn:blocked,net:undefined/undefined/undefined/undefined/undefined/undefined/undefined');
      await expect(page.locator('#tester-script-log')).toContainText('lib:2');
      // Secret variables reach scripts as their own placeholder, never the value (US5).
      const log = (await page.locator('#tester-script-log').textContent()) ?? '';
      expect(log).toContain('"google_api_key":"{{google_api_key}}"');
      expect(log).not.toContain('YOUR_GOOGLE_CLOUD_API_KEY');
      // pm.sendRequest goes through the server guards: a private address is refused.
      await expect(page.locator('#tester-script-log')).toContainText('send-private:blocked');
      await expect(page.locator('dialog#tester-scripts-confirm-modal')).toHaveCount(0);
      // Nothing left the frame: no response for a leak path, no socket opened.
      await page.waitForTimeout(500);
      expect([...leaks, ...sockets]).toEqual([]);
      // Server side (dev demo, web profiler on): no request for a leak path reached the kernel.
      const profiler = await page.request.get('/_profiler/empty/search/results?url=leak&limit=50');
      if (profiler.ok()) {
        expect((await profiler.text()).match(/[A-Z]+\s*<\/td>\s*<td[^>]*>\s*[^<]*\/leak-[\w.-]+|\/leak-[\w.-]+/g) ?? []).toEqual([]);
      }
    } finally {
      await setStoredPreScript(page, consoleUrl, '');
    }
  });
});
