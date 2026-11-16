import { describe, expect, it } from 'vitest';
import { nodeRefusal } from '../src/lib/node-refusal';

/** Spec 003 (1.15), audit RW-L1: the server runner refuses a Node that cannot block the network. */
const io = { stdin: (async function* () {})(), stdout: { write: () => true }, exit: () => undefined as never };

describe('nodeRefusal', () => {
  it('accepts Deno (no Node object)', () => {
    expect(nodeRefusal(undefined)).toBeNull();
  });

  it('refuses Node below 25 even with --permission', () => {
    expect(nodeRefusal({ ...io, versions: { node: '24.11.0' }, permission: { has: () => false } })).toMatch(/refuses Node 24\.11\.0: Node 25 or later is required/);
    expect(nodeRefusal({ ...io, versions: {} })).toMatch(/Node 25 or later/);
  });

  it('refuses Node 25+ without --permission or with network granted', () => {
    expect(nodeRefusal({ ...io, versions: { node: '25.1.0' } })).toMatch(/without --permission/);
    expect(nodeRefusal({ ...io, versions: { node: '25.1.0' }, permission: { has: (scope) => scope === 'net' } })).toMatch(/without --permission/);
    expect(
      nodeRefusal({
        ...io,
        versions: { node: '25.1.0' },
        permission: {
          has: () => {
            throw new Error('unknown scope');
          },
        },
      }),
    ).toMatch(/without --permission/);
  });

  it('accepts Node 25+ whose permission model denies the network', () => {
    expect(nodeRefusal({ ...io, versions: { node: '25.0.0' }, permission: { has: (scope) => scope !== 'net' } })).toBeNull();
  });
});
