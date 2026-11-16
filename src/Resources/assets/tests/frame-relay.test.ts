import { afterEach, describe, expect, it, vi } from 'vitest';
import { createFrameRelay, type RelayWorker } from '../src/lib/frame-relay';
import { emptyScopes, runInSandbox, type RunMessage } from '../src/lib/sandbox';

/** Spec 003 (1.15), audit RW-M1: the frame only relays between the console and the script worker. */
function fakeWorker() {
  const posted: unknown[] = [];
  const worker = {
    postMessage: vi.fn((m: unknown) => posted.push(m)),
    terminate: vi.fn(),
    onmessage: null,
    onerror: null,
  } as unknown as RelayWorker & { postMessage: ReturnType<typeof vi.fn>; terminate: ReturnType<typeof vi.fn> };
  const say = (data: unknown): void => (worker.onmessage as unknown as (e: MessageEvent) => void)(new MessageEvent('message', { data }));

  return { worker, posted, say };
}

const run: RunMessage = {
  v: 1,
  kind: 'run',
  nonce: 'n1',
  scripts: [{ label: 'pre', code: '1' }],
  runTests: false,
  state: { variables: {}, dirty: {}, request: { body: '', headers: {}, queryParams: {} }, response: null, logs: [], tests: [], scopes: emptyScopes() },
};

describe('frame relay', () => {
  it('says ready only after the worker, passes one run, relays sendRequest / sendResult of that run and stops at the result', () => {
    const { worker, posted, say } = fakeWorker();
    const parent: unknown[] = [];
    const fromParent = createFrameRelay(worker, (m) => parent.push(m));

    fromParent(run);
    expect(posted).toEqual([]); // the worker is not ready yet
    say({ v: 1, kind: 'ready' });
    say({ v: 1, kind: 'ready' });
    expect(parent).toEqual([{ v: 1, kind: 'ready' }]);

    fromParent({ ...run, scripts: 'bad' });
    fromParent(run);
    fromParent({ ...run, nonce: 'n2' });
    expect(posted).toEqual([run]);

    say({ v: 1, kind: 'sendRequest', nonce: 'other', id: 1 });
    say({ v: 1, kind: 'navigate', nonce: 'n1' });
    say({ v: 1, kind: 'sendRequest', nonce: 'n1', id: 1, request: {} });
    fromParent({ v: 1, kind: 'sendResult', nonce: 'other', id: 1 });
    fromParent({ v: 1, kind: 'sendResult', nonce: 'n1', id: 1, error: 'x' });
    expect(parent).toHaveLength(2);
    expect(posted).toHaveLength(2);

    say({ v: 1, kind: 'result', nonce: 'n1', ok: true });
    expect(parent[2]).toEqual({ v: 1, kind: 'result', nonce: 'n1', ok: true });
    expect(worker.terminate).toHaveBeenCalled();
    say({ v: 1, kind: 'result', nonce: 'n1', ok: false });
    expect(parent).toHaveLength(3);
  });

  it('reports a crashed worker as an error of the run', () => {
    const { worker, say } = fakeWorker();
    const parent: unknown[] = [];
    const fromParent = createFrameRelay(worker, (m) => parent.push(m));
    say({ v: 1, kind: 'ready' });
    fromParent(run);
    (worker.onerror as unknown as (e: Event) => void)(new Event('error'));

    expect(parent[1]).toEqual({ v: 1, kind: 'error', nonce: 'n1' });
    expect(worker.terminate).toHaveBeenCalled();
  });
});

describe('page-side runner against a navigating or failing frame', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('fails the run when the frame document loads a second time', async () => {
    const promise = runInSandbox('/sandbox', [{ label: 'pre', code: '1' }], run.state, false, 5000);
    const iframe = document.querySelector('iframe.as-script-sandbox') as HTMLIFrameElement;
    iframe.dispatchEvent(new Event('load'));
    expect(document.querySelector('iframe.as-script-sandbox')).not.toBeNull();
    iframe.dispatchEvent(new Event('load'));

    await expect(promise).rejects.toThrow('the sandbox frame navigated');
    expect(document.querySelector('iframe.as-script-sandbox')).toBeNull();
  });

  it('fails the run when the frame reports a failed worker', async () => {
    const promise = runInSandbox('/sandbox', [{ label: 'pre', code: '1' }], run.state, false, 5000);
    const iframe = document.querySelector('iframe.as-script-sandbox') as HTMLIFrameElement;
    const posted: RunMessage[] = [];
    vi.spyOn(iframe.contentWindow as Window, 'postMessage').mockImplementation((m: unknown) => {
      posted.push(m as RunMessage);
    });
    const post = (data: unknown): void => {
      window.dispatchEvent(new MessageEvent('message', { data, source: iframe.contentWindow, origin: 'null' }));
    };
    post({ v: 1, kind: 'ready' });
    post({ v: 1, kind: 'error', nonce: 'guess' });
    expect(document.querySelector('iframe.as-script-sandbox')).not.toBeNull();
    post({ v: 1, kind: 'error', nonce: posted[0]!.nonce });

    await expect(promise).rejects.toThrow('the script worker failed');
  });
});
