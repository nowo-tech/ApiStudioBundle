/**
 * Shared helpers for Api Studio frontend modules (JSON parse, variable templates).
 *
 * @packageDocumentation
 */
import type { StringMap } from '../types/global';

export const VAR_PATTERN = /\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g;

/**
 * Parse a JSON string, returning `fallback` when empty or invalid.
 *
 * @param raw JSON text to parse.
 * @param fallback Value used when parsing fails.
 * @returns Parsed value or `fallback`.
 */
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

/**
 * Replace `{{var}}` placeholders in a template using the given map.
 *
 * @param template Template string with optional placeholders.
 * @param variables Map of variable name to value.
 * @returns Resolved string (unresolved placeholders are left intact).
 */
export function resolveTemplate(template: string | null | undefined, variables: StringMap): string {
  if (!template) {
    return '';
  }

  return String(template).replace(VAR_PATTERN, (_match, key: string) =>
    Object.prototype.hasOwnProperty.call(variables, key) ? variables[key]! : `{{${key}}}`,
  );
}

/**
 * Resolve every value in a string map against the same variable map.
 *
 * @param values Map whose values may contain placeholders.
 * @param variables Variable values used for substitution.
 * @returns New map with resolved values.
 */
export function resolveMap(values: StringMap | null | undefined, variables: StringMap): StringMap {
  const out: StringMap = {};
  Object.keys(values ?? {}).forEach((key) => {
    out[key] = resolveTemplate(values![key], variables);
  });

  return out;
}

/**
 * Detect whether the text still contains unresolved `{{var}}` placeholders.
 *
 * @param text Text to inspect.
 * @returns `true` when at least one placeholder remains.
 */
export function hasUnresolved(text: string | null | undefined): boolean {
  VAR_PATTERN.lastIndex = 0;

  return VAR_PATTERN.test(text ?? '');
}

/**
 * Truncate text for UI snippets, appending an ellipsis when over the limit.
 *
 * @param text Source text.
 * @param max Maximum length before truncation (default 200).
 * @returns Truncated or original string.
 */
export function truncateSnippet(text: string | null | undefined, max = 200): string {
  if (!text) {
    return '';
  }

  return text.length > max ? `${text.slice(0, max)}…` : text;
}

/**
 * Escape double quotes for safe use inside HTML attribute values.
 *
 * @param value Raw attribute value.
 * @returns Escaped string.
 */
export function escapeHtmlAttr(value: string): string {
  return value.replace(/"/g, '&quot;');
}
