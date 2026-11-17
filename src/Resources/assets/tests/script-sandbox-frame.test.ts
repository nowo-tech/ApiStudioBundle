import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { emptyScopes, type RunMessage } from '../src/lib/sandbox';

/**
 * The sandbox frame entry (spec 003, audit RW-M1): it starts the script worker, relays between the worker and the
 * console and takes the network away from itself. The worker class is replaced by a fake; lockDownNetwork() is
 * spied (it is tested on its own in lockdown.test.ts and would remove fetch from this test window).
 */
const fake = vi.hoisted(() => ({
  instances: [] as Array<{ postMessage: ReturnType<typeof vi.fn>; terminate: ReturnType<typeof vi.fn>; onmessage: unknown; onerror: unknown }>,
}));
const lock = vi.hoisted(() => ({ lockDownNetwork: vi.fn() }));

vi.mock('../src/api-script-worker?worker&inline', () => ({
  default: class FakeWorker {
    postMessage = vi.fn();
    terminate = vi.fn();
    onmessage: unknown = null;
    onerror: unknown = null;

    constructor() {
      fake.instances.push(this);
    }
  },
}));
vi.mock('../src/lib/lockdown', () => lock);

const run: RunMessage = {
  v: 1,
  kind: 'run',
  nonce: 'n1',
  scripts: [{ label: 'pre', code: '1' }],
  runTests: false,
  state: { variables: {}, dirty: {}, request: { body: '', headers: {}, queryParams: {} }, response: null, logs: [], tests: [], scopes: emptyScopes() },
};

describe('api-script-sandbox (frame)', () => {
  const listeners: Array<(event: MessageEvent) => void> = [];

  beforeEach(() => {
    fake.instances.length = 0;
    lock.lockDownNetwork.mockClear();
    const add = window.addEventListener.bind(window);
    vi.spyOn(window, 'addEventListener').mockImplementation(((type: string, listener: EventListenerOrEventListenerObject, options?: boolean | AddEventListenerOptions) => {
      if (type === 'message') {
        listeners.push(listener as (event: MessageEvent) => void);
      }
      add(type, listener, options);
    }) as typeof window.addEventListener);
  });

  afterEach(() => {
    for (const listener of listeners.splice(0)) {
      window.removeEventListener('message', listener);
    }
  });

  it('starts the worker before it locks the frame down, tells the console when the worker is ready and relays one run', async () => {
    const toParent = vi.spyOn(window.parent, 'postMessage').mockImplementation(() => undefined);
    vi.resetModules();
    await import('../src/api-script-sandbox');

    expect(fake.instances).toHaveLength(1);
    expect(lock.lockDownNetwork).toHaveBeenCalledWith(window);
    const worker = fake.instances[0]!;
    (worker.onmessage as (e: MessageEvent) => void)(new MessageEvent('message', { data: { v: 1, kind: 'ready' } }));
    expect(toParent).toHaveBeenCalledWith({ v: 1, kind: 'ready' }, '*');

    window.dispatchEvent(new MessageEvent('message', { data: run, source: window.parent }));
    expect(worker.postMessage).toHaveBeenCalledWith(run);
  });

  it('ignores messages that do not come from the console', async () => {
    vi.spyOn(window.parent, 'postMessage').mockImplementation(() => undefined);
    vi.resetModules();
    await import('../src/api-script-sandbox');
    const worker = fake.instances[0]!;
    (worker.onmessage as (e: MessageEvent) => void)(new MessageEvent('message', { data: { v: 1, kind: 'ready' } }));

    window.dispatchEvent(new MessageEvent('message', { data: run, source: null }));
    window.dispatchEvent(new MessageEvent('message', { data: run, source: {} as MessageEventSource }));

    expect(worker.postMessage).not.toHaveBeenCalled();
  });
});
