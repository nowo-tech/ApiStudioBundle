import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  emptyScopes,
  executeRun,
  isResultMessage,
  isRunMessage,
  MAX_MESSAGE_GROWTH,
  runInSandbox,
  scriptResponse,
  type RunMessage,
  type SandboxState,
} from '../src/lib/sandbox';
import { createPm, runScript } from '../src/lib/pm';

/** Spec 003 (1.15): pm.response helpers and the page-side bounds on what the untrusted frame sends back. */
const state = (): SandboxState => ({
  variables: {},
  dirty: {},
  request: { body: '', headers: {}, queryParams: {} },
  response: null,
  logs: [],
  tests: [],
  scopes: emptyScopes(),
});

const run = (code: string, st: SandboxState, runTests = true): RunMessage => ({ v: 1, kind: 'run', nonce: 'n', scripts: [{ label: 'post', code }], state: st, runTests });

describe('pm.response helpers', () => {
  const response = { code: 201, body: '{"items":[1,2,3]}', headers: { 'Content-Type': 'application/json', 'X-Trace': 'abc' }, responseTime: 42 };

  it('exposes masked headers, the round trip and Postman assertions to post scripts', async () => {
    const code = `
      pm.test('status', () => pm.response.to.have.status(201));
      pm.test('ok', () => pm.response.to.be.ok);
      pm.test('header', () => pm.response.to.have.header('content-type', 'application/json'));
      pm.test('json', () => { pm.response.to.be.json; pm.response.to.have.jsonBody(); });
      pm.test('body', () => pm.response.to.have.body());
      pm.test('not error', () => pm.response.to.not.be.error);
      pm.test('time', () => pm.expect(pm.response.responseTime).to.be.below(100));
      pm.test('get', () => pm.expect(pm.response.headers.get('x-trace')).to.equal('abc'));
      pm.test('has', () => pm.expect(pm.response.headers.has('X-TRACE') && !pm.response.headers.has('nope')).to.be.true);
      pm.test('items', () => pm.expect(pm.response.json().items).to.have.lengthOf(3));
      pm.test('wrong status', () => pm.response.to.have.status(200));
      pm.test('missing header', () => pm.response.to.have.header('X-None'));
      pm.test('client error', () => pm.response.to.be.clientError);
      pm.test('not ok', () => pm.response.to.not.be.ok);
    `;
    const result = await executeRun(run(code, { ...state(), response }));
    const failed = result.state.tests.filter((t) => !t.ok).map((t) => `${t.name}: ${t.error}`);

    expect(result.state.tests.filter((t) => t.ok).map((t) => t.name)).toEqual(['status', 'ok', 'header', 'json', 'body', 'not error', 'time', 'get', 'has', 'items']);
    expect(failed).toEqual([
      'wrong status: Expected status 200 but got 201',
      'missing header: Expected header X-None',
      'client error: Expected a 4xx status but got 201',
      'not ok: Expected not: a 2xx status but got 201',
    ]);
    expect(result.state.response).toEqual(response);
  });

  it('gives the inline runtime the same helpers', () => {
    const ctx = createPm({});
    ctx.setResponse(500, 'oops', { 'Retry-After': '3' }, 7);
    runScript("pm.test('t', () => { pm.response.to.be.serverError; pm.response.to.have.header('retry-after', 3); pm.expect(pm.response.responseTime).to.equal(7); pm.response.to.not.have.jsonBody(); pm.response.to.have.status('500'); })", ctx.pm);

    expect(ctx.runTests()).toEqual([{ name: 't', ok: true }]);
    ctx.setResponse(null, '');
    expect(ctx.pm.response.headers.toObject()).toEqual({});
    expect(ctx.pm.response.responseTime).toBe(0);
  });

  it('adds the assertions to pm.sendRequest responses', () => {
    const r = scriptResponse({ code: 404, headers: { Server: 'x' }, body: 'nf', responseTime: 1 });
    expect(() => r.to.be.clientError).not.toThrow();
    expect(() => r.to.have.body('nf')).not.toThrow();
    expect(() => r.to.be.success).toThrow('Expected a 2xx status but got 404');
    expect(r.headers.get('server')).toBe('x');
  });

  it('refuses a run message whose response is malformed', () => {
    expect(isRunMessage(run('1', { ...state(), response }))).toBe(true);
    expect(isRunMessage(run('1', { ...state(), response: { ...response, headers: { a: 1 } as unknown as Record<string, string> } }))).toBe(false);
    expect(isRunMessage(run('1', { ...state(), response: { ...response, responseTime: Number.NaN } }))).toBe(false);
    expect(isRunMessage(run('1', { ...state(), response: { code: {}, body: '' } as unknown as SandboxState['response'] }))).toBe(false);
    expect(isRunMessage(run('1', { ...state(), logs: [1] as unknown as string[] }))).toBe(false);
  });
});

