import { afterEach, describe, expect, it, vi } from 'vitest';
import { executeRun, isRunMessage, MAX_CODE_LENGTH, runInSandbox, sandboxContext, type RunMessage, type SandboxState } from '../src/lib/sandbox';

/** Spec 003 (1.15): sandboxed script runner protocol, frame execution and page-side runner. */
const state = (): SandboxState => ({
  variables: { token: 'abc' },
  dirty: {},
  request: { body: '{}', headers: { Accept: 'application/json' }, queryParams: {} },
  response: null,
  logs: [],
  tests: [],
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

  it('runs the scripts with a pm built from the state and returns the new state', () => {
    const result = executeRun(
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

  it('stops at the first failing script and reports it', () => {
    const result = executeRun(
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

  it('runs tests against the response in the post phase', () => {
    const st = { ...state(), response: { code: 201, body: '{"ok":true}' } };
    const result = executeRun(
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

    fromFrame(executeRun({ ...posted[0]!, scripts: [{ label: 'pre', code: "pm.environment.set('x', '1')" }] }));
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
    expect(ctx.state.response).toEqual({ code: 200, body: 'ok' });
  });
});
