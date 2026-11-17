import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The request console (api-tester.ts) with its real script runtime and body tools: URL preview, header / parameter
 * tables, body tools, sending (variables, tests, persistence), request / response examples and the response tabs.
 */
type Dataset = Record<string, string>;

function consoleHtml(data: Dataset = {}, extra = ''): string {
  const attrs: Dataset = {
    'data-scripts-mode': 'inline',
    'data-scripts-confirm': '0',
    'data-execute-url': '/api-studio/execute/7',
    'data-csrf-token': 'tok',
    'data-environment-maps': JSON.stringify({ '1': { variables: { host: 'https://api.test', id: '42' } }, '2': { variables: {} } }),
    'data-service-protocol': 'rest',
    'data-service-base-url': '{{host}}/',
    'data-endpoint-path': 'users/{{id}}',
    'data-env-sync-url': '/env/__ENV__/sync',
    'data-env-sync-csrf': 'sync-tok',
    'data-examples-csrf': 'ex-tok',
    'data-save-request-url': '/examples/request',
    'data-save-response-url': '/examples/response',
    'data-delete-request-url': '/examples/request/__EXAMPLE__/delete',
    'data-delete-response-url': '/examples/response/__EXAMPLE__/delete',
    'data-service-pre-script': '',
    'data-service-post-script': '',
    ...data,
  };
  const html = Object.entries(attrs)
    .map(([k, v]) => `${k}='${v.replace(/'/g, '&#39;')}'`)
    .join(' ');

  return `
<div class="as-console" id="api-studio-tester" ${html}>
  <div id="tester-method-wrap"><select id="tester-method"><option value="GET">GET</option><option value="POST" selected>POST</option><option value="TRACE">TRACE</option></select></div>
  <select id="tester-environment"><option value="">none</option><option value="1" selected>dev</option><option value="2">empty</option></select>
  <input id="tester-url-display"><span id="tester-url-unresolved" hidden></span>
  <table><tbody id="tester-headers-body"></tbody></table><button type="button" id="tester-headers-add"></button>
  <table><tbody id="tester-params-body"><tr data-param-row><td><input type="checkbox" class="as-kv-enabled" checked></td><td><input class="as-kv-key" value="q"></td><td><input class="as-kv-value" value="{{id}}"></td><td><code class="as-resolved-param"></code></td><td><button class="as-kv-remove"></button></td></tr></tbody></table>
  <button type="button" id="tester-params-add"></button>
  <textarea id="tester-body">{"a":1}</textarea>
  <textarea id="tester-pre-script"></textarea><textarea id="tester-post-script"></textarea>
  <label><input type="checkbox" id="tester-persist-vars"></label>
  <button type="button" id="tester-send">Send</button>
  <div id="tester-status"></div>
  <button type="button" data-response-tab="body" class="is-active"></button><button type="button" data-response-tab="headers"></button>
  <div data-response-panel="body" class="is-active"><div class="as-response-body-toolbar"></div><pre id="tester-response"></pre></div>
  <div data-response-panel="headers" hidden><pre id="tester-response-headers"></pre></div>
  <span id="tester-duration"></span>
  <pre id="tester-script-log"></pre>
  <button type="button" id="tester-body-format"></button><button type="button" id="tester-body-validate"></button><div id="tester-body-validation" hidden></div>
  <button type="button" id="tester-response-format"></button><button type="button" id="tester-response-validate"></button><div id="tester-response-validation" hidden></div>
  <button type="button" id="tester-save-request"></button><button type="button" id="tester-save-response" disabled>Save response</button>
  <div id="tester-request-examples"><p class="as-examples-empty">none</p></div><div id="tester-response-examples"></div>
</div>
<dialog id="tester-example-modal"><span id="tester-example-modal-title"></span><input id="tester-example-name"><button type="button" id="tester-example-save"></button></dialog>
<dialog id="tester-example-delete-modal"></dialog><button type="button" id="tester-example-delete-confirm"></button>
${extra}`;
}