describe('result messages from the frame', () => {
  const result = (st: unknown = state()) => ({ v: 1, kind: 'result', nonce: 'n', ok: true, state: st });

  it('accepts only well-formed results of the run within the size bound', () => {
    expect(isResultMessage(result(), 'n', 10_000)).toBe(true);
    expect(isResultMessage(result(), 'other', 10_000)).toBe(false);
    expect(isResultMessage({ ...result(), ok: 'yes' }, 'n', 10_000)).toBe(false);
    expect(isResultMessage({ ...result(), error: 3 }, 'n', 10_000)).toBe(false);
    expect(isResultMessage({ ...result(), label: {} }, 'n', 10_000)).toBe(false);
    expect(isResultMessage(result({ ...state(), request: { body: '', headers: { a: [] }, queryParams: {} } }), 'n', 10_000)).toBe(false);
    expect(isResultMessage(result({ ...state(), tests: [{ name: 't', ok: 'y' }] }), 'n', 10_000)).toBe(false);
    expect(isResultMessage(result({ ...state(), scopes: { globals: {}, collection: {} } }), 'n', 10_000)).toBe(false);
    expect(isResultMessage(result({ ...state(), logs: ['x'.repeat(500)] }), 'n', 400)).toBe(false);
    const cyclic: Record<string, unknown> = { ...result() };
    (cyclic.state as Record<string, unknown>).self = cyclic;
    expect(isResultMessage(cyclic, 'n', 10_000)).toBe(false);
  });
});

describe('page-side bounds', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  function start(sendBudget = 1, send?: () => Promise<never>) {
    const promise = runInSandbox('/sandbox', [{ label: 'pre', code: '1' }], state(), false, 200, send, sendBudget);
    promise.catch(() => undefined);
    const iframe = document.querySelector('iframe.as-script-sandbox') as HTMLIFrameElement;
    const posted: RunMessage[] = [];
    vi.spyOn(iframe.contentWindow as Window, 'postMessage').mockImplementation((m: unknown) => {
      posted.push(m as RunMessage);
    });
    const post = (data: unknown): void => {
      window.dispatchEvent(new MessageEvent('message', { data, source: iframe.contentWindow, origin: 'null' }));
    };

    return { promise, posted, post, iframe };
  }

  it('posts the run once, however many times the frame says ready', async () => {
    const { promise, posted, post } = start();
    post({ v: 1, kind: 'ready' });
    post({ v: 1, kind: 'ready' });
    expect(posted).toHaveLength(1);
    post({ v: 1, kind: 'result', nonce: posted[0]!.nonce, ok: true, state: state() });
    await expect(promise).resolves.toMatchObject({ ok: true });
  });

  it('ignores a result before the run was posted', () => {
    const { post } = start();
    post({ v: 1, kind: 'result', nonce: 'guess', ok: true, state: state() });
    expect(document.querySelector('iframe.as-script-sandbox')).not.toBeNull();
  });

  it('tears the frame down on a malformed result', async () => {
    const { promise, posted, post } = start();
    post({ v: 1, kind: 'ready' });
    post({ v: 1, kind: 'result', nonce: posted[0]!.nonce, ok: true, state: { ...state(), variables: { a: 1 } } });
    await expect(promise).rejects.toThrow('malformed or oversized result');
    expect(document.querySelector('iframe.as-script-sandbox')).toBeNull();
  });

  it('tears the frame down when the result grows the state by more than the bound', async () => {
    const { promise, posted, post } = start();
    post({ v: 1, kind: 'ready' });
    post({ v: 1, kind: 'result', nonce: posted[0]!.nonce, ok: true, state: { ...state(), logs: ['x'.repeat(MAX_MESSAGE_GROWTH + 1000)] } });
    await expect(promise).rejects.toThrow('malformed or oversized result');
  });

  it('refuses oversized sendRequest messages and stops a message flood', async () => {
    const send = vi.fn(async (): Promise<never> => {
      throw new Error('unused');
    });
    const { promise, posted, post } = start(1, send);
    post({ v: 1, kind: 'ready' });
    const nonce = posted[0]!.nonce;
    post({ v: 1, kind: 'sendRequest', nonce, id: 1, request: { url: 'https://a.test', body: 'x'.repeat(MAX_MESSAGE_GROWTH + 1) } });
    expect(send).not.toHaveBeenCalled();
    expect(posted[1]).toMatchObject({ kind: 'sendResult', id: 1, error: 'pm.sendRequest is not available.' });

    // ready + its budget of sendRequest + result: one more message is a flood.
    post({ v: 1, kind: 'log' });
    expect(document.querySelector('iframe.as-script-sandbox')).not.toBeNull();
    post({ v: 1, kind: 'log' });
    await expect(promise).rejects.toThrow('too many messages');
    expect(document.querySelector('iframe.as-script-sandbox')).toBeNull();
  });
});
