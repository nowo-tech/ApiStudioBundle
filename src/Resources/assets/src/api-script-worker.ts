/**
 * Api Studio script worker (spec 003, 1.15; audit RW-M1): the dedicated Worker in which sandboxed pre / post scripts
 * run. The sandbox frame creates it from a `blob:` URL (frame policy `worker-src blob:`), so it inherits the frame's
 * CSP (no network) and, unlike the frame document, has nothing to navigate: no `document`, no `window.location` to
 * assign, no anchors, forms or popups. The network APIs are removed before any script runs; the worker talks only to
 * the frame, which relays to the console (see lib/frame-relay.ts and lib/sandbox.ts for the protocol).
 *
 * @packageDocumentation
 */
import { lockDownNetwork } from './lib/lockdown';
import { scriptRequire } from './lib/require';
import { executeRun, isRunMessage, type SandboxHttpResponse, type SendResultMessage } from './lib/sandbox';

type WorkerScope = typeof globalThis & {
  postMessage: (message: unknown) => void;
  addEventListener: (type: 'message', listener: (event: MessageEvent) => void) => void;
};

const scope = globalThis as WorkerScope;
const post = scope.postMessage.bind(scope);
lockDownNetwork(scope);

const waiting = new Map<number, { resolve: (r: SandboxHttpResponse) => void; reject: (e: Error) => void }>();
let nextId = 0;
let started = false;

scope.addEventListener('message', (event: MessageEvent) => {
  const data = event.data as Partial<SendResultMessage> | null;
  if (started && data && data.v === 1 && data.kind === 'sendResult' && typeof data.id === 'number') {
    const call = waiting.get(data.id);
    waiting.delete(data.id);
    if (call && data.response && typeof data.response === 'object') {
      call.resolve(data.response);
    } else {
      call?.reject(new Error(typeof data.error === 'string' ? data.error : 'pm.sendRequest failed.'));
    }

    return;
  }
  if (started || !isRunMessage(event.data)) {
    return;
  }
  started = true;
  const run = event.data;
  void executeRun(run, (request) => {
    const id = ++nextId;

    return new Promise<SandboxHttpResponse>((resolve, reject) => {
      waiting.set(id, { resolve, reject });
      post({ v: 1, kind: 'sendRequest', nonce: run.nonce, id, request });
    });
  }, scriptRequire).then(
    (result) => post(result),
    (e: unknown) => post({ v: 1, kind: 'result', nonce: run.nonce, ok: false, error: e instanceof Error ? e.message : String(e), state: run.state }),
  );
});

post({ v: 1, kind: 'ready' });
