/**
 * Sandboxed script runner (spec 003, 1.15): pre / post request scripts run in an opaque-origin iframe
 * (`sandbox="allow-scripts"`, its own CSP without network) and talk to the console only through `postMessage`.
 * The studio page itself never evaluates script code, so it needs no `'unsafe-eval'`.
 *
 * Protocol v1 (all messages carry `v: 1`):
 * - frame → page `{kind: 'ready'}` once loaded;
 * - page → frame `{kind: 'run', nonce, scripts, state, runTests}`;
 * - frame → page `{kind: 'result', nonce, ok, error?, label?, state}`.
 * The page only accepts messages whose `source` is the frame it created, whose origin is `null` (opaque) and whose
 * nonce matches the run; the frame only accepts messages from its parent.
 *
 * @packageDocumentation
 */

import type { ScopeChanges, ScriptContext, ScriptScopes, StringMap } from '../types/global';
import { createPm, headerList, responseAssertions, runScript } from './pm';

export type SandboxTest = { name: string; ok: boolean; error?: string };

export type SandboxState = {
  variables: StringMap;
  dirty: StringMap;
  request: { body: string; headers: StringMap; queryParams: StringMap };
  /** The response of a post phase; 1.15 adds its masked headers and round trip (ms). */
  response: SandboxResponse | null;
  logs: string[];
  tests: SandboxTest[];
  /** 1.15: `pm.globals` / `pm.collectionVariables` and the changes of the previous phases. */
  scopes: ScriptScopes;
};

/** Empty `pm.globals` / `pm.collectionVariables`. */
export function emptyScopes(): ScriptScopes {
  return { globals: {}, collection: {}, changes: { globals: {}, collection: {} } };
}

export type SandboxResponse = { code: number | string | null; body: string; headers?: StringMap; responseTime?: number };

export type SandboxScript = { label: string; code: string };

export type RunMessage = {
  v: 1;
  kind: 'run';
  nonce: string;
  scripts: SandboxScript[];
  state: SandboxState;
  runTests: boolean;
  /** `pm.sendRequest` calls allowed in this run (`scripts.limits.send_requests`; default MAX_SEND_REQUESTS). */
  sendBudget?: number;
};

export type ResultMessage = { v: 1; kind: 'result'; nonce: string; ok: boolean; error?: string; label?: string; state: SandboxState };

/** Largest script code accepted by the frame (characters, all scripts of a run). */
export const MAX_CODE_LENGTH = 256 * 1024;

/**
 * How much a frame message may add to what the page sent (characters of JSON): a result may grow the run state by at
 * most this much, and one `pm.sendRequest` message may not be larger.
 */
export const MAX_MESSAGE_GROWTH = 256 * 1024;

/** Messages a frame may send in one run on top of its `pm.sendRequest` budget (`ready`, `result`); more = flood. */
export const EXTRA_MESSAGES = 2;

/** Default wall time of one run (pre or post phase) before the frame is torn down. */
export const DEFAULT_TIMEOUT_MS = 2000;

function isStringMap(value: unknown): value is StringMap {
  return typeof value === 'object' && value !== null && Object.values(value).every((v) => typeof v === 'string');
}

function isScopeChanges(value: unknown): value is ScopeChanges {
  return typeof value === 'object' && value !== null && Object.values(value).every((v) => v === null || typeof v === 'string');
}

function isResponse(value: unknown): value is SandboxResponse | null {
  if (value === null) {
    return true;
  }
  if (typeof value !== 'object') {
    return false;
  }
  const r = value as Partial<SandboxResponse>;

  return (
    (r.code === null || typeof r.code === 'number' || typeof r.code === 'string') &&
    typeof r.body === 'string' &&
    (r.headers === undefined || isStringMap(r.headers)) &&
    (r.responseTime === undefined || (typeof r.responseTime === 'number' && Number.isFinite(r.responseTime)))
  );
}

function isTests(value: unknown): value is SandboxTest[] {
  return (
    Array.isArray(value) &&
    value.every(
      (t) =>
        typeof t === 'object' &&
        t !== null &&
        typeof (t as SandboxTest).name === 'string' &&
        typeof (t as SandboxTest).ok === 'boolean' &&
        ((t as SandboxTest).error === undefined || typeof (t as SandboxTest).error === 'string'),
    )
  );
}

