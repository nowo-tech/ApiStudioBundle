/**
 * Api Studio frontend module: api-script-runtime.
 *
 * @packageDocumentation
 */

import type { ScriptContext, ScriptRunResult, StringMap } from './types/global';
import { createPm, runScript } from './lib/pm';

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
