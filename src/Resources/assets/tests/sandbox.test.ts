import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  emptyScopes,
  executeRun,
  isRunMessage,
  MAX_CODE_LENGTH,
  MAX_SEND_BUDGET,
  MAX_SEND_REQUESTS,
  normalizeRequest,
  parseLevelScripts,
  parseScopes,
  runInSandbox,
  sandboxContext,
  type RunMessage,
  type SandboxHttpRequest,
  type SandboxState,
} from '../src/lib/sandbox';

/** Spec 003 (1.15): sandboxed script runner protocol, frame execution and page-side runner. */
const state = (): SandboxState => ({
  variables: { token: 'abc' },
  dirty: {},
  request: { body: '{}', headers: { Accept: 'application/json' }, queryParams: {} },
  response: null,
  logs: [],
  tests: [],
  scopes: emptyScopes(),
});

const run = (scripts: RunMessage['scripts'], runTests = false, st: SandboxState = state()): RunMessage => ({
  v: 1,
  kind: 'run',
  nonce: 'n1',
  scripts,
  state: st,
  runTests,
});

describe('sandbox protocol', () => {
  it('accepts only well-formed run messages within the size limit', () => {
    expect(isRunMessage(run([{ label: 'pre', code: '1' }]))).toBe(true);
    expect(isRunMessage(null)).toBe(false);
    expect(isRunMessage({ ...run([]), v: 2 })).toBe(false);
    expect(isRunMessage({ ...run([]), kind: 'result' })).toBe(false);
    expect(isRunMessage({ ...run([{ label: 'pre', code: 1 as unknown as string }]) })).toBe(false);
    expect(isRunMessage({ ...run([]), state: { ...state(), variables: { a: 1 } } })).toBe(false);
    expect(isRunMessage(run([{ label: 'pre', code: 'x'.repeat(MAX_CODE_LENGTH + 1) }]))).toBe(false);
  });

  it('runs the scripts with a pm built from the state and returns the new state', async () => {
    const result = await executeRun(
      run([
        { label: 'service pre', code: "pm.environment.set('id', '7'); pm.request.setHeader('X-Id', pm.environment.get('id')); pm.console.log('one')" },
        { label: 'pre-request', code: "pm.console.log('two', pm.environment.get('token'))" },
      ]),
    );

    expect(result.ok).toBe(true);
    expect(result.nonce).toBe('n1');
    expect(result.state.variables).toEqual({ token: 'abc', id: '7' });
    expect(result.state.dirty).toEqual({ id: '7' });
    expect(result.state.request.headers).toEqual({ Accept: 'application/json', 'X-Id': '7' });
    expect(result.state.logs).toEqual(['[service pre] one', '[pre-request] two abc']);
  });

  it('stops at the first failing script and reports it', async () => {
    const result = await executeRun(
      run([
        { label: 'a', code: "pm.console.log('before'); throw new Error('boom')" },
        { label: 'b', code: "pm.environment.set('never', '1')" },
      ]),
    );

    expect(result.ok).toBe(false);
    expect(result.error).toBe('boom');
    expect(result.label).toBe('a');
    expect(result.state.logs).toEqual(['[a] before']);
    expect(result.state.variables.never).toBeUndefined();
  });

  it('runs tests against the response in the post phase', async () => {
    const st = { ...state(), response: { code: 201, body: '{"ok":true}' } };
    const result = await executeRun(
      run([{ label: 'post', code: "pm.test('created', () => pm.expect(pm.response.code).to.equal(201)); pm.test('ok', () => pm.expect(pm.response.json().ok).to.equal(false))" }, { label: 'empty', code: '  ' }], true, st),
    );

    expect(result.state.tests).toEqual([
      { name: 'created', ok: true },
      { name: 'ok', ok: false, error: 'Expected false but got true' },
    ]);
  });
});