/** Whether $value has the shape of a run state (both directions of the protocol). */
export function isSandboxState(value: unknown): value is SandboxState {
  if (typeof value !== 'object' || value === null) {
    return false;
  }
  const st = value as Partial<SandboxState>;

  return (
    isStringMap(st.variables) &&
    isStringMap(st.dirty) &&
    typeof st.request === 'object' &&
    st.request !== null &&
    typeof st.request.body === 'string' &&
    isStringMap(st.request.headers) &&
    isStringMap(st.request.queryParams) &&
    isResponse(st.response ?? null) &&
    Array.isArray(st.logs) &&
    st.logs.every((l) => typeof l === 'string') &&
    isTests(st.tests) &&
    isScopes(st.scopes)
  );
}

/**
 * Page side: whether $data is a well-formed result of the run with $nonce whose JSON is at most $maxLength characters
 * (the page ignores anything else; the frame is untrusted).
 */
export function isResultMessage(data: unknown, nonce: string, maxLength: number): data is ResultMessage {
  if (typeof data !== 'object' || data === null) {
    return false;
  }
  const m = data as Partial<ResultMessage>;
  if (
    m.v !== 1 ||
    m.kind !== 'result' ||
    m.nonce !== nonce ||
    typeof m.ok !== 'boolean' ||
    (m.error !== undefined && typeof m.error !== 'string') ||
    (m.label !== undefined && typeof m.label !== 'string') ||
    !isSandboxState(m.state)
  ) {
    return false;
  }
  try {
    return JSON.stringify(data).length <= maxLength;
  } catch {
    return false;
  }
}

function isScopes(value: unknown): value is ScriptScopes {
  if (typeof value !== 'object' || value === null) {
    return false;
  }
  const v = value as Partial<ScriptScopes>;

  return (
    isStringMap(v.globals) &&
    isStringMap(v.collection) &&
    typeof v.changes === 'object' &&
    v.changes !== null &&
    isScopeChanges(v.changes.globals) &&
    isScopeChanges(v.changes.collection)
  );
}

/** Whether $data is a well-formed run message (the frame refuses anything else). */
export function isRunMessage(data: unknown): data is RunMessage {
  if (typeof data !== 'object' || data === null) {
    return false;
  }
  const m = data as Partial<RunMessage>;
  if (m.v !== 1 || m.kind !== 'run' || typeof m.nonce !== 'string' || !Array.isArray(m.scripts) || typeof m.runTests !== 'boolean') {
    return false;
  }
  if (m.sendBudget !== undefined && !(Number.isInteger(m.sendBudget) && m.sendBudget >= 0 && m.sendBudget <= MAX_SEND_BUDGET)) {
    return false;
  }
  let length = 0;
  for (const s of m.scripts) {
    if (typeof s !== 'object' || s === null || typeof s.label !== 'string' || typeof s.code !== 'string') {
      return false;
    }
    length += s.code.length;
  }

  return length <= MAX_CODE_LENGTH && isSandboxState(m.state);
}

/** Default number of `pm.sendRequest` calls of one run (pre or post phase). */
export const MAX_SEND_REQUESTS = 10;

/** Highest `pm.sendRequest` budget a run message may carry. */
export const MAX_SEND_BUDGET = 100;

export type SandboxHttpRequest = { url: string; method: string; header: StringMap; body: string | null };

export type SandboxHttpResponse = { code: number; headers: StringMap; body: string; responseTime: number };

export type SendRequestMessage = { v: 1; kind: 'sendRequest'; nonce: string; id: number; request: SandboxHttpRequest };

export type SendResultMessage = { v: 1; kind: 'sendResult'; nonce: string; id: number; response?: SandboxHttpResponse; error?: string };

/** How the frame gets a `pm.sendRequest` answered (the page relays it to the server). */
export type SendTransport = (request: SandboxHttpRequest) => Promise<SandboxHttpResponse>;

/**
 * A `pm.sendRequest` argument (a URL, or Postman's `{url, method, header, body}` with `header` as a map or a list of
 * `{key, value}` and `body` as a string or `{mode: 'raw', raw}`) as a plain request.
 */
