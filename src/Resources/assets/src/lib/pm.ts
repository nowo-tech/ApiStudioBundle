/**
 * The Postman-style `pm` facade and the script runner shared by the inline runtime (page) and the sandboxed runner
 * (opaque-origin frame, 1.15).
 *
 * @packageDocumentation
 */

import { expect } from './expect';
import type { PmApi, PmCreateOptions, PmHeaderList, PmResponseAssertions, PmScopeApi, ScopeChanges, ScriptContext, StringMap } from '../types/global';

/** Response headers as Postman's header list: `get` / `has` ignore case. */
export function headerList(headers: StringMap): PmHeaderList {
  const own: StringMap = { ...headers };
  const find = (name: string): string | undefined =>
    Object.keys(own).find((k) => k.toLowerCase() === String(name).toLowerCase());

  return {
    get(name: string) {
      const key = find(name);

      return key === undefined ? undefined : own[key];
    },
    has(name: string, value?: unknown) {
      const key = find(name);

      return key !== undefined && (value === undefined || own[key] === String(value));
    },
    toObject: () => ({ ...own }),
  };
}

type AssertedResponse = { code: number | string | null; body: string; headers: PmHeaderList; text: () => string };

/**
 * Postman's `pm.response.to` assertions (1.15): `to.have.status(code)`, `to.have.header(name[, value])`,
 * `to.have.body([text])`, `to.have.jsonBody()`, `to.be.ok` / `success` / `error` / `clientError` / `serverError` /
 * `json`, and `to.not.*`. Each throws when it fails, like `pm.expect`.
 */
export function responseAssertions(response: AssertedResponse, negate: boolean): PmResponseAssertions {
  const check = (pass: boolean, message: string): void => {
    if (pass === negate) {
      throw new Error(negate ? `Expected not: ${message}` : `Expected ${message}`);
    }
  };
  const code = (): number => Number(response.code);
  const isJson = (): boolean => {
    try {
      JSON.parse(response.text());

      return true;
    } catch {
      return false;
    }
  };
  const have = {
    status(expected: number | string) {
      check(typeof expected === 'number' ? code() === expected : String(response.code) === String(expected), `status ${String(expected)} but got ${String(response.code)}`);
    },
    header(name: string, value?: unknown) {
      check(response.headers.has(name, value), value === undefined ? `header ${name}` : `header ${name}: ${String(value)}`);
    },
    body(expected?: string) {
      check(expected === undefined ? response.text() !== '' : response.text() === expected, expected === undefined ? 'a response body' : `body ${expected}`);
    },
    jsonBody() {
      check(isJson(), 'a JSON body');
    },
  };
  const be = {
    get ok() {
      return check(code() >= 200 && code() < 300, `a 2xx status but got ${String(response.code)}`);
    },
    get success() {
      return check(code() >= 200 && code() < 300, `a 2xx status but got ${String(response.code)}`);
    },
    get error() {
      return check(code() >= 400 && code() < 600, `a 4xx or 5xx status but got ${String(response.code)}`);
    },
    get clientError() {
      return check(code() >= 400 && code() < 500, `a 4xx status but got ${String(response.code)}`);
    },
    get serverError() {
      return check(code() >= 500 && code() < 600, `a 5xx status but got ${String(response.code)}`);
    },
    get json() {
      return check(isJson(), 'a JSON body');
    },
  };
  const api = { have, be } as unknown as PmResponseAssertions;
  Object.defineProperty(api, 'not', { get: () => responseAssertions(response, !negate) });

  return api;
}

/** A `pm.globals` / `pm.collectionVariables` store: its values and the changes made by the scripts. */
function scopeStore(initial: StringMap | undefined): { api: PmScopeApi; values: StringMap; changes: ScopeChanges } {
  const values: StringMap = { ...(initial ?? {}) };
  const changes: ScopeChanges = {};
  const api: PmScopeApi = {
    get(key: string) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : undefined;
    },
    set(key: string, value: unknown) {
      if (!key) return;
      values[key] = value == null ? '' : String(value);
      changes[key] = values[key]!;
    },
    unset(key: string) {
      delete values[key];
      changes[key] = null;
    },
    has(key: string) {
      return Object.prototype.hasOwnProperty.call(values, key);
    },
    clear() {
      Object.keys(values).forEach((key) => api.unset(key));
    },
    toObject() {
      return { ...values };
    },
  };

  return { api, values, changes };
}