describe('page-side sandbox runner', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
  });

  function frame(): HTMLIFrameElement {
    return document.querySelector('iframe.as-script-sandbox') as HTMLIFrameElement;
  }

  function fromFrame(data: unknown, source: Window | null = frame().contentWindow, origin = 'null'): void {
    window.dispatchEvent(new MessageEvent('message', { data, source, origin }));
  }

  it('loads an opaque-origin frame, posts the run after ready and resolves with the matching result', async () => {
    const promise = runInSandbox('/api-studio/scripts/sandbox', [{ label: 'pre', code: '1' }], state(), false, 1000);
    const iframe = frame();
    expect(iframe.getAttribute('sandbox')).toBe('allow-scripts');
    expect(iframe.getAttribute('src')).toBe('/api-studio/scripts/sandbox');

    const posted: RunMessage[] = [];
    vi.spyOn(iframe.contentWindow as Window, 'postMessage').mockImplementation((message: unknown) => {
      posted.push(message as RunMessage);
    });
    fromFrame({ v: 1, kind: 'ready' });
    expect(posted).toHaveLength(1);
    const nonce = posted[0]!.nonce;

    // Foreign windows, other origins and other nonces are ignored.
    fromFrame({ v: 1, kind: 'result', nonce, ok: true, state: state() }, window);
    fromFrame({ v: 1, kind: 'result', nonce, ok: true, state: state() }, iframe.contentWindow, 'https://evil.test');
    fromFrame({ v: 1, kind: 'result', nonce: 'other', ok: true, state: state() });
    expect(frame()).not.toBeNull();

    fromFrame(await executeRun({ ...posted[0]!, scripts: [{ label: 'pre', code: "pm.environment.set('x', '1')" }] }));
    const result = await promise;
    expect(result.state.variables.x).toBe('1');
    expect(frame()).toBeNull();
  });

  it('tears the frame down and rejects when the time limit is reached', async () => {
    vi.useFakeTimers();
    const promise = runInSandbox('/sandbox', [{ label: 'pre', code: 'while(true){}' }], state(), false, 500);
    vi.advanceTimersByTime(501);

    await expect(promise).rejects.toThrow('500 ms');
    expect(frame()).toBeNull();
  });

  it('exposes the state as a console script context', () => {
    const ctx = sandboxContext(state());
    ctx.setResponse(200, 'ok');
    ctx.state = { ...ctx.state, request: { ...ctx.state.request, body: 'changed' }, dirty: { a: '1' }, logs: ['l'], tests: [{ name: 't', ok: true }] };

    expect(ctx.pm.request.body).toBe('changed');
    expect(ctx.getVariables()).toEqual({ token: 'abc' });
    expect(ctx.getDirtyKeys()).toEqual({ a: '1' });
    expect(ctx.getLogs()).toEqual(['l']);
    expect(ctx.runTests()).toEqual([{ name: 't', ok: true }]);
    expect(ctx.state.response).toEqual({ code: 200, body: 'ok', headers: {}, responseTime: 0 });
  });
});

