/**
 * The Postman-style `pm` facade and the script runner shared by the inline runtime (page) and the sandboxed runner
 * (opaque-origin frame, 1.15).
 *
 * @packageDocumentation
 */

import { expect } from './expect';
import type { PmApi, PmCreateOptions, PmScopeApi, ScopeChanges, ScriptContext, StringMap } from '../types/global';

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
  };

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
    setResponse(status, body) {
      responseWrapper.code = status;
      responseWrapper.status = status ? String(status) : '';
      responseWrapper.body = body || '';
    },
  };
}

export function runScript(code: string, pm: PmApi): void {
  if (!code || !String(code).trim()) {
    return;
  }

  const runner = new Function('pm', 'console', String(code)) as (
    pm: PmApi,
    console: PmApi['console'],
  ) => void;
  runner(pm, pm.console);
}

