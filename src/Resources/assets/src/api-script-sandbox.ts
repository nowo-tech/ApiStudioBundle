/**
 * Api Studio sandboxed script frame (spec 003, 1.15): loaded only in the opaque-origin iframe served by
 * `nowo_api_studio_script_sandbox` (`sandbox="allow-scripts"`, CSP without network). Since audit RW-M1 the frame runs
 * no script itself: it starts a dedicated Worker from a `blob:` URL (`worker-src blob:`), where the scripts run with
 * nothing to navigate, and relays between that worker and the console (lib/frame-relay.ts).
 *
 * @packageDocumentation
 */
import ScriptWorker from './api-script-worker?worker&inline';
import { createFrameRelay } from './lib/frame-relay';
import { lockDownNetwork } from './lib/lockdown';
import { createBundleLogger } from './logger';

declare const __API_STUDIO_BUILD_TIME__: string;

createBundleLogger('api-studio/script-sandbox', {
  buildTime: typeof __API_STUDIO_BUILD_TIME__ !== 'undefined' ? __API_STUDIO_BUILD_TIME__ : undefined,
}).scriptLoaded();

const post = window.parent.postMessage.bind(window.parent);
const toParent = (message: unknown): void => post(message, '*');
// The worker first, while Worker and Blob are still there; then the frame loses its network APIs too.
const relay = createFrameRelay(new ScriptWorker(), toParent);
lockDownNetwork(window);

window.addEventListener('message', (event: MessageEvent) => {
  if (event.source === window.parent) {
    relay(event.data);
  }
});