export function normalizeRequest(input: unknown): SandboxHttpRequest {
  if (typeof input === 'string') {
    return { url: input, method: 'GET', header: {}, body: null };
  }
  if (typeof input !== 'object' || input === null) {
    throw new Error('pm.sendRequest: a URL or a request object is required.');
  }
  const r = input as { url?: unknown; method?: unknown; header?: unknown; body?: unknown };
  const header: StringMap = {};
  if (Array.isArray(r.header)) {
    r.header.forEach((h) => {
      if (h && typeof h === 'object' && typeof (h as { key?: unknown }).key === 'string') {
        header[(h as { key: string }).key] = String((h as { value?: unknown }).value ?? '');
      }
    });
  } else if (r.header && typeof r.header === 'object') {
    Object.entries(r.header as Record<string, unknown>).forEach(([k, v]) => {
      header[k] = String(v ?? '');
    });
  }
  let body: string | null = null;
  if (typeof r.body === 'string') {
    body = r.body;
  } else if (r.body && typeof r.body === 'object' && typeof (r.body as { raw?: unknown }).raw === 'string') {
    body = (r.body as { raw: string }).raw;
  }

  return { url: String(r.url ?? ''), method: typeof r.method === 'string' ? r.method.toUpperCase() : 'GET', header, body };
}

/** The response object a script receives from `pm.sendRequest` (same helpers as `pm.response`). */
export function scriptResponse(r: SandboxHttpResponse) {
  const response = {
    code: r.code,
    status: String(r.code),
    responseTime: r.responseTime,
    headers: headerList(r.headers),
    text: (): string => r.body,
    json: (): unknown => JSON.parse(r.body || 'null'),
  };

  return { ...response, to: responseAssertions({ ...response, body: r.body }, false) };
}

/**
 * Frame side: runs the scripts of a run message with a fresh `pm` built from the state, one after the other, each
 * one's `pm.sendRequest` work (callbacks included) finished before the next starts; stops at the first error (like the
 * inline runner) and returns the new state. Without a transport, `pm.sendRequest` throws.
 */
export async function executeRun(message: RunMessage, transport?: SendTransport, requireFn?: (name: unknown) => unknown): Promise<ResultMessage> {
  const st = message.state;
  const ctx = createPm({
    baseVariables: st.variables,
    requestBody: st.request.body,
    requestHeaders: st.request.headers,
    requestQueryParams: st.request.queryParams,
    globals: st.scopes.globals,
    collectionVariables: st.scopes.collection,
  });
  if (st.response) {
    ctx.setResponse(st.response.code, st.response.body, st.response.headers, st.response.responseTime);
  }

  const pending = new Set<Promise<unknown>>();
  const allowed = message.sendBudget ?? MAX_SEND_REQUESTS;
  let budget = allowed;
  let asyncError: unknown = null;
  (ctx.pm as unknown as { sendRequest: unknown }).sendRequest = (
    input: unknown,
    callback?: (error: unknown, response: ReturnType<typeof scriptResponse> | null) => void,
  ): Promise<ReturnType<typeof scriptResponse>> => {
    if (!transport) {
      throw new Error('pm.sendRequest needs scripts.mode: sandbox.');
    }
    if (budget <= 0) {
      throw new Error(`pm.sendRequest: at most ${allowed} requests per run.`);
    }
    budget--;
    const call = transport(normalizeRequest(input)).then(
      (raw) => {
        const response = scriptResponse(raw);
        if (callback) {
          try {
            callback(null, response);
          } catch (e) {
            asyncError ??= e;
          }
        }

        return response;
      },
      (error: unknown) => {
        if (callback) {
          try {
            callback(error instanceof Error ? error : new Error(String(error)), null);
          } catch (e) {
            asyncError ??= e;
          }
        }
        throw error;
      },
    );
    pending.add(call.catch(() => undefined));

    return call;
  };

  const logs: string[] = [];
  let seen = 0;
  let failure: { error: string; label: string } | null = null;
  for (const script of message.scripts) {
    if (!script.code.trim()) {
      continue;
    }
    try {
      runScript(script.code, ctx.pm, requireFn);
      while (pending.size) {
        const waiting = [...pending];
        pending.clear();
        await Promise.all(waiting);
      }
      if (asyncError !== null) {
        throw asyncError;
      }
    } catch (e) {
      failure = { error: e instanceof Error ? e.message : String(e), label: script.label };
    }
    const all = ctx.getLogs();
    all.slice(seen).forEach((line) => logs.push(`[${script.label}] ${line}`));
    seen = all.length;
    if (failure) {
      break;
    }
  }

  return {
    v: 1,
    kind: 'result',
    nonce: message.nonce,
    ok: failure === null,
    ...(failure ? { error: failure.error, label: failure.label } : {}),
    state: {
      variables: ctx.getVariables(),
      dirty: { ...st.dirty, ...ctx.getDirtyKeys() },
      request: { body: ctx.pm.request.body, headers: { ...ctx.pm.request.headers }, queryParams: { ...ctx.pm.request.queryParams } },
      response: st.response,
      logs,
      tests: message.runTests && failure === null ? ctx.runTests() : [],
      scopes: mergeScopes(st.scopes, ctx.getScopes!()),
    },
  };
}

