/**
 * Api Studio server-side script runner (spec 003, 1.15): the sidecar of `ProcessScriptRunner`, run by Deno (default:
 * no permission at all) or Node. Reads one JSON message per line on stdin and writes one per line on stdout (see
 * lib/sandbox.ts for the messages): a `run`, `sendResult` answers to its `sendRequest` messages, then its `result`,
 * and exits.
 *
 * @packageDocumentation
 */
import { lockDownNetwork } from './lib/lockdown';
import { nodeRefusal, type NodeLike } from './lib/node-refusal';
import { scriptRequire } from './lib/require';
import { executeRun, isRunMessage, type ResultMessage, type SandboxHttpResponse, type SendResultMessage } from './lib/sandbox';

type DenoLike = {
  stdin: { readable: AsyncIterable<Uint8Array> };
  stdout: { write: (data: Uint8Array) => Promise<number> };
  exit: (code: number) => never;
};
const host = globalThis as unknown as { Deno?: DenoLike; process?: NodeLike };
const deno = host.Deno;
const node = deno ? undefined : host.process;
const encoder = new TextEncoder();
// Decided before the runtime globals are hidden below.
const refusal = nodeRefusal(node);

async function write(message: unknown): Promise<void> {
  const line = `${JSON.stringify(message)}\n`;
  if (deno) {
    let data = encoder.encode(line);
    while (data.length > 0) {
      data = data.subarray(await deno.stdout.write(data));
    }

    return;
  }
  await new Promise<void>((resolve) => node?.stdout.write(line, resolve));
}

function exit(code: number): never {
  return (deno ?? node)!.exit(code);
}

// No network API in the scripts' scope either (the sidecar's permissions remain the boundary; audit RW-L1).
lockDownNetwork(globalThis);

// Scripts reach only `pm` and their console: hide the runtime objects from the global scope (best effort; the sidecar's
// permissions are the boundary).
for (const name of ['Deno', 'process', 'require', 'Bun']) {
  try {
    Reflect.deleteProperty(globalThis, name);
    Object.defineProperty(globalThis, name, { value: undefined, configurable: false, writable: false });
  } catch {
    // not configurable here
  }
}

const waiting = new Map<number, { resolve: (r: SandboxHttpResponse) => void; reject: (e: Error) => void }>();
let nextId = 0;
let started = false;

function onLine(line: string): void {
  let data: unknown;
  try {
    data = JSON.parse(line);
  } catch {
    return;
  }
  const answer = data as Partial<SendResultMessage> | null;
  if (started && answer && answer.v === 1 && answer.kind === 'sendResult' && typeof answer.id === 'number') {
    const call = waiting.get(answer.id);
    waiting.delete(answer.id);
    if (call && answer.response && typeof answer.response === 'object') {
      call.resolve(answer.response);
    } else {
      call?.reject(new Error(typeof answer.error === 'string' ? answer.error : 'pm.sendRequest failed.'));
    }

    return;
  }
  if (started || !isRunMessage(data)) {
    return;
  }
  started = true;
  const run = data;
  if (refusal !== null) {
    void write({ v: 1, kind: 'result', nonce: run.nonce, ok: false, error: refusal, state: run.state }).then(() => exit(0));

    return;
  }
  void executeRun(run, (request) => {
    const id = ++nextId;

    return new Promise<SandboxHttpResponse>((resolve, reject) => {
      waiting.set(id, { resolve, reject });
      void write({ v: 1, kind: 'sendRequest', nonce: run.nonce, id, request });
    });
  }, scriptRequire)
    .catch((e: unknown): ResultMessage => ({
      v: 1,
      kind: 'result',
      nonce: run.nonce,
      ok: false,
      error: e instanceof Error ? e.message : String(e),
      state: run.state,
    }))
    .then(async (result) => {
      await write(result);
      exit(0);
    });
}

async function main(): Promise<void> {
  const input = deno ? deno.stdin.readable : node?.stdin;
  if (!input) {
    return;
  }
  const decoder = new TextDecoder();
  let buffer = '';
  for await (const chunk of input) {
    buffer += decoder.decode(chunk, { stream: true });
    let end: number;
    while ((end = buffer.indexOf('\n')) >= 0) {
      onLine(buffer.slice(0, end));
      buffer = buffer.slice(end + 1);
    }
  }
}

void main();