describe('pm.sendRequest (sandbox)', () => {
  const ok = { code: 200, headers: { 'Content-Type': 'application/json' }, body: '{"token":"t1"}', responseTime: 3 };

  it('normalizes Postman request shapes', () => {
    expect(normalizeRequest('https://api.test/a')).toEqual({ url: 'https://api.test/a', method: 'GET', header: {}, body: null });
    expect(normalizeRequest({ url: 'https://api.test/b', method: 'post', header: [{ key: 'X-A', value: 1 }], body: { mode: 'raw', raw: '{}' } })).toEqual({
      url: 'https://api.test/b',
      method: 'POST',
      header: { 'X-A': '1' },
      body: '{}',
    });
    expect(normalizeRequest({ url: 'https://api.test/c', header: { 'X-B': 'b' }, body: 'x' }).header).toEqual({ 'X-B': 'b' });
    expect(() => normalizeRequest(42)).toThrow('URL or a request object');
  });

  it('waits for callbacks and promises before the next script and the result', async () => {
    const sent: SandboxHttpRequest[] = [];
    const transport = vi.fn(async (r: SandboxHttpRequest) => {
      sent.push(r);

      return ok;
    });
    const result = await executeRun(
      run([
        {
          label: 'pre',
          code: "pm.sendRequest('https://auth.test/token', (err, res) => { pm.environment.set('token', res.json().token); pm.console.log(res.code, res.headers.get('content-type')); })",
        },
        { label: 'next', code: "pm.request.setHeader('Authorization', 'Bearer ' + pm.environment.get('token'))" },
      ]),
      transport,
    );

    expect(sent).toEqual([{ url: 'https://auth.test/token', method: 'GET', header: {}, body: null }]);
    expect(result.ok).toBe(true);
    expect(result.state.variables.token).toBe('t1');
    expect(result.state.request.headers.Authorization).toBe('Bearer t1');
    expect(result.state.logs).toEqual(['[pre] 200 application/json']);
  });

  it('reports transport errors to the callback, callback errors as failures and enforces the budget', async () => {
    const failing = await executeRun(run([{ label: 'pre', code: "pm.sendRequest('https://x.test', (err) => pm.console.log('err:', err.message))" }]), async () => {
      throw new Error('not allowed (SSRF or allowlist)');
    });
    expect(failing.ok).toBe(true);
    expect(failing.state.logs).toEqual(['[pre] err: not allowed (SSRF or allowlist)']);

    const throwing = await executeRun(run([{ label: 'pre', code: "pm.sendRequest('https://x.test', () => { throw new Error('in callback') })" }]), async () => ok);
    expect(throwing.ok).toBe(false);
    expect(throwing.error).toBe('in callback');

    const many = await executeRun(run([{ label: 'pre', code: `for (let i = 0; i <= ${MAX_SEND_REQUESTS}; i++) pm.sendRequest('https://x.test/' + i)` }]), async () => ok);
    expect(many.ok).toBe(false);
    expect(many.error).toContain(`at most ${MAX_SEND_REQUESTS}`);

    const none = await executeRun(run([{ label: 'pre', code: "pm.sendRequest('https://x.test')" }]));
    expect(none.error).toContain('scripts.mode: sandbox');

    // scripts.limits.send_requests travels with the run message.
    const two = await executeRun({ ...run([{ label: 'pre', code: "for (let i = 0; i < 3; i++) pm.sendRequest('https://x.test/' + i)" }]), sendBudget: 2 }, async () => ok);
    expect(two.error).toContain('at most 2');
    expect(isRunMessage({ ...run([]), sendBudget: 2 })).toBe(true);
    expect(isRunMessage({ ...run([]), sendBudget: -1 })).toBe(false);
    expect(isRunMessage({ ...run([]), sendBudget: 1.5 })).toBe(false);
    expect(isRunMessage({ ...run([]), sendBudget: MAX_SEND_BUDGET + 1 })).toBe(false);
  });

  it('relays the frame request through the page and answers the frame', async () => {
    const send = vi.fn(async () => ok);
    const promise = runInSandbox('/sandbox', [{ label: 'pre', code: '1' }], state(), false, 1000, send);
    const iframe = document.querySelector('iframe.as-script-sandbox') as HTMLIFrameElement;
    const posted: unknown[] = [];
    vi.spyOn(iframe.contentWindow as Window, 'postMessage').mockImplementation((m: unknown) => {
      posted.push(m);
    });
    const source = iframe.contentWindow;
    window.dispatchEvent(new MessageEvent('message', { data: { v: 1, kind: 'ready' }, source, origin: 'null' }));
    const nonce = (posted[0] as RunMessage).nonce;

    window.dispatchEvent(new MessageEvent('message', { data: { v: 1, kind: 'sendRequest', nonce, id: 4, request: { url: 'https://a.test', method: 'GET', header: {}, body: null } }, source, origin: 'null' }));
    await vi.waitFor(() => expect(posted).toHaveLength(2));
    expect(send).toHaveBeenCalledWith({ url: 'https://a.test', method: 'GET', header: {}, body: null });
    expect(posted[1]).toEqual({ v: 1, kind: 'sendResult', nonce, id: 4, response: ok });

    // Another nonce is ignored; without a transport the frame is told it is not available.
    window.dispatchEvent(new MessageEvent('message', { data: { v: 1, kind: 'sendRequest', nonce: 'other', id: 5, request: {} }, source, origin: 'null' }));
    expect(send).toHaveBeenCalledTimes(1);

    window.dispatchEvent(new MessageEvent('message', { data: { v: 1, kind: 'result', nonce, ok: true, state: state() }, source, origin: 'null' }));
    await promise;
    document.body.innerHTML = '';
  });
});

