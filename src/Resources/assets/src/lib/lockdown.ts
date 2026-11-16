/**
 * Defence in depth for the sandboxed script worker and frame (spec 003, 1.15). The **primary gate is the CSP** the
 * worker inherits from the frame (header and meta; `connect-src`, `script-src` and the rest through
 * `default-src 'none'`): it refuses every request whatever a script calls. This module only makes the network APIs
 * unreachable by name as a second layer, and covers channels no CSP directive governs (WebRTC in the frame).
 *
 * Browsers define many of these APIs on prototypes, not on the global object (in workers `fetch` and `importScripts`
 * live on `WorkerGlobalScope.prototype`, from the `WindowOrWorkerGlobalScope` mixin): shadowing them with an own
 * property is not enough, because `Object.getOwnPropertyDescriptor(proto, 'fetch').value` gets the original back
 * (audit RW-D-L1). So every object of the prototype chain loses the property, and the global (or the `navigator`)
 * gets a non-configurable, non-writable `undefined` in its place. A property a browser makes non-configurable on a
 * prototype cannot be removed; `lockDownNetwork()` then leaves it out of the names it reports, and the CSP still
 * applies.
 *
 * It does not stop navigation: CSP has no navigation directive and `sandbox="allow-scripts"` lets a document navigate
 * itself, which is why scripts run in a Worker (nothing to navigate) and never in the frame document (audit RW-M1).
 *
 * @packageDocumentation
 */

/** Global constructors and functions a script could use to reach the network. */
export const NETWORK_GLOBALS = [
  'fetch',
  'XMLHttpRequest',
  'WebSocket',
  'WebSocketStream',
  'WebTransport',
  'EventSource',
  'Worker',
  'SharedWorker',
  'RTCPeerConnection',
  'webkitRTCPeerConnection',
  'RTCDataChannel',
  'RTCIceTransport',
  'PresentationRequest',
  'BroadcastChannel',
  'open',
  'importScripts',
] as const;

/** Methods reached through another object of the global (`navigator.sendBeacon`). */
const NETWORK_METHODS: Array<[string, string]> = [['navigator', 'sendBeacon']];

/** $target and its prototypes, Object.prototype excluded. */
function chainOf(target: object): object[] {
  const chain: object[] = [];
  for (let p: object | null = target; p !== null && p !== Object.prototype; p = Object.getPrototypeOf(p) as object | null) {
    chain.push(p);
  }

  return chain;
}

/**
 * Removes $name from $target and every prototype of $target, then defines it on $target as a non-configurable,
 * non-writable `undefined`.
 */
function lock(target: object, name: string): void {
  chainOf(target).forEach((p) => {
    if (Object.prototype.hasOwnProperty.call(p, name)) {
      try {
        Reflect.deleteProperty(p, name);
      } catch {
        // Non-configurable: reported as not locked below.
      }
    }
  });
  try {
    Object.defineProperty(target, name, { value: undefined, configurable: false, writable: false, enumerable: false });
  } catch {
    // Not configurable here: the CSP still applies.
  }
}

/**
 * Whether a function named $name is still reachable from $target, by property access or through a descriptor
 * anywhere on its prototype chain.
 */
export function reachable(target: object, name: string): boolean {
  if ((target as Record<string, unknown>)[name] !== undefined) {
    return true;
  }

  return chainOf(target).some((p) => {
    const descriptor = Object.getOwnPropertyDescriptor(p, name);

    return descriptor !== undefined && (typeof descriptor.value === 'function' || typeof descriptor.get === 'function');
  });
}

/**
 * Removes the network APIs from $scope (the worker's global, or the frame's window) along its prototype chain; returns
 * the names that are no longer reachable.
 */
export function lockDownNetwork(scope: typeof globalThis = globalThis): string[] {
  const done: string[] = [];
  NETWORK_GLOBALS.forEach((name) => {
    lock(scope, name);
    if (!reachable(scope, name)) {
      done.push(name);
    }
  });
  NETWORK_METHODS.forEach(([owner, method]) => {
    const object = (scope as unknown as Record<string, unknown>)[owner];
    if (typeof object === 'object' && object !== null) {
      lock(object, method);
      if (!reachable(object, method)) {
        done.push(`${owner}.${method}`);
      }
    }
  });

  return done;
}
