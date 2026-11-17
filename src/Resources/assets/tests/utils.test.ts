import { describe, expect, it } from 'vitest';
import { escapeHtmlAttr, hasUnresolved, parseJson, resolveMap, resolveTemplate, truncateSnippet } from '../src/lib/utils';

describe('lib/utils', () => {
  it('parses JSON and falls back on empty or invalid input', () => {
    expect(parseJson('{"a":1}', {})).toEqual({ a: 1 });
    expect(parseJson(undefined, 'fallback')).toBe('fallback');
    expect(parseJson('', 7)).toBe(7);
    expect(parseJson('{nope', [])).toEqual([]);
  });

  it('resolves placeholders and leaves the unknown ones intact', () => {
    expect(resolveTemplate('{{ host }}/a/{{id}}/{{missing}}', { host: 'h', id: '7' })).toBe('h/a/7/{{missing}}');
    expect(resolveTemplate(null, {})).toBe('');
    expect(resolveTemplate(undefined, {})).toBe('');
    expect(resolveTemplate('', {})).toBe('');
  });

  it('resolves every value of a map', () => {
    expect(resolveMap({ a: '{{x}}', b: 'plain' }, { x: '1' })).toEqual({ a: '1', b: 'plain' });
    expect(resolveMap(null, {})).toEqual({});
    expect(resolveMap(undefined, { x: '1' })).toEqual({});
  });

  it('detects unresolved placeholders, whatever the previous call left in the regex state', () => {
    expect(hasUnresolved('a {{b}} c')).toBe(true);
    expect(hasUnresolved('a {{b}} c')).toBe(true);
    expect(hasUnresolved('none')).toBe(false);
    expect(hasUnresolved(null)).toBe(false);
    expect(hasUnresolved(undefined)).toBe(false);
  });

  it('truncates snippets', () => {
    expect(truncateSnippet('abcdef', 3)).toBe('abc…');
    expect(truncateSnippet('abc', 3)).toBe('abc');
    expect(truncateSnippet('x'.repeat(250))).toBe(`${'x'.repeat(200)}…`);
    expect(truncateSnippet(null)).toBe('');
    expect(truncateSnippet('')).toBe('');
  });

  it('escapes double quotes for attributes', () => {
    expect(escapeHtmlAttr('say "hi"')).toBe('say &quot;hi&quot;');
  });
});