describe('workspace / folder scripts', () => {
  it('parses the console attribute and ignores anything malformed', () => {
    expect(parseLevelScripts('{"workspace":{"pre":"a","post":"b"},"folders":[{"name":"F","pre":"c","post":1},null]}')).toEqual({
      workspace: { pre: 'a', post: 'b' },
      folders: [{ name: 'F', pre: 'c', post: '' }],
    });
    expect(parseLevelScripts(undefined)).toEqual({ workspace: { pre: '', post: '' }, folders: [] });
    expect(parseLevelScripts('not json')).toEqual({ workspace: { pre: '', post: '' }, folders: [] });
    expect(parseLevelScripts('[1]').folders).toEqual([]);
  });
});

describe('pm.globals / pm.collectionVariables', () => {
  it('reads, writes and records the changes of each scope across phases', async () => {
    const st = state();
    st.scopes = { globals: { g: '1', both: 'global' }, collection: { c: '2', both: 'collection' }, changes: { globals: { old: null }, collection: {} } };
    const result = await executeRun(
      run(
        [
          {
            label: 'pre',
            code: [
              "pm.console.log(pm.globals.get('g'), pm.collectionVariables.get('c'), pm.variables.get('both'), pm.variables.get('token'))",
              "pm.console.log(pm.globals.has('g'), pm.globals.has('nope'), pm.globals.get('toString'))",
              "pm.globals.set('g', 3); pm.collectionVariables.unset('c'); pm.collectionVariables.set('n', null)",
              "pm.console.log(JSON.stringify(pm.variables.toObject()))",
            ].join(';'),
          },
        ],
        false,
        st,
      ),
    );

    expect(result.ok).toBe(true);
    expect(result.state.logs).toEqual([
      '[pre] 1 2 collection abc',
      '[pre] true false undefined',
      '[pre] {"g":"3","both":"collection","n":"","token":"abc"}',
    ]);
    expect(result.state.scopes).toEqual({
      globals: { g: '3', both: 'global' },
      collection: { both: 'collection', n: '' },
      changes: { globals: { old: null, g: '3' }, collection: { c: null, n: '' } },
    });
  });

  it('clears a scope', async () => {
    const st = state();
    st.scopes = { ...emptyScopes(), globals: { a: '1', b: '2' } };
    const result = await executeRun(run([{ label: 'pre', code: 'pm.globals.clear()' }], false, st));
    expect(result.state.scopes.globals).toEqual({});
    expect(result.state.scopes.changes.globals).toEqual({ a: null, b: null });
  });

  it('refuses a run message with malformed scopes', () => {
    expect(isRunMessage({ ...run([]), state: { ...state(), scopes: undefined } })).toBe(false);
    expect(isRunMessage({ ...run([]), state: { ...state(), scopes: { ...emptyScopes(), globals: { a: 1 } } } })).toBe(false);
    expect(isRunMessage({ ...run([]), state: { ...state(), scopes: { ...emptyScopes(), changes: null } } })).toBe(false);
    expect(isRunMessage({ ...run([]), state: { ...state(), scopes: { ...emptyScopes(), changes: { globals: { a: 1 }, collection: {} } } } })).toBe(false);
    expect(isRunMessage({ ...run([]), state: { ...state(), scopes: 'x' } })).toBe(false);
  });

  it('parses the console attribute, PHP empty arrays included', () => {
    expect(parseScopes('{"globals":{"a":"1"},"collection":[]}')).toEqual({ ...emptyScopes(), globals: { a: '1' } });
    expect(parseScopes('{"globals":{"a":1}}')).toEqual(emptyScopes());
    expect(parseScopes('nope')).toEqual(emptyScopes());
    expect(parseScopes('7')).toEqual(emptyScopes());
    expect(parseScopes(undefined)).toEqual(emptyScopes());
  });
});
