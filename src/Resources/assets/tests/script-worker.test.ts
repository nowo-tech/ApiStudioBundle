import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { emptyScopes, type RunMessage } from '../src/lib/sandbox';

/**
 * The script worker entry (spec 003, audit RW-M1): its scope is the test window here, with postMessage and the message
 * listener captured. lockDownNetwork() is spied (see lockdown.test.ts for its behaviour).
 */
const lock = vi.hoisted(() => ({ lockDownNetwork: vi.fn() }));
vi.mock('../src/lib/lockdown', () => lock);

function run(code: string, extra: Partial<RunMessage> = {}): RunMessage {
  return {
    v: 1,
    kind: 'run',
    nonce: 'n1',
    scripts: [{ label: 'pre', code }],
    runTests: false,
    state: { variables: {}, dirty: {}, request: { body: '', headers: {}, queryParams: {} }, response: null, logs: [], tests: [], scopes: emptyScopes() },
    ...extra,
  };
}

describe('api-script-worker', () => {
  let posted: unknown[];
  let listener: (event: MessageEvent) => void;
  const say = (data: unknown): void => listener(new MessageEvent('message', { data }));
  const tick = async (): Promise<void> => {
    for (let i = 0; i < 10; i += 1) {
      await Promise.resolve();
    }
  };

  beforeEach(async () => {
    posted = [];
    (globalThis as unknown as { postMessage: unknown }).postMessage = (m: unknown) => posted.push(m);
    const add = vi.spyOn(globalThis, 'addEventListener').mockImplementation(((type: string, fn: unknown) => {
      if (type === 'message') {
        listener = fn as (event: MessageEvent) => void;
      }
    }) as typeof globalThis.addEventListener);
    vi.resetModules();
    await import('../src/api-script-worker');
    add.mockRestore();
  });

  afterEach(() => {
    lock.lockDownNetwork.mockClear();
  });

  it('locks the network down, says ready and runs one run', async () => {
    expect(lock.lockDownNetwork).toHaveBeenCalledWith(globalThis);
    expect(posted).toEqual([{ v: 1, kind: 'ready' }]);

    say(run("pm.environment.set('a', '1')"));
    await tick();

    const result = posted[1] as { kind: string; ok: boolean; nonce: string; state: { dirty: Record<string, string> } };
    expect(result).toMatchObject({ kind: 'result', ok: true, nonce: 'n1' });
    expect(result.state.dirty).toEqual({ a: '1' });
  });

  it('runs only the first run and ignores everything that is not a run', async () => {
    say({ nope: true });
    say(null);
    expect(posted).toHaveLength(1);

    say(run('1'));
    say(run('2'));
    await tick();

    expect(posted.filter((m) => (m as { kind: string }).kind === 'result')).toHaveLength(1);
  });

  it('asks the frame for pm.sendRequest and resumes with the answer', async () => {
    say(run("pm.sendRequest('https://auth.test/t', (err, res) => pm.environment.set('code', String(res.code)))"));
    await tick();

    const ask = posted[1] as { kind: string; id: number; nonce: string };
    expect(ask).toMatchObject({ kind: 'sendRequest', nonce: 'n1' });
    say({ v: 1, kind: 'sendResult', nonce: 'n1', id: ask.id, response: { code: 200, headers: {}, body: '{}', responseTime: 1 } });
    await tick();

    expect(posted.at(-1)).toMatchObject({ kind: 'result', ok: true, state: { dirty: { code: '200' } } });
  });

  it('fails the request of a script when the frame answers with an error or nothing', async () => {
    say(run("pm.sendRequest('https://x.test', (err) => pm.console.log('err:' + err.message)); pm.sendRequest('https://y.test', (err) => pm.console.log('err:' + err.message))"));
    await tick();
    const [first, second] = posted.slice(1) as Array<{ id: number }>;

    say({ v: 1, kind: 'sendResult', nonce: 'n1', id: first!.id, error: 'blocked' });
    say({ v: 1, kind: 'sendResult', nonce: 'n1', id: second!.id });
    say({ v: 1, kind: 'sendResult', nonce: 'n1', id: 999 });
    await tick();

    const result = posted.at(-1) as { state: { logs: string[] } };
    expect(result.state.logs.join('\n')).toContain('err:blocked');
    expect(result.state.logs.join('\n')).toContain('err:pm.sendRequest failed.');
  });
});
