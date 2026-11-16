import { describe, expect, it } from 'vitest';
import { lockDownNetwork, NETWORK_GLOBALS } from '../src/lib/lockdown';

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

    expect(done).toEqual([...NETWORK_GLOBALS, 'Navigator.sendBeacon']);
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
});
