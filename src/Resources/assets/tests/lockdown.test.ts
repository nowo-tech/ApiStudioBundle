import { describe, expect, it } from 'vitest';
import { lockDownNetwork, NETWORK_GLOBALS, reachable } from '../src/lib/lockdown';

/** What a hostile script does (audit RW-D-L1): look for the original function anywhere on the prototype chain. */
function findOnChain(target: object, name: string): unknown {
  for (let p: object | null = target; p !== null; p = Object.getPrototypeOf(p) as object | null) {
    const descriptor = Object.getOwnPropertyDescriptor(p, name);
    if (descriptor && typeof descriptor.value === 'function') {
      return descriptor.value;
    }
  }

  return null;
}

/** Spec 003 (1.15): the sandbox frame removes its network APIs before any script runs. */
describe('lockDownNetwork', () => {
  it('removes every network global and navigator.sendBeacon for good', () => {
    class Navigator {
      sendBeacon(): boolean {
        return true;
      }
    }
    const scope: Record<string, unknown> = { Navigator, navigator: new Navigator(), keep: 1 };
    NETWORK_GLOBALS.forEach((name) => {
      scope[name] = () => 'network';
    });

    const done = lockDownNetwork(scope as unknown as typeof globalThis);

    expect(done).toEqual([...NETWORK_GLOBALS, 'navigator.sendBeacon']);
    NETWORK_GLOBALS.forEach((name) => expect(scope[name]).toBeUndefined());
    expect((scope.navigator as { sendBeacon?: unknown }).sendBeacon).toBeUndefined();
    expect(scope.keep).toBe(1);
    // A script cannot put them back.
    expect(() => {
      'use strict';
      scope.fetch = () => 'again';
    }).toThrow();
    expect(() => Object.defineProperty(scope, 'WebSocket', { value: 1 })).toThrow();
  });

  it('tolerates what it cannot change and reports only what is gone', () => {
    const frozen = Object.freeze({ fetch: () => 'network' });
    const done = lockDownNetwork(frozen as unknown as typeof globalThis);
    expect(done).not.toContain('fetch');
    expect(frozen.fetch()).toBe('network');

    const scope = { fetch: () => 1 } as unknown as typeof globalThis;
    expect(lockDownNetwork(scope)).toContain('fetch');
  });

  it('removes the APIs a worker global inherits from its prototypes (audit RW-D-L1)', () => {
    // A worker global: fetch and importScripts live on WorkerGlobalScope.prototype, not on the global itself.
    class EventTargetLike {
      addEventListener(): void {}
    }
    class WorkerGlobalScopeLike extends EventTargetLike {
      fetch(): string {
        return 'network';
      }
      importScripts(): string {
        return 'network';
      }
    }
    class DedicatedWorkerGlobalScopeLike extends WorkerGlobalScopeLike {
      postMessage(): void {}
    }
    const scope = new DedicatedWorkerGlobalScopeLike();
    expect(findOnChain(scope, 'fetch')).not.toBeNull();

    const done = lockDownNetwork(scope as unknown as typeof globalThis);

    expect(done).toContain('fetch');
    expect(done).toContain('importScripts');
    expect(findOnChain(scope, 'fetch')).toBeNull();
    expect(findOnChain(scope, 'importScripts')).toBeNull();
    expect(Object.getOwnPropertyDescriptor(WorkerGlobalScopeLike.prototype, 'fetch')).toBeUndefined();
    expect(Object.getOwnPropertyDescriptor(scope, 'fetch')).toMatchObject({ value: undefined, configurable: false, writable: false });
    // Unrelated members stay.
    expect(typeof scope.postMessage).toBe('function');
    expect(typeof scope.addEventListener).toBe('function');
  });

  it('does not report a prototype method it cannot remove', () => {
    const proto = {};
    Object.defineProperty(proto, 'fetch', { value: () => 'network', configurable: false });
    const scope = Object.create(proto) as typeof globalThis;

    expect(lockDownNetwork(scope)).not.toContain('fetch');
    expect(reachable(scope, 'fetch')).toBe(true);
  });
});