/** The scopes after a phase: its values, and the changes of the earlier phases followed by its own. */
function mergeScopes(before: ScriptScopes, after: ScriptScopes): ScriptScopes {
  return {
    globals: after.globals,
    collection: after.collection,
    changes: {
      globals: { ...before.changes.globals, ...after.changes.globals },
      collection: { ...before.changes.collection, ...after.changes.collection },
    },
  };
}

function newNonce(): string {
  const c = globalThis.crypto;
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }

  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Page side: runs $scripts in a fresh sandboxed frame loaded from $url and resolves with the frame's result; rejects
 * when the frame does not answer within $timeoutMs (the frame is removed either way, which stops a runaway script).
 */
export function runInSandbox(
  url: string,
  scripts: SandboxScript[],
  state: SandboxState,
  runTests: boolean,
  timeoutMs: number = DEFAULT_TIMEOUT_MS,
  sendRequest?: SendTransport,
  sendBudget: number = MAX_SEND_REQUESTS,
): Promise<ResultMessage> {
  return new Promise<ResultMessage>((resolve, reject) => {
    const frame = document.createElement('iframe');
    frame.setAttribute('sandbox', 'allow-scripts');
    frame.setAttribute('aria-hidden', 'true');
    frame.setAttribute('tabindex', '-1');
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.hidden = true;
    frame.className = 'as-script-sandbox';
    const nonce = newNonce();
    let settled = false;
    let started = false;
    let sent = 0;
    let received = 0;
    // The frame is untrusted: a result may grow the state it was given by at most MAX_MESSAGE_GROWTH, and the frame
    // may send at most its pm.sendRequest budget plus `ready` and `result`.
    let maxResultLength = MAX_MESSAGE_GROWTH;
    const maxMessages = sendBudget + EXTRA_MESSAGES;
    // The frame document loads once; a second load means it navigated (audit RW-M1): fail the run.
    let loads = 0;
    frame.addEventListener('load', () => {
      if (++loads > 1 && !settled) {
        finish();
        reject(new Error('Scripts stopped: the sandbox frame navigated.'));
      }
    });

    const finish = (): void => {
      settled = true;
      window.clearTimeout(timer);
      window.removeEventListener('message', onMessage);
      frame.remove();
    };

    function onMessage(event: MessageEvent): void {
      if (settled || event.source !== frame.contentWindow || event.origin !== 'null') {
        return;
      }
      if (++received > maxMessages) {
        finish();
        reject(new Error('Scripts stopped: the sandbox sent too many messages.'));

        return;
      }
      const data = event.data as Omit<Partial<ResultMessage>, 'kind'> & { kind?: string; v?: number };
      if (typeof data !== 'object' || data === null || data.v !== 1) {
        return;
      }
      if (data.kind === 'ready') {
        if (started) {
          return;
        }
        started = true;
        const run: RunMessage = { v: 1, kind: 'run', nonce, scripts, state, runTests, sendBudget };
        maxResultLength = JSON.stringify(run).length + MAX_MESSAGE_GROWTH;
        frame.contentWindow?.postMessage(run, '*');

        return;
      }
      if (!started) {
        return;
      }
      if (data.kind === 'sendRequest' && data.nonce === nonce) {
        const ask = data as unknown as Partial<SendRequestMessage>;
        const id = typeof ask.id === 'number' && Number.isSafeInteger(ask.id) ? ask.id : -1;
        let size = Infinity;
        try {
          size = JSON.stringify(ask.request ?? null).length;
        } catch {
          // not serialisable: refused below
        }
        const reply = (answer: Omit<SendResultMessage, 'v' | 'kind' | 'nonce' | 'id'>): void => {
          if (!settled) {
            frame.contentWindow?.postMessage({ v: 1, kind: 'sendResult', nonce, id, ...answer }, '*');
          }
        };
        if (!sendRequest || ++sent > sendBudget || typeof ask.request !== 'object' || ask.request === null || size > MAX_MESSAGE_GROWTH) {
          reply({ error: 'pm.sendRequest is not available.' });

          return;
        }
        sendRequest(normalizeRequest(ask.request)).then(
          (response) => reply({ response }),
          (error: unknown) => reply({ error: error instanceof Error ? error.message : String(error) }),
        );

        return;
      }
      if (data.kind === 'error' && data.nonce === nonce) {
        finish();
        reject(new Error('Scripts stopped: the script worker failed.'));

        return;
      }
      if (data.kind === 'result' && data.nonce === nonce) {
        finish();
        if (isResultMessage(data, nonce, maxResultLength)) {
          resolve(data);
        } else {
          reject(new Error('Scripts stopped: the sandbox sent a malformed or oversized result.'));
        }
      }
    }

    const timer = window.setTimeout(() => {
      if (!settled) {
        finish();
        reject(new Error(`Scripts stopped after ${timeoutMs} ms (sandbox time limit).`));
      }
    }, timeoutMs);

    window.addEventListener('message', onMessage);
    frame.src = url;
    document.body.appendChild(frame);
  });
}

