import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * AS-SCRIPTS-1: with `scripts.mode: disabled` the page loads no api-script-runtime.js, so
 * window.ApiStudioScriptRuntime is undefined. The console must still send requests.
 */
const CONSOLE = `
<div class="as-console" id="api-studio-tester"
     data-execute-url="/api-studio/execute/7"
     data-csrf-token="tok"
     data-environment-maps="{}"
     data-service-protocol="rest"
     data-service-pre-script=""
     data-service-post-script="">
  <div class="as-subtabs" data-subtabs="request">
    <button type="button" class="as-subtab is-active" data-subtab="body">Body</button>
    <button type="button" class="as-subtab" data-subtab="pre">Pre</button>
    <button type="button" class="as-subtab" data-subtab="post">Post</button>
  </div>
  <div class="as-subpanel is-active" data-subpanel="body"><textarea id="tester-body">{"a":1}</textarea></div>
  <div class="as-subpanel" data-subpanel="pre"></div>
  <div class="as-subpanel" data-subpanel="post"></div>
  <select id="tester-method"><option value="POST" selected>POST</option></select>
  <button type="button" id="tester-send">Send</button>
  <div id="tester-status"></div>
  <pre id="tester-response"></pre>
  <pre id="tester-script-log"></pre>
</div>`;

async function boot(html: string, runtime?: unknown): Promise<ReturnType<typeof vi.fn>> {
  document.body.innerHTML = html;
  (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime = runtime;
  const fetchMock = vi.fn().mockResolvedValue({
    json: async () => ({
      request_url: 'https://api.example.test/things',
      request_method: 'POST',
      response_status: 201,
      response_body: '{"ok":true}',
      response_headers: {},
      request_headers: {},
      duration_ms: 3,
      success: true,
      error_message: null,
    }),
  });
  vi.stubGlobal('fetch', fetchMock);
  // The document outlives each test: run only this import's DOMContentLoaded handler, never an earlier one.
  const ready: EventListener[] = [];
  const native = document.addEventListener.bind(document);
  const spy = vi.spyOn(document, 'addEventListener').mockImplementation((type: string, listener: unknown, options?: unknown) => {
    if (type === 'DOMContentLoaded') {
      ready.push(listener as EventListener);

      return;
    }
    native(type, listener as EventListener, options as AddEventListenerOptions);
  });
  vi.resetModules();
  await import('../src/api-tester');
  spy.mockRestore();
  ready.forEach((listener) => listener(new Event('DOMContentLoaded')));

  return fetchMock;
}

async function flush(): Promise<void> {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve();
  }
}

describe('api-tester without the script runtime (AS-SCRIPTS-1)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    delete (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime;
  });

  it('wires Send and posts the request to the execute URL', async () => {
    const fetchMock = await boot(CONSOLE);

    document.getElementById('tester-send')!.click();
    await flush();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0]! as [string, RequestInit];
    expect(url).toBe('/api-studio/execute/7');
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
    const payload = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(payload.body).toBe('{"a":1}');
    expect(payload.method).toBe('POST');
    expect(document.getElementById('tester-status')!.textContent).toBe('POST → 201');
    expect(document.getElementById('tester-status')!.className).toBe('as-status-ok');
    expect((document.getElementById('tester-send') as HTMLButtonElement).disabled).toBe(false);
  });

  it('never runs scripts and hides the script tabs an override may still render', async () => {
    const html = CONSOLE.replace('data-service-pre-script=""', 'data-service-pre-script="pm.environment.set(\'x\', 1)"');
    const fetchMock = await boot(html);

    for (const sel of ['[data-subtab="pre"]', '[data-subtab="post"]', '[data-subpanel="pre"]', '[data-subpanel="post"]']) {
      expect(document.querySelector<HTMLElement>(sel)!.hidden).toBe(true);
    }
    expect(document.querySelector<HTMLElement>('[data-subtab="body"]')!.hidden).toBe(false);

    document.getElementById('tester-send')!.click();
    await flush();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(document.getElementById('tester-script-log')!.textContent).toBe('');
  });

  it('keeps the script tabs when the runtime is present', async () => {
    await boot(CONSOLE, {
      storageKey: () => 'k',
      loadRuntimeOverrides: () => ({}),
      saveRuntimeOverrides: () => undefined,
      createPm: () => {
        throw new Error('not used');
      },
      runScriptSafe: () => ({ ok: true, logs: [] }),
    });

    expect(document.querySelector<HTMLElement>('[data-subtab="pre"]')!.hidden).toBe(false);
  });
});