export function createPm(options: PmCreateOptions): ScriptContext {
  const logs: string[] = [];
  const dirtyKeys: StringMap = {};
  const variables: StringMap = { ...(options.baseVariables ?? {}) };

  const envApi = {
    get(key: string) {
      return variables[key];
    },
    set(key: string, value: unknown) {
      variables[key] = value == null ? '' : String(value);
      dirtyKeys[key] = variables[key]!;
      options.onVariableChange?.(key, variables[key]!);
    },
    unset(key: string) {
      delete variables[key];
      dirtyKeys[key] = '';
    },
    toObject() {
      return { ...variables };
    },
  };

  const request = {
    body: options.requestBody ?? '',
    headers: { ...(options.requestHeaders ?? {}) },
    queryParams: { ...(options.requestQueryParams ?? {}) },
    setHeader(key: string, value: unknown) {
      if (!key) return;
      this.headers[key] = value == null ? '' : String(value);
    },
    removeHeader(key: string) {
      delete this.headers[key];
    },
    getHeader(key: string) {
      return this.headers[key];
    },
    setQueryParam(key: string, value: unknown) {
      if (!key) return;
      this.queryParams[key] = value == null ? '' : String(value);
    },
    removeQueryParam(key: string) {
      delete this.queryParams[key];
    },
    getQueryParam(key: string) {
      return this.queryParams[key];
    },
  };

  const responseWrapper = {
    code: null as number | string | null,
    status: '',
    body: '',
    /** 1.15: masked response headers (`get` ignores case) and the round trip in milliseconds. */
    headers: headerList({}),
    responseTime: 0,
    json() {
      try {
        return JSON.parse(this.body || '{}');
      } catch {
        return null;
      }
    },
    text() {
      return this.body || '';
    },
    to: undefined as unknown as PmResponseAssertions,
  };
  responseWrapper.to = responseAssertions(responseWrapper, false);

  const globals = scopeStore(options.globals);
  const collection = scopeStore(options.collectionVariables);
  // pm.variables reads the narrowest scope that has the key (environment, then collection, then globals) and writes
  // the environment, as before 1.15.
  const variablesApi = {
    ...envApi,
    get(key: string) {
      return envApi.get(key) ?? collection.api.get(key) ?? globals.api.get(key);
    },
    toObject() {
      return { ...globals.values, ...collection.values, ...variables };
    },
  };

  const tests: Array<{ name: string; fn: () => void }> = [];
  const pm: PmApi = {
    environment: envApi,
    variables: variablesApi,
    globals: globals.api,
    collectionVariables: collection.api,
    request,
    response: responseWrapper,
    test(name, fn) {
      tests.push({ name, fn });
    },
    expect,
    console: {
      log(...args: unknown[]) {
        logs.push(args.map(String).join(' '));
      },
    },
  };

  return {
    pm,
    getVariables: () => ({ ...variables }),
    getLogs: () => logs.slice(),
    getDirtyKeys: () => ({ ...dirtyKeys }),
    getScopes: () => ({
      globals: { ...globals.values },
      collection: { ...collection.values },
      changes: { globals: { ...globals.changes }, collection: { ...collection.changes } },
    }),
    runTests: () =>
      tests.map((t) => {
        try {
          t.fn();

          return { name: t.name, ok: true };
        } catch (e) {
          return {
            name: t.name,
            ok: false,
            error: e instanceof Error ? e.message : String(e),
          };
        }
      }),
    setResponse(status, body, headers, responseTime) {
      responseWrapper.code = status;
      responseWrapper.status = status ? String(status) : '';
      responseWrapper.body = body || '';
      responseWrapper.headers = headerList(headers ?? {});
      responseWrapper.responseTime = typeof responseTime === 'number' && Number.isFinite(responseTime) ? responseTime : 0;
    },
  };
}

/** `require()` outside the sandbox frame and the server runner: no library is bundled there. */
function noRequire(name: unknown): never {
  throw new Error(`require('${String(name)}') needs scripts.mode: sandbox (or the server runner).`);
}

export function runScript(code: string, pm: PmApi, requireFn: (name: unknown) => unknown = noRequire): void {
  if (!code || !String(code).trim()) {
    return;
  }

  const runner = new Function('pm', 'console', 'require', String(code)) as (
    pm: PmApi,
    console: PmApi['console'],
    require: (name: unknown) => unknown,
  ) => void;
  runner(pm, pm.console, requireFn);
}