/**
 * A console script context backed by a plain state object: the page keeps the state, the frame runs the scripts.
 */
export function sandboxContext(state: SandboxState): ScriptContext & { state: SandboxState } {
  const ctx = {
    state,
    pm: {
      get request() {
        return ctx.state.request;
      },
    } as unknown as ScriptContext['pm'],
    getVariables: () => ({ ...ctx.state.variables }),
    getLogs: () => ctx.state.logs.slice(),
    getDirtyKeys: () => ({ ...ctx.state.dirty }),
    getScopes: () => ctx.state.scopes,
    runTests: () => ctx.state.tests.slice(),
    setResponse: (status: number | string | null, body: string, headers?: StringMap, responseTime?: number) => {
      ctx.state.response = { code: status, body: body || '', headers: { ...(headers ?? {}) }, responseTime: responseTime ?? 0 };
    },
  };

  return ctx;
}

export type LevelScripts = { workspace: { pre: string; post: string }; folders: Array<{ name: string; pre: string; post: string }> };

/**
 * Workspace and folder scripts (1.15, sandbox mode; `data-level-scripts`), folders top-level first. Anything malformed
 * counts as no script.
 */
export function parseLevelScripts(raw: string | undefined): LevelScripts {
  const empty: LevelScripts = { workspace: { pre: '', post: '' }, folders: [] };
  let data: unknown;
  try {
    data = JSON.parse(raw || '{}');
  } catch {
    return empty;
  }
  if (typeof data !== 'object' || data === null) {
    return empty;
  }
  const d = data as { workspace?: { pre?: unknown; post?: unknown }; folders?: unknown };
  const str = (v: unknown): string => (typeof v === 'string' ? v : '');

  return {
    workspace: { pre: str(d.workspace?.pre), post: str(d.workspace?.post) },
    folders: Array.isArray(d.folders)
      ? d.folders
          .filter((f): f is { name?: unknown; pre?: unknown; post?: unknown } => typeof f === 'object' && f !== null)
          .map((f) => ({ name: str(f.name), pre: str(f.pre), post: str(f.post) }))
      : [],
  };
}

/**
 * `pm.globals` / `pm.collectionVariables` of the console (1.15, sandbox mode; `data-script-variables`). Anything
 * malformed counts as empty (PHP encodes an empty map as `[]`).
 */
export function parseScopes(raw: string | undefined): ScriptScopes {
  const scopes = emptyScopes();
  let data: unknown;
  try {
    data = JSON.parse(raw || '{}');
  } catch {
    return scopes;
  }
  if (typeof data !== 'object' || data === null) {
    return scopes;
  }
  const d = data as { globals?: unknown; collection?: unknown };
  const map = (v: unknown): StringMap =>
    typeof v === 'object' && v !== null && !Array.isArray(v) && isStringMap(v) ? { ...v } : {};

  return { ...scopes, globals: map(d.globals), collection: map(d.collection) };
}
