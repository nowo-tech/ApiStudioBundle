/**
 * Every shipped browser entry logs the house "script loaded" line exactly once when it is evaluated. The entries are
 * IIFE scripts with top-level side effects, so each one is imported on an empty document with a fresh module registry
 * (the sandbox frame gets a fake worker and a stubbed network lockdown, as in script-sandbox-frame.test.ts).
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../src/api-script-worker?worker&inline', () => ({
  default: class FakeWorker {
    postMessage = vi.fn();
    terminate = vi.fn();
    onmessage: unknown = null;
    onerror: unknown = null;
  },
}));
vi.mock('../src/lib/lockdown', () => ({ lockDownNetwork: vi.fn() }));

const ENTRIES = [
  ['../src/api-body-tools', 'api-studio/body-tools'],
  ['../src/api-endpoint-doc', 'api-studio/endpoint-doc'],
  ['../src/api-script-runtime', 'api-studio/script-runtime'],
  ['../src/api-script-sandbox', 'api-studio/script-sandbox'],
  ['../src/api-studio-shell', 'api-studio/studio-shell'],
  ['../src/api-tester', 'api-studio/tester'],
] as const;

describe.each(ENTRIES)('%s', (entry, name) => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.resetModules();
  });

  it('logs "script loaded" once, without a build id when none is injected', async () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

    await import(/* @vite-ignore */ entry);

    const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
    expect(lines).toHaveLength(1);
    expect(lines[0]).toContain(`[${name}] script loaded`);
    expect(lines[0]).not.toContain('build: ');
  });

  it('includes the injected build id', async () => {
    vi.stubGlobal('__API_STUDIO_BUILD_TIME__', 'abc123def456');
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

    await import(/* @vite-ignore */ entry);

    const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
    expect(lines).toHaveLength(1);
    expect(lines[0]).toContain(`[${name}] script loaded, build: `);
    expect(JSON.stringify(log.mock.calls)).toContain('abc123def456');
  });
});
