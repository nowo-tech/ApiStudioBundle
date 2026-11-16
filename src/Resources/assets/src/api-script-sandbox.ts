/**
 * Api Studio sandboxed script frame (spec 003, 1.15): loaded only in the opaque-origin iframe served by
 * `nowo_api_studio_script_sandbox` (`sandbox="allow-scripts"`, CSP without network). Runs the scripts the console
 * posts and posts the result back to its parent; `pm.sendRequest` is relayed through the parent (the frame itself has
 * no network). See lib/sandbox.ts for the protocol.
 *
 * @packageDocumentation
 */
import { scriptRequire } from './lib/require';
import { executeRun, isRunMessage, type SandboxHttpResponse, type SendResultMessage } from './lib/sandbox';
import { lockDownNetwork } from './lib/lockdown';

// Before anything else: the frame needs no network of its own (pm.sendRequest goes through the parent), so scripts
// get none either, whatever happened to the document's CSP on its way here.
const post = window.parent.postMessage.bind(window.parent);
lockDownNetwork(window);

const waiting = new Map<number, { resolve: (r: SandboxHttpResponse) => void; reject: (e: Error) => void }>();
let nextId = 0;

window.addEventListener('message', (event: MessageEvent) => {
  if (event.source !== window.parent) {
    return;
  }
  const data = event.data as Partial<SendResultMessage> | null;
  if (data && data.v === 1 && data.kind === 'sendResult' && typeof data.id === 'number') {
    const call = waiting.get(data.id);
    waiting.delete(data.id);
    if (call && data.response && typeof data.response === 'object') {
      call.resolve(data.response);
    } else {
      call?.reject(new Error(typeof data.error === 'string' ? data.error : 'pm.sendRequest failed.'));
    }

    return;
  }
  if (!isRunMessage(event.data)) {
    return;
  }
  const run = event.data;
  void executeRun(run, (request) => {
    const id = ++nextId;

    return new Promise<SandboxHttpResponse>((resolve, reject) => {
      waiting.set(id, { resolve, reject });
      post({ v: 1, kind: 'sendRequest', nonce: run.nonce, id, request }, '*');
    });
  }, scriptRequire).then((result) => {
    post(result, '*');
  });
});

post({ v: 1, kind: 'ready' }, '*');
