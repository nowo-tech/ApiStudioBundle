import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { emptyScopes, type RunMessage } from '../src/lib/sandbox';

/**
 * The server-side sidecar (spec 003): reads one JSON message per line on stdin and writes one per line on stdout.
 * It runs here under a fake Deno (stdin / stdout / exit); the runtime globals it hides are protected from deletion
 * and lockDownNetwork() is spied (see lockdown.test.ts), so this test process keeps its own `process`.
 */
const lock = vi.hoisted(() => ({ lockDownNetwork: vi.fn() }));
vi.mock('../src/lib/lockdown', () => lock);

function run(code: string): RunMessage {
  return {
    v: 1,
    kind: 'run',
    nonce: 'n1',
    scripts: [{ label: 'pre', code }],
    runTests: false,
    state: { variables: {}, dirty: {}, request: { body: '', headers: {}, queryParams: {} }, response: null, logs: [], tests: [], scopes: emptyScopes() },
  };
}

type Channel = { feed: (line: string) => void; end: () => void; written: string[]; exits: number[]; settled: () => Promise<void> };

/** A fake Deno whose stdin is fed by the test. */
function installDeno(): Channel {
  const encoder = new TextEncoder();
  const queue: Uint8Array[] = [];
  let wake: (() => void) | null = null;
  let closed = false;
  const written: string[] = [];
  const exits: number[] = [];
  const stdin = {
    async *[Symbol.asyncIterator](): AsyncGenerator<Uint8Array> {
      while (!closed || queue.length > 0) {
        if (queue.length === 0) {
          await new Promise<void>((resolve) => {
            wake = resolve;
          });
          continue;
        }
        yield queue.shift()!;
      }
    },
  };
  (globalThis as unknown as { Deno: unknown }).Deno = {
    stdin: { readable: stdin },
    stdout: {
      write: async (data: Uint8Array): Promise<number> => {
        // A short write on purpose: the sidecar writes the rest in a loop.
        const part = data.subarray(0, 5);
        written.push(new TextDecoder().decode(part));

        return part.length;
      },
    },
    exit: (code: number) => {
      exits.push(code);

      return undefined as never;
    },
  };

  return {
    feed: (line) => {
      queue.push(encoder.encode(line));
      wake?.();
    },
    end: () => {
      closed = true;
      wake?.();
    },
    written,
    exits,
    settled: async () => {
      for (let i = 0; i < 200 && exits.length === 0; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 1));
      }
    },
  };
}

describe('api-script-runner (sidecar)', () => {
  beforeEach(() => {
    // The entry deletes the runtime globals: keep them (the entry's own try/catch tolerates the failure).
    vi.spyOn(Reflect, 'deleteProperty').mockImplementation(() => {
      throw new Error('not configurable here');
    });
  });

  afterEach(() => {
    delete (globalThis as unknown as { Deno?: unknown }).Deno;
    lock.lockDownNetwork.mockClear();
  });

  it('runs a run line and answers one result line, then exits', async () => {
    const channel = installDeno();
    vi.resetModules();
    await import('../src/api-script-runner');
    expect(lock.lockDownNetwork).toHaveBeenCalledWith(globalThis);

    channel.feed('not json\n');
    channel.feed(`${JSON.stringify(run("pm.environment.set('a', '1')"))}\n`);
    await channel.settled();
    channel.end();

    const out = channel.written.join('');
    expect(out).toContain('"kind":"result"');
    expect(out).toContain('"ok":true');
    expect(channel.exits).toEqual([0]);
  });

  it('relays pm.sendRequest through stdout and resumes with the answer line', async () => {
    const channel = installDeno();
    vi.resetModules();
    await import('../src/api-script-runner');

    channel.feed(`${JSON.stringify(run("pm.sendRequest('https://auth.test/t', (err, res) => pm.environment.set('code', String(res.code)))"))}\n`);
    for (let i = 0; i < 100 && !channel.written.join('').includes('sendRequest'); i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 1));
    }
    const ask = JSON.parse(channel.written.join('').split('\n')[0]!) as { kind: string; id: number };
    expect(ask.kind).toBe('sendRequest');

    channel.feed(`${JSON.stringify({ v: 1, kind: 'sendResult', nonce: 'n1', id: ask.id, response: { code: 201, headers: {}, body: '', responseTime: 1 } })}\n`);
    channel.feed(`${JSON.stringify({ v: 1, kind: 'sendResult', nonce: 'n1', id: 999, error: 'late' })}\n`);
    channel.feed(`${JSON.stringify(run('2'))}\n`);
    await channel.settled();
    channel.end();

    expect(channel.written.join('')).toContain('"code":"201"');
    expect(channel.exits).toEqual([0]);
  });

  it('fails the request of a script when the answer is an error', async () => {
    const channel = installDeno();
    vi.resetModules();
    await import('../src/api-script-runner');

    channel.feed(`${JSON.stringify(run("pm.sendRequest('https://x.test', (err) => pm.console.log('err:' + err.message))"))}\n`);
    for (let i = 0; i < 100 && !channel.written.join('').includes('sendRequest'); i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 1));
    }
    const ask = JSON.parse(channel.written.join('').split('\n')[0]!) as { id: number };
    channel.feed(`${JSON.stringify({ v: 1, kind: 'sendResult', nonce: 'n1', id: ask.id, error: 'denied' })}\n`);
    await channel.settled();
    channel.end();

    expect(channel.written.join('')).toContain('err:denied');
  });

  it('refuses a Node that cannot block the network', async () => {
    delete (globalThis as unknown as { Deno?: unknown }).Deno;
    const stdout = vi.fn((_data: string, callback: () => void) => {
      callback();

      return true;
    });
    const exit = vi.fn();
    const original = Object.getOwnPropertyDescriptor(globalThis, 'process');
    vi.stubGlobal('process', {
      versions: { node: '20.0.0' },
      stdin: (async function* () {
        yield new TextEncoder().encode(`${JSON.stringify(run('1'))}\n`);
      })(),
      stdout: { write: stdout },
      exit,
    });
    vi.resetModules();
    await import('../src/api-script-runner');
    for (let i = 0; i < 200 && exit.mock.calls.length === 0; i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 1));
    }
    vi.unstubAllGlobals();
    if (original) {
      Object.defineProperty(globalThis, 'process', original);
    }

    expect(stdout.mock.calls[0]![0]).toContain('refuses Node 20.0.0');
    expect(exit).toHaveBeenCalledWith(0);
  });

  it('does nothing when there is no stdin', async () => {
    delete (globalThis as unknown as { Deno?: unknown }).Deno;
    vi.stubGlobal('process', { versions: { node: '25.0.0' }, permission: { has: () => false }, stdout: { write: vi.fn() }, exit: vi.fn() });
    vi.resetModules();
    await import('../src/api-script-runner');
    vi.unstubAllGlobals();

    expect(lock.lockDownNetwork).toHaveBeenCalled();
  });
});
