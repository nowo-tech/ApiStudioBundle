import type { StringMap } from '../types/global';

export const VAR_PATTERN = /\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g;

export function parseJson<T>(raw: string | undefined, fallback: T): T {
  if (!raw) {
    return fallback;
  }

  try {
    return JSON.parse(raw) as T;
  } catch {
    return fallback;
  }
}

export function resolveTemplate(template: string | null | undefined, variables: StringMap): string {
  if (!template) {
    return '';
  }

  return String(template).replace(VAR_PATTERN, (_match, key: string) =>
    Object.prototype.hasOwnProperty.call(variables, key) ? variables[key]! : `{{${key}}}`,
  );
}

export function resolveMap(values: StringMap | null | undefined, variables: StringMap): StringMap {
  const out: StringMap = {};
  Object.keys(values ?? {}).forEach((key) => {
    out[key] = resolveTemplate(values![key], variables);
  });

  return out;
}

export function hasUnresolved(text: string | null | undefined): boolean {
  VAR_PATTERN.lastIndex = 0;

  return VAR_PATTERN.test(text ?? '');
}

export function truncateSnippet(text: string | null | undefined, max = 200): string {
  if (!text) {
    return '';
  }

  return text.length > max ? `${text.slice(0, max)}…` : text;
}

export function escapeHtmlAttr(value: string): string {
  return value.replace(/"/g, '&quot;');
}
