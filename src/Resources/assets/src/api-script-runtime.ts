import type {
  PmApi,
  PmCreateOptions,
  ScriptContext,
  ScriptRunResult,
  StringMap,
} from './types/global';

function storageKey(envId: string): string {
  return `nowo_api_studio_runtime_vars_${envId}`;
}

function loadRuntimeOverrides(envId: string): StringMap {
  if (!envId) {
    return {};
  }

  try {
    return JSON.parse(sessionStorage.getItem(storageKey(envId)) || '{}') as StringMap;
  } catch {
    return {};
  }
}

function saveRuntimeOverrides(envId: string, variables: StringMap): void {
  if (!envId) {
    return;
  }

  sessionStorage.setItem(storageKey(envId), JSON.stringify(variables));
}

function createPm(options: PmCreateOptions): ScriptContext {
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

  const tests: Array<{ name: string; fn: () => void }> = [];
  const pm: PmApi = {
    environment: envApi,
    variables: envApi,
    request,
    response: responseWrapper,
    test(name, fn) {
      tests.push({ name, fn });
    },
    expect(value) {
      return {
        to: {
          equal(expected: unknown) {
            if (value !== expected) {
              throw new Error(`Expected ${String(expected)} but got ${String(value)}`);
            }
          },
        },
      };
    },
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

function runScript(code: string, pm: PmApi): void {
  if (!code || !String(code).trim()) {
    return;
  }

  const runner = new Function('pm', 'console', String(code)) as (
    pm: PmApi,
    console: PmApi['console'],
  ) => void;
  runner(pm, pm.console);
}

function runScriptSafe(code: string, ctx: ScriptContext, label?: string): ScriptRunResult {
  if (!code || !String(code).trim()) {
    return { ok: true, logs: ctx.getLogs() };
  }

  try {
    runScript(code, ctx.pm);

    return { ok: true, logs: ctx.getLogs(), label };
  } catch (e) {
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
      logs: ctx.getLogs(),
      label,
    };
  }
}

window.ApiStudioScriptRuntime = {
  storageKey,
  loadRuntimeOverrides,
  saveRuntimeOverrides,
  createPm,
  runScriptSafe,
};
