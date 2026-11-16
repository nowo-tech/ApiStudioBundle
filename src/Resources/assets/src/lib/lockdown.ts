/**
 * Defence in depth for the sandboxed script frame (spec 003, 1.15): removes the network APIs from the frame's global
 * scope before any script runs. The frame CSP (header and meta) is the boundary; this also covers channels a CSP does
 * not govern (WebRTC) and hosts whose middleware altered the document's policy. Scripts run in this same realm and
 * cannot create another one (no frames, no workers), so they cannot get the originals back.
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
] as const;

/** Prototype methods with the same reach (`navigator.sendBeacon`). */
const NETWORK_METHODS: Array<[string, string]> = [['Navigator', 'sendBeacon']];

function lock(target: object, name: string): void {
  try {
    Reflect.deleteProperty(target, name);
    Object.defineProperty(target, name, { value: undefined, configurable: false, writable: false, enumerable: false });
  } catch {
    // Not configurable here: the CSP still applies.
  }
}

/** Removes the network APIs from $scope (the frame's window); returns the names that are now unavailable. */
export function lockDownNetwork(scope: typeof globalThis = globalThis): string[] {
  const done: string[] = [];
  const g = scope as unknown as Record<string, unknown>;
  NETWORK_GLOBALS.forEach((name) => {
    lock(scope, name);
    if (g[name] === undefined) {
      done.push(name);
    }
  });
  NETWORK_METHODS.forEach(([owner, method]) => {
    const proto = (g[owner] as { prototype?: object } | undefined)?.prototype;
    if (proto) {
      lock(proto, method);
      if ((proto as Record<string, unknown>)[method] === undefined) {
        done.push(`${owner}.${method}`);
      }
    }
  });

  return done;
}
