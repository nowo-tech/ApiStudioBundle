/**
 * Api Studio sandboxed script frame (spec 003, 1.15): loaded only in the opaque-origin iframe served by
 * `nowo_api_studio_script_sandbox` (`sandbox="allow-scripts"`, CSP without network). Runs the scripts the console
 * posts and posts the result back to its parent. See lib/sandbox.ts for the protocol.
 *
 * @packageDocumentation
 */
import { executeRun, isRunMessage } from './lib/sandbox';

window.addEventListener('message', (event: MessageEvent) => {
  if (event.source !== window.parent || !isRunMessage(event.data)) {
    return;
  }
  window.parent.postMessage(executeRun(event.data), '*');
});

window.parent.postMessage({ v: 1, kind: 'ready' }, '*');