const OK = { request_method: 'POST', request_url: 'https://api.test/users/42', request_headers: { A: 'b' }, response_status: 201, response_headers: { 'Content-Type': 'application/json', Multi: ['a', 'b'] }, response_body: '{"id":5}', duration_ms: 7, success: true, error_message: null };

type Reply = { ok?: boolean; status?: number; body: unknown };

function reply({ ok = true, status = 200, body }: Reply): { ok: boolean; status: number; json: () => Promise<unknown> } {
  return { ok, status, json: async () => body };
}

const ready: EventListener[] = [];

async function boot(html: string, fetchReplies: Reply[] = [{ body: OK }]): Promise<ReturnType<typeof vi.fn>> {
  document.body.innerHTML = html;
  const fetchMock = vi.fn();
  fetchReplies.forEach((r) => fetchMock.mockResolvedValueOnce(reply(r)));
  fetchMock.mockResolvedValue(reply({ body: OK }));
  vi.stubGlobal('fetch', fetchMock);
  vi.resetModules();
  sessionStorage.clear();
  delete (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime;
  delete (window as unknown as Record<string, unknown>).ApiStudioBodyTools;
  await import('../src/api-script-runtime');
  await import('../src/api-body-tools');
  const native = document.addEventListener.bind(document);
  const spy = vi.spyOn(document, 'addEventListener').mockImplementation((type: string, listener: unknown, options?: unknown) => {
    if (type === 'DOMContentLoaded') {
      ready.push(listener as EventListener);

      return;
    }
    native(type, listener as EventListener, options as AddEventListenerOptions);
  });
  await import('../src/api-tester');
  spy.mockRestore();
  ready.splice(0).forEach((listener) => listener(new Event('DOMContentLoaded')));

  return fetchMock;
}

const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;
const log = (): string => $('tester-script-log').textContent ?? '';
const send = async (): Promise<void> => {
  $('tester-send').click();
  await vi.waitFor(() => expect($<HTMLButtonElement>('tester-send').disabled).toBe(false));
};
const type = (el: HTMLInputElement | HTMLTextAreaElement, value: string): void => {
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
};

describe('api-tester console', () => {
  beforeAll(() => {
    const proto = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    proto.showModal = function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    proto.close = function (this: HTMLDialogElement) {
      this.removeAttribute('open');
      this.dispatchEvent(new Event('close'));
    };
  });

  beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  describe('request builder', () => {
    it('previews the URL with the environment variables and the query parameters, and flags unresolved ones', async () => {
      await boot(consoleHtml());

      expect($<HTMLInputElement>('tester-url-display').value).toBe('https://api.test/users/42?q=42');
      expect($('tester-url-unresolved').hidden).toBe(true);
      expect($('tester-method-wrap').className).toContain('as-method-post');

      $<HTMLSelectElement>('tester-environment').value = '2';
      $('tester-environment').dispatchEvent(new Event('change'));
      expect($<HTMLInputElement>('tester-url-display').value).toContain('{{host}}');
      expect($('tester-url-unresolved').hidden).toBe(false);

      $<HTMLSelectElement>('tester-environment').value = '';
      $('tester-environment').dispatchEvent(new Event('change'));
      expect($<HTMLInputElement>('tester-url-display').value).toContain('{{id}}');
    });

    it('styles the method selector for each verb', async () => {
      await boot(consoleHtml());
      const select = $<HTMLSelectElement>('tester-method');

      for (const [value, cls] of [['GET', 'as-method-get'], ['TRACE', 'as-method-default']] as const) {
        select.value = value;
        select.dispatchEvent(new Event('change'));
        expect($('tester-method-wrap').className).toContain(cls);
      }
    });

    it('edits the parameter and header tables', async () => {
      await boot(consoleHtml());
      const params = $('tester-params-body');

      type(params.querySelector<HTMLInputElement>('.as-kv-key')!, 'page');
      type(params.querySelector<HTMLInputElement>('.as-kv-value')!, '2');
      expect($<HTMLInputElement>('tester-url-display').value).toContain('?page=2');
      expect(params.querySelector('.as-resolved-param')!.textContent).toBe('2');

      const enabled = params.querySelector<HTMLInputElement>('.as-kv-enabled')!;
      enabled.checked = false;
      enabled.dispatchEvent(new Event('change', { bubbles: true }));
      expect($<HTMLInputElement>('tester-url-display').value).not.toContain('?');

      $('tester-params-add').click();
      expect(params.querySelectorAll('[data-param-row]')).toHaveLength(2);
      params.querySelector<HTMLInputElement>('.as-kv-remove')?.click();
      params.querySelectorAll<HTMLButtonElement>('.as-kv-remove').forEach((b) => b.click());
      expect(params.querySelectorAll('[data-param-row]')).toHaveLength(1);

      $('tester-headers-add').click();
      type($('tester-headers-body').querySelector<HTMLInputElement>('.as-kv-key')!, 'X-Id');
      type($('tester-headers-body').querySelector<HTMLInputElement>('.as-kv-value')!, '{{id}}');
      expect($('tester-headers-body').querySelector('.as-resolved-header')!.textContent).toBe('42');
      params.dispatchEvent(new Event('input', { bubbles: true }));
      params.dispatchEvent(new Event('change', { bubbles: true }));
      params.click();
    });

    it('switches the response tabs', async () => {
      await boot(consoleHtml());

      document.querySelector<HTMLButtonElement>('[data-response-tab="headers"]')!.click();
      expect(document.querySelector<HTMLElement>('[data-response-panel="headers"]')!.hidden).toBe(false);
      expect(document.querySelector<HTMLElement>('.as-response-body-toolbar')!.hidden).toBe(true);

      document.querySelector<HTMLButtonElement>('[data-response-tab="body"]')!.click();
      expect(document.querySelector<HTMLElement>('.as-response-body-toolbar')!.hidden).toBe(false);
    });

    it('does nothing without a console root and skips the send wiring without a URL', async () => {
      await boot('<p>no console</p>');
      await boot(consoleHtml({ 'data-execute-url': '' }));

      expect($<HTMLInputElement>('tester-url-display').value).toContain('api.test');
    });
  });

  describe('body tools', () => {
    it('formats and validates the request body', async () => {
      await boot(consoleHtml());
      const body = $<HTMLTextAreaElement>('tester-body');
      const validation = $('tester-body-validation');

      $('tester-body-format').click();
      expect(body.value).toBe('{\n  "a": 1\n}\n');
      expect(validation.textContent).toBe('Valid JSON');
      expect(validation.className).toContain('is-valid');

      body.value = '{broken';
      $('tester-body-format').click();
      expect(validation.className).toContain('is-invalid');

      $('tester-body-validate').click();
      expect(validation.className).toContain('is-invalid');

      type(body, '{"b":{{x}}}');
      expect(validation.className).toContain('is-valid');
      expect(validation.textContent).toBe('Valid JSON (with {{variables}})');
    });

    it('formats and validates the response', async () => {
      await boot(consoleHtml());
      const response = $('tester-response');
      const validation = $('tester-response-validation');

      response.textContent = '{"a":1}';
      $('tester-response-format').click();
      expect(response.textContent).toContain('"a": 1');
      expect(validation.className).toContain('is-valid');

      response.textContent = '{nope';
      $('tester-response-format').click();
      expect(validation.className).toContain('is-invalid');
      $('tester-response-validate').click();
      expect(validation.className).toContain('is-invalid');
    });

    it('validates in XML for a SOAP service', async () => {
      await boot(consoleHtml({ 'data-service-protocol': 'soap', 'data-endpoint-content-type': 'text/xml' }));
      const body = $<HTMLTextAreaElement>('tester-body');

      body.value = '<a><b>1</b></a>';
      $('tester-body-validate').click();

      expect($('tester-body-validation').textContent).toBe('Valid XML');
    });

    it('refuses to send an invalid body', async () => {
      const fetchMock = await boot(consoleHtml());
      $<HTMLTextAreaElement>('tester-body').value = '{broken';

      await send();

      expect(fetchMock).not.toHaveBeenCalled();
      expect($('tester-status').textContent).toBe('Invalid body');
      expect(log()).toContain('Request body validation failed');
    });
  });

  describe('sending', () => {
    it('runs the scripts, sends the resolved request and shows the response', async () => {
      const fetchMock = await boot(
        consoleHtml({
          'data-service-pre-script': "pm.environment.set('token', 'abc')",
          'data-scripts-confirm': '0',
          'data-level-scripts': JSON.stringify({ workspace: { pre: "pm.console.log('ws pre')", post: "pm.console.log('ws post')" }, folders: [{ name: 'F', pre: "pm.console.log('f pre')", post: "pm.console.log('f post')" }] }),
        }),
        [{ body: OK }, { body: {} }],
      );
      $<HTMLTextAreaElement>('tester-pre-script').value = "pm.request.setHeader('X-Token', pm.environment.get('token'))";
      $<HTMLTextAreaElement>('tester-post-script').value = "pm.test('created', () => pm.expect(pm.response.code).to.equal(201)); pm.test('broken', () => { throw new Error('nope') })";
      $<HTMLInputElement>('tester-persist-vars').checked = true;
      $<HTMLInputElement>('tester-persist-vars').dispatchEvent(new Event('change'));

      await send();

      const [url, init] = fetchMock.mock.calls[0]! as [string, RequestInit];
      expect(url).toBe('/api-studio/execute/7');
      expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
      const payload = JSON.parse(String(init.body)) as { headers: Record<string, string>; method: string; environment_id: number; variables: Record<string, string> };
      expect(payload.headers['X-Token']).toBe('abc');
      expect(payload.method).toBe('POST');
      expect(payload.environment_id).toBe(1);
      expect(payload.variables.token).toBe('abc');

      expect($('tester-status').textContent).toBe('POST → 201');
      expect($('tester-status').className).toBe('as-status-ok');
      expect($('tester-response').textContent).toContain('"id": 5');
      expect($('tester-response-headers').textContent).toContain('=== Request ===\nA: b');
      expect($('tester-response-headers').textContent).toContain('Multi: a,b');
      expect($('tester-duration').textContent).toBe('7 ms · 8 B');
      expect($<HTMLInputElement>('tester-url-display').value).toBe('https://api.test/users/42');
      expect(log()).toContain('✓ created');
      expect(log()).toContain('✗ broken: nope');
      expect(log()).toContain('Variables persisted to environment.');
      expect(log()).toContain('[workspace pre]');
      expect(JSON.parse(sessionStorage.getItem('nowo_api_studio_runtime_vars_1')!)).toEqual({ token: 'abc' });
      expect(fetchMock.mock.calls[1]![0]).toBe('/env/1/sync');
      expect($<HTMLButtonElement>('tester-save-response').disabled).toBe(false);
    });

    it('reports a variable persistence failure and deletes an emptied override', async () => {
      const fetchMock = await boot(consoleHtml({ 'data-service-pre-script': "pm.environment.set('token', 'abc')" }));
      fetchMock.mockReset();
      fetchMock.mockResolvedValueOnce(reply({ body: OK })).mockRejectedValueOnce(new Error('sync down'));
      sessionStorage.setItem('nowo_api_studio_runtime_vars_1', JSON.stringify({ gone: 'x' }));
      $<HTMLTextAreaElement>('tester-pre-script').value = "pm.environment.set('gone', '')";
      $<HTMLInputElement>('tester-persist-vars').checked = true;

      await send();

      expect(log()).toContain('Failed to persist variables: sync down');
      expect(JSON.parse(sessionStorage.getItem('nowo_api_studio_runtime_vars_1')!)).toEqual({ token: 'abc' });
    });

    it('shows a transport error and an error status', async () => {
      await boot(consoleHtml(), [{ body: { response_status: null, success: false, error_message: 'timeout', request_method: 'POST', response_body: null } }]);

      await send();

      expect($('tester-status').textContent).toBe('POST → ERR · timeout');
      expect($('tester-status').className).toBe('as-status-err');
      expect($('tester-response').textContent).toBe('timeout');
    });

    it('formats the response body without the body tools', async () => {
      await boot(consoleHtml(), [{ body: { ...OK, response_body: 'plain text' } }]);
      delete (window as unknown as Record<string, unknown>).ApiStudioBodyTools;
      await send();
      expect($('tester-response').textContent).toBe('plain text');
    });

    it('fails the request when a pre-request script throws', async () => {
      const fetchMock = await boot(consoleHtml());
      $<HTMLTextAreaElement>('tester-pre-script').value = "throw new Error('bad pre')";

      await send();

      expect(fetchMock).not.toHaveBeenCalled();
      expect($('tester-status').textContent).toBe('Request failed');
      expect($('tester-response').textContent).toContain('bad pre');
      expect(log()).toContain('ERROR: [pre-request] bad pre');
    });

    it('sends one request at a time', async () => {
      const fetchMock = await boot(consoleHtml());

      $('tester-send').click();
      $('tester-send').click();
      await vi.waitFor(() => expect($<HTMLButtonElement>('tester-send').disabled).toBe(false));

      expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('sends a plain request when the method select is missing, without an environment and with a non-REST service', async () => {
      const fetchMock = await boot(consoleHtml({ 'data-service-protocol': 'soap' }));
      $<HTMLSelectElement>('tester-environment').value = '';
      $<HTMLTextAreaElement>('tester-body').value = '<a/>';

      await send();

      const payload = JSON.parse(String((fetchMock.mock.calls[0]![1] as RequestInit).body)) as Record<string, unknown>;
      expect(payload).not.toHaveProperty('method');
      expect(payload).not.toHaveProperty('environment_id');
    });

    it('sends without scripts when scripts are disabled', async () => {
      const fetchMock = await boot(consoleHtml({ 'data-scripts-mode': 'disabled', 'data-service-pre-script': "throw new Error('never runs')" }));

      await send();

      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect($('tester-status').textContent).toBe('POST → 201');
    });
  });

  describe('examples', () => {
    const saved = { id: 3, name: 'Saved', body: '{"a":1}', headers: { H: 'v' }, query_params: { q: '1' } };

    it('saves a request example through the dialog, loads it and deletes it', async () => {
      const fetchMock = await boot(consoleHtml(), [{ body: saved }, { body: {} }]);
      const name = $<HTMLInputElement>('tester-example-name');

      $('tester-example-save').click();
      expect(fetchMock).not.toHaveBeenCalled();

      $('tester-save-request').click();
      expect($<HTMLDialogElement>('tester-example-modal').open).toBe(true);
      name.value = '  Saved ';
      name.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
      name.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
      await vi.waitFor(() => expect(log()).toContain('Request example saved: Saved'));

      expect(JSON.parse(String((fetchMock.mock.calls[0]![1] as RequestInit).body))).toMatchObject({ name: 'Saved', body: '{"a":1}' });
      expect($<HTMLDialogElement>('tester-example-modal').open).toBe(false);
      const list = $('tester-request-examples');
      expect(list.querySelector('.as-examples-empty')).toBeNull();
      const item = list.querySelector<HTMLElement>('.as-example-item')!;
      expect(item.querySelector('strong')!.textContent).toBe('Saved');

      $<HTMLTextAreaElement>('tester-body').value = 'other';
      item.querySelector<HTMLElement>('.as-example-load')!.click();
      expect($<HTMLTextAreaElement>('tester-body').value).toBe('{"a":1}');
      expect($('tester-headers-body').querySelector<HTMLInputElement>('.as-kv-key')!.value).toBe('H');
      expect(log()).toContain('Loaded request example: Saved');

      list.click();
      item.querySelector<HTMLElement>('strong')!.click();
      item.querySelector<HTMLElement>('.as-example-delete')!.click();
      expect($<HTMLDialogElement>('tester-example-delete-modal').open).toBe(true);
      $('tester-example-delete-confirm').click();
      await vi.waitFor(() => expect(list.querySelector('.as-example-item')).toBeNull());
      expect(list.querySelector('.as-examples-empty')).not.toBeNull();
      expect(fetchMock.mock.calls[1]![0]).toBe('/examples/request/3/delete');
    });

    it('logs a failed save and ignores an empty name', async () => {
      await boot(consoleHtml(), [{ ok: false, status: 400, body: { error: 'Name taken' } }, { ok: false, body: {} }]);
      const name = $<HTMLInputElement>('tester-example-name');

      $('tester-save-request').click();
      name.value = '   ';
      $('tester-example-save').click();
      expect(log()).toBe('');

      name.value = 'X';
      $('tester-example-save').click();
      await vi.waitFor(() => expect(log()).toContain('ERROR: Name taken'));
      $('tester-example-save').click();
      await vi.waitFor(() => expect(log()).toContain('Failed to save request example.'));
      $<HTMLDialogElement>('tester-example-modal').close();
      $('tester-example-save').click();
    });

    it('needs a save URL', async () => {
      await boot(consoleHtml({ 'data-save-request-url': '' }));

      $('tester-save-request').click();
      $<HTMLInputElement>('tester-example-name').value = 'X';
      $('tester-example-save').click();

      await vi.waitFor(() => expect(log()).toContain('ERROR: Save URL not configured.'));
    });

    it('saves the last response as an example and deletes it', async () => {
      const fetchMock = await boot(consoleHtml(), [{ body: OK }, { body: { id: 9, name: 'HTTP 201', status_code: 201, response_body: '{"id":5}', response_headers: {} } }, { ok: false, status: 500, body: { error: 'Nope' } }]);
      $('tester-save-response').click();
      expect($<HTMLDialogElement>('tester-example-modal').open).toBe(false);

      await send();
      $('tester-save-response').click();
      expect($<HTMLInputElement>('tester-example-name').value).toBe('HTTP 201');
      $('tester-example-save').click();
      await vi.waitFor(() => expect(log()).toContain('Response example saved: HTTP 201'));

      const list = $('tester-response-examples');
      const item = list.querySelector<HTMLElement>('.as-example-item')!;
      expect(item.querySelector('.as-method')!.textContent).toBe('201');
      list.click();
      item.querySelector<HTMLElement>('.as-example-load')?.click();
      item.querySelector<HTMLElement>('.as-example-delete')!.click();
      $('tester-example-delete-confirm').click();
      await vi.waitFor(() => expect(log()).toContain('ERROR: Nope'));
      expect(fetchMock.mock.calls[2]![0]).toBe('/examples/response/9/delete');
      expect(list.querySelector('.as-example-item')).not.toBeNull();
    });

    it('refuses to save a response example without a response', async () => {
      await boot(consoleHtml({ 'data-save-response-url': '' }));
      $<HTMLButtonElement>('tester-save-response').disabled = false;

      $('tester-save-response').click();
      $('tester-example-delete-confirm').click();

      expect($<HTMLDialogElement>('tester-example-modal').open).toBe(false);
    });

    it('loads the example named by the page', async () => {
      const item = `<div id="x"></div>`;
      await boot(consoleHtml({ 'data-load-request-example': '5' }, item).replace('<p class="as-examples-empty">none</p>', `<div class="as-example-item" data-example-type="request" data-example-id="5" data-example-payload='{"body":"loaded","headers":{},"query_params":{}}'><strong>Five</strong></div>`));

      expect($<HTMLTextAreaElement>('tester-body').value).toBe('loaded');
      expect($('tester-headers-body').querySelectorAll('[data-header-row]')).toHaveLength(1);
    });

    it('loads nothing for an unknown example id', async () => {
      await boot(consoleHtml({ 'data-load-request-example': '99' }));
      expect($<HTMLTextAreaElement>('tester-body').value).toBe('{"a":1}');
    });
  });
});

