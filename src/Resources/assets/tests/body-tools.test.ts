import { describe, expect, it, vi } from 'vitest';

type Tools = NonNullable<typeof window.ApiStudioBodyTools>;

async function load(): Promise<Tools> {
  vi.resetModules();
  await import('../src/api-body-tools');

  return window.ApiStudioBodyTools as Tools;
}

describe('api-body-tools', () => {
  it('resolves the body format from the protocol and the content type', async () => {
    const tools = await load();

    expect(tools.resolveBodyFormat('soap')).toBe('xml');
    expect(tools.resolveBodyFormat('rest', 'application/XML; charset=utf-8')).toBe('xml');
    expect(tools.resolveBodyFormat('rest', 'application/soap+xml')).toBe('xml');
    expect(tools.resolveBodyFormat('rest', 'application/json')).toBe('json');
    expect(tools.resolveBodyFormat('rest')).toBe('json');
  });

  it('validates JSON, with and without variables', async () => {
    const tools = await load();

    expect(tools.validate('', 'json')).toEqual({ valid: true, with_variables: false, message: 'empty' });
    expect(tools.validate('  ', 'json')).toEqual({ valid: true, with_variables: false, message: 'empty' });
    expect(tools.validate('{"a":1}', 'json')).toEqual({ valid: true, with_variables: false, message: 'valid_json' });
    expect(tools.validate('{"a":"{{x}}"}', 'json')).toEqual({ valid: true, with_variables: true, message: 'valid_json_with_variables' });
    expect(tools.validate('{"a":{{x}}}', 'json')).toEqual({ valid: true, with_variables: true, message: 'valid_json_with_variables' });
    const invalid = tools.validate('{"a":', 'json');
    expect(invalid.valid).toBe(false);
    expect(invalid.message).not.toBe('');
  });

  it('validates XML, with and without variables', async () => {
    const tools = await load();

    expect(tools.validate('', 'xml')).toEqual({ valid: true, with_variables: false, message: 'empty' });
    expect(tools.validate('<a><b>1</b></a>', 'xml')).toEqual({ valid: true, with_variables: false, message: 'valid_xml' });
    expect(tools.validate('<a>{{x}}</a>', 'xml')).toMatchObject({ valid: true, with_variables: true });
    expect(tools.validate('<a {{attr}}="1"></a>', 'xml')).toMatchObject({ valid: true, with_variables: true });
    const invalid = tools.validate('<a><b></a>', 'xml');
    expect(invalid.valid).toBe(false);
    expect(invalid.message).not.toBe('');
  });

  it('formats JSON keeping the variables', async () => {
    const tools = await load();

    expect(tools.format('{"a":1,"b":[1,2]}', 'json')).toBe('{\n  "a": 1,\n  "b": [\n    1,\n    2\n  ]\n}\n');
    expect(tools.format('{"b":{{y}},"c":[{{z}}]}', 'json')).toContain('"{{y}}"');
    // A variable that is already inside a string is masked twice: the body cannot be formatted (the display keeps the raw text).
    expect(() => tools.format('{"a":"{{x}}"}', 'json')).toThrow();
    expect(tools.formatDisplay('{"a":"{{x}}"}', 'json')).toBe('{"a":"{{x}}"}');
    expect(tools.format('', 'json')).toBe('');
    expect(() => tools.format('{broken', 'json')).toThrow();
  });

  it('formats XML keeping the variables, attributes, empty elements and escaped text', async () => {
    const tools = await load();

    const formatted = tools.format('<root a="1 &amp; 2"><item>{{x}}</item><empty/><group><n>a &lt; b</n><m>2</m></group></root>', 'xml');

    expect(formatted).toContain('<?xml version="1.0" encoding="UTF-8"?>');
    expect(formatted).toContain('<root a="1 &amp; 2">');
    expect(formatted).toContain('<item>{{x}}</item>');
    expect(formatted).toContain('<empty />');
    expect(formatted).toContain('a &lt; b');
    expect(tools.format('  ', 'xml')).toBe('  ');
    expect(() => tools.format('<a><b></a>', 'xml')).toThrow();
  });

  it('formats for display and falls back to the raw text when it cannot', async () => {
    const tools = await load();

    expect(tools.formatDisplay('{"a":1}', 'json')).toBe('{\n  "a": 1\n}\n');
    expect(tools.formatDisplay('{broken', 'json')).toBe('{broken');
    expect(tools.formatDisplay('', 'json')).toBe('');
    expect(tools.formatDisplay(' ', 'xml')).toBe(' ');
    expect(tools.formatDisplay('<a>', 'xml')).toBe('<a>');
  });

  it('labels a validation message', async () => {
    const tools = await load();

    expect(tools.messageLabel({ valid: true, with_variables: false, message: 'valid_json' }, { valid_json: 'JSON is valid' })).toBe('JSON is valid');
    expect(tools.messageLabel({ valid: true, with_variables: false, message: 'valid_json' })).toBe('valid_json');
    expect(tools.messageLabel({ valid: false, with_variables: false, message: 'Unexpected end' }, { 'Unexpected end': 'ignored' })).toBe('Unexpected end');
  });
});
