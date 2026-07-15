export type StringMap = Record<string, string>;

export type BodyFormat = 'json' | 'xml';

export type BodyValidationResult = {
  valid: boolean;
  with_variables: boolean;
  message: string;
};

export type BodyValidationLabels = Record<string, string>;

export type ScriptRunResult = {
  ok: boolean;
  logs: string[];
  label?: string;
  error?: string;
};

export type PmCreateOptions = {
  baseVariables?: StringMap;
  requestBody?: string;
  requestHeaders?: StringMap;
  requestQueryParams?: StringMap;
  onVariableChange?: (key: string, value: string) => void;
};

export type ScriptContext = {
  pm: PmApi;
  getVariables: () => StringMap;
  getLogs: () => string[];
  getDirtyKeys: () => StringMap;
  runTests: () => Array<{ name: string; ok: boolean; error?: string }>;
  setResponse: (status: number | string | null, body: string) => void;
};

export type PmApi = {
  environment: PmVariablesApi;
  variables: PmVariablesApi;
  request: PmRequestApi;
  response: PmResponseApi;
  test: (name: string, fn: () => void) => void;
  expect: (value: unknown) => { to: { equal: (expected: unknown) => void } };
  console: { log: (...args: unknown[]) => void };
};

export type PmVariablesApi = {
  get: (key: string) => string | undefined;
  set: (key: string, value: unknown) => void;
  unset: (key: string) => void;
  toObject: () => StringMap;
};

export type PmRequestApi = {
  body: string;
  headers: StringMap;
  queryParams: StringMap;
  setHeader: (key: string, value: unknown) => void;
  removeHeader: (key: string) => void;
  getHeader: (key: string) => string | undefined;
  setQueryParam: (key: string, value: unknown) => void;
  removeQueryParam: (key: string) => void;
  getQueryParam: (key: string) => string | undefined;
};

export type PmResponseApi = {
  code: number | string | null;
  status: string;
  body: string;
  json: () => unknown;
  text: () => string;
};

export type EnvironmentMaps = Record<
  string,
  {
    name?: string;
    is_default?: boolean;
    variables?: StringMap;
  }
>;

export type DocEntry = { title: string; description: string };

export type DocumentationByLocale = Record<string, DocEntry>;

declare global {
  interface Window {
    ApiStudioBodyTools: {
      resolveBodyFormat: (protocol: string, contentType?: string) => BodyFormat;
      validate: (body: string, format: BodyFormat) => BodyValidationResult;
      format: (body: string, format: BodyFormat) => string;
      formatDisplay: (body: string, format: BodyFormat) => string;
      messageLabel: (result: BodyValidationResult, labels?: BodyValidationLabels) => string;
    };
    ApiStudioScriptRuntime: {
      storageKey: (envId: string) => string;
      loadRuntimeOverrides: (envId: string) => StringMap;
      saveRuntimeOverrides: (envId: string, variables: StringMap) => void;
      createPm: (options: PmCreateOptions) => ScriptContext;
      runScriptSafe: (code: string, ctx: ScriptContext, label?: string) => ScriptRunResult;
    };
  }
}

export {};
