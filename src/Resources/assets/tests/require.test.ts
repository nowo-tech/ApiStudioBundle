import { describe, expect, it } from 'vitest';
import { createPm, runScript } from '../src/lib/pm';
import { ALLOWED_LIBRARIES, scriptRequire } from '../src/lib/require';
import { emptyScopes, executeRun } from '../src/lib/sandbox';

/** Spec 003 (1.15): the closed require() allow-list of sandboxed and server-side scripts. */
describe('require() allow-list', () => {
  it('lists exactly the bundled libraries', () => {
    expect([...ALLOWED_LIBRARIES].sort()).toEqual(['ajv', 'chai', 'crypto-js', 'dayjs', 'fast-xml-parser', 'lodash', 'moment', 'uuid']);
  });

  it('hands out working libraries', () => {
    const _ = scriptRequire('lodash') as { chunk: (a: unknown[], n: number) => unknown[][] };
    expect(_.chunk([1, 2, 3], 2)).toEqual([[1, 2], [3]]);

    const dayjs = scriptRequire('dayjs') as (d: string) => { add: (n: number, u: string) => { format: (f: string) => string } };
    expect(dayjs('2026-01-31').add(1, 'day').format('YYYY-MM-DD')).toBe('2026-02-01');
    expect(scriptRequire('moment')).toBe(scriptRequire('dayjs'));

    const CryptoJS = scriptRequire('crypto-js') as { SHA256: (s: string) => { toString: () => string } };
    expect(CryptoJS.SHA256('abc').toString()).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');

    const Ajv = scriptRequire('ajv') as new () => { compile: (s: object) => (d: unknown) => boolean };
    const valid = new Ajv().compile({ type: 'object', required: ['id'], properties: { id: { type: 'integer' } } });
    expect(valid({ id: 1 })).toBe(true);
    expect(valid({ id: 'x' })).toBe(false);

    const uuid = scriptRequire('uuid') as { v4: () => string; validate: (s: string) => boolean };
    expect(uuid.validate(uuid.v4())).toBe(true);

    const xml = scriptRequire('fast-xml-parser') as { XMLParser: new () => { parse: (s: string) => unknown } };
    expect(new xml.XMLParser().parse('<a><b>1</b></a>')).toEqual({ a: { b: 1 } });

    const chai = scriptRequire('chai') as { expect: (v: unknown) => { to: { equal: (v: unknown) => void } } };
    expect(() => chai.expect(1).to.equal(2)).toThrow();
  });

  it('refuses anything else', () => {
    for (const name of ['fs', 'child_process', 'axios', '__proto__', 'constructor', 'hasOwnProperty', 'lodash/fp', 7]) {
      expect(() => scriptRequire(name)).toThrow(/is not available\. Allowed: ajv, chai/);
    }
  });

  it('is available to sandboxed scripts and not to inline ones', async () => {
    const result = await executeRun(
      {
        v: 1,
        kind: 'run',
        nonce: 'n',
        runTests: false,
        scripts: [{ label: 'pre', code: "pm.console.log(require('lodash').sum([1, 2, 3]))" }],
        state: { variables: {}, dirty: {}, request: { body: '', headers: {}, queryParams: {} }, response: null, logs: [], tests: [], scopes: emptyScopes() },
      },
      undefined,
      scriptRequire,
    );
    expect(result.state.logs).toEqual(['[pre] 6']);

    expect(() => runScript("require('lodash')", createPm({}).pm)).toThrow("require('lodash') needs scripts.mode: sandbox (or the server runner).");
  });
});
