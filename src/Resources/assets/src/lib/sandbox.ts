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

import type { ScriptContext, StringMap } from '../types/global';
import { createPm, runScript } from './pm';

export type SandboxTest = { name: string; ok: boolean; error?: string };

export type SandboxState = {
  variables: StringMap;
  dirty: StringMap;
  request: { body: string; headers: StringMap; queryParams: StringMap };
  response: { code: number | string | null; body: string } | null;
  logs: string[];
  tests: SandboxTest[];
};

export type SandboxScript = { label: string; code: string };

export type RunMessage = { v: 1; kind: 'run'; nonce: string; scripts: SandboxScript[]; state: SandboxState; runTests: boolean };

export type ResultMessage = { v: 1; kind: 'result'; nonce: string; ok: boolean; error?: string; label?: string; state: SandboxState };

/** Largest script code accepted by the frame (characters, all scripts of a run). */
export const MAX_CODE_LENGTH = 256 * 1024;

/** Default wall time of one run (pre or post phase) before the frame is torn down. */
export const DEFAULT_TIMEOUT_MS = 2000;

function isStringMap(value: unknown): value is StringMap {
  return typeof value === 'object' && value !== null && Object.values(value).every((v) => typeof v === 'string');
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
  let length = 0;
  for (const s of m.scripts) {
    if (typeof s !== 'object' || s === null || typeof s.label !== 'string' || typeof s.code !== 'string') {
      return false;
    }
    length += s.code.length;
  }
  const st = m.state;

  return (
    length <= MAX_CODE_LENGTH &&
    typeof st === 'object' &&
    st !== null &&
    isStringMap(st.variables) &&
    isStringMap(st.dirty) &&
    typeof st.request === 'object' &&
    st.request !== null &&
    typeof st.request.body === 'string' &&
    isStringMap(st.request.headers) &&
    isStringMap(st.request.queryParams)
  );
}

/**
 * Frame side: runs the scripts of a run message with a fresh `pm` built from the state, stops at the first error
 * (like the inline runner) and returns the new state.
 */
export function executeRun(message: RunMessage): ResultMessage {
  const st = message.state;
  const ctx = createPm({
    baseVariables: st.variables,
    requestBody: st.request.body,
    requestHeaders: st.request.headers,
    requestQueryParams: st.request.queryParams,
  });
  if (st.response) {
    ctx.setResponse(st.response.code, st.response.body);
  }

  const logs: string[] = [];
  let seen = 0;
  let failure: { error: string; label: string } | null = null;
  for (const script of message.scripts) {
    if (!script.code.trim()) {
      continue;
    }
    try {
      runScript(script.code, ctx.pm);
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
      const data = event.data as Omit<Partial<ResultMessage>, 'kind'> & { kind?: string; v?: number };
      if (typeof data !== 'object' || data === null || data.v !== 1) {
        return;
      }
      if (data.kind === 'ready') {
        const run: RunMessage = { v: 1, kind: 'run', nonce, scripts, state, runTests };
        frame.contentWindow?.postMessage(run, '*');

        return;
      }
      if (data.kind === 'result' && data.nonce === nonce && typeof data.state === 'object' && data.state !== null) {
        finish();
        resolve(data as ResultMessage);
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
    runTests: () => ctx.state.tests.slice(),
    setResponse: (status: number | string | null, body: string) => {
      ctx.state.response = { code: status, body: body || '' };
    },
  };

  return ctx;
}
