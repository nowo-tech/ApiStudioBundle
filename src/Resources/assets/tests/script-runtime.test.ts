import { beforeEach, describe, expect, it, vi } from 'vitest';

type Runtime = NonNullable<typeof window.ApiStudioScriptRuntime>;

async function load(): Promise<Runtime> {
  vi.resetModules();
  await import('../src/api-script-runtime');

  return window.ApiStudioScriptRuntime as Runtime;
}

describe('api-script-runtime', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });

  it('keys the runtime overrides per environment', async () => {
    const runtime = await load();

    expect(runtime.storageKey('env-1')).toBe('nowo_api_studio_runtime_vars_env-1');
  });

  it('saves and loads the overrides, and ignores a missing environment or broken storage', async () => {
    const runtime = await load();

    runtime.saveRuntimeOverrides('e', { a: '1' });
    expect(runtime.loadRuntimeOverrides('e')).toEqual({ a: '1' });
    runtime.saveRuntimeOverrides('', { a: '2' });
    expect(runtime.loadRuntimeOverrides('')).toEqual({});
    expect(runtime.loadRuntimeOverrides('other')).toEqual({});

    sessionStorage.setItem(runtime.storageKey('bad'), '{broken');
    expect(runtime.loadRuntimeOverrides('bad')).toEqual({});
  });

  it('runs a script against a context and reports success, empty scripts and failures', async () => {
    const runtime = await load();
    const pm = runtime.createPm({
      environment: {},
      globals: {},
      collectionVariables: {},
      request: { url: 'http://x', method: 'GET', headers: {}, body: '' },
      response: null,
    } as never);
    const ctx = { pm: pm.pm, getLogs: () => ['log'] } as never;

    expect(runtime.runScriptSafe('', ctx)).toEqual({ ok: true, logs: ['log'] });
    expect(runtime.runScriptSafe('   ', ctx)).toEqual({ ok: true, logs: ['log'] });
    expect(runtime.runScriptSafe('1 + 1', ctx, 'pre')).toEqual({ ok: true, logs: ['log'], label: 'pre' });
    const failed = runtime.runScriptSafe('throw new Error("boom")', ctx, 'post');
    expect(failed).toMatchObject({ ok: false, error: 'boom', label: 'post' });
    expect(runtime.runScriptSafe('throw "text"', ctx)).toMatchObject({ ok: false, error: 'text' });
  });
});
