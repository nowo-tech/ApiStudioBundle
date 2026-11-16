/**
 * The chai BDD subset of `pm.expect` (spec 003, 1.15): `to` / `be` / `been` / `is` / `that` / `which` / `and` / `has` /
 * `have` / `with` / `at` / `of` / `same` chains, `not`, and the assertions `equal`, `eql` (deep), `true`, `false`,
 * `null`, `undefined`, `ok`, `empty`, `a` / `an`, `include` / `contain`, `property`, `lengthOf`, `above`, `below`,
 * `least`, `most`, `oneOf`, `match`. Messages keep the 1.x wording for `equal` ("Expected X but got Y").
 *
 * @packageDocumentation
 */

export type Assertion = {
  [chain: string]: unknown;
};

function show(value: unknown): string {
  if (typeof value === 'string') {
    return value;
  }
  try {
    return JSON.stringify(value) ?? String(value);
  } catch {
    return String(value);
  }
}

/** Deep equality for JSON-like values (objects, arrays, scalars). */
export function deepEqual(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) {
    return true;
  }
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) {
    return false;
  }
  if (Array.isArray(a) !== Array.isArray(b)) {
    return false;
  }
  const ka = Object.keys(a as object);
  const kb = Object.keys(b as object);
  if (ka.length !== kb.length) {
    return false;
  }

  return ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && deepEqual((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k]));
}

function typeName(value: unknown): string {
  if (value === null) {
    return 'null';
  }
  if (Array.isArray(value)) {
    return 'array';
  }

  return typeof value;
}

function lengthOf(value: unknown): number | null {
  if (typeof value === 'string' || Array.isArray(value)) {
    return value.length;
  }
  if (value instanceof Map || value instanceof Set) {
    return value.size;
  }

  return null;
}

const CHAINS = ['to', 'be', 'been', 'is', 'that', 'which', 'and', 'has', 'have', 'with', 'at', 'of', 'same', 'does', 'deep'];

/**
 * `pm.expect(value)`.
 */
export function expect(value: unknown): Assertion {
  const build = (negate: boolean): Assertion => {
    const check = (pass: boolean, message: string, negatedMessage: string): Assertion => {
      if (pass === negate) {
        throw new Error(negate ? negatedMessage : message);
      }

      return assertion;
    };

    const assertion: Assertion = {};
    CHAINS.forEach((chain) => {
      Object.defineProperty(assertion, chain, { get: () => assertion, enumerable: false });
    });
    Object.defineProperty(assertion, 'not', { get: () => build(!negate), enumerable: false });

    const getters: Record<string, () => Assertion> = {
      true: () => check(value === true, `Expected ${show(value)} to be true`, `Expected ${show(value)} not to be true`),
      false: () => check(value === false, `Expected ${show(value)} to be false`, `Expected ${show(value)} not to be false`),
      null: () => check(value === null, `Expected ${show(value)} to be null`, `Expected value not to be null`),
      undefined: () => check(value === undefined, `Expected ${show(value)} to be undefined`, `Expected value not to be undefined`),
      ok: () => check(Boolean(value), `Expected ${show(value)} to be truthy`, `Expected ${show(value)} to be falsy`),
      empty: () => {
        const len = lengthOf(value);
        const empty = len !== null ? len === 0 : typeof value === 'object' && value !== null && Object.keys(value).length === 0;

        return check(empty, `Expected ${show(value)} to be empty`, `Expected ${show(value)} not to be empty`);
      },
    };
    Object.entries(getters).forEach(([name, get]) => {
      Object.defineProperty(assertion, name, { get, enumerable: false });
    });

    const methods: Record<string, (...args: unknown[]) => Assertion> = {
      equal: (expected) => check(value === expected, `Expected ${show(expected)} but got ${show(value)}`, `Expected ${show(value)} not to equal ${show(expected)}`),
      eql: (expected) => check(deepEqual(value, expected), `Expected ${show(value)} to deeply equal ${show(expected)}`, `Expected ${show(value)} not to deeply equal ${show(expected)}`),
      a: (type) => check(typeName(value) === String(type).toLowerCase(), `Expected ${show(value)} to be a ${String(type)}`, `Expected ${show(value)} not to be a ${String(type)}`),
      include: (part) => {
        let pass = false;
        if (typeof value === 'string') {
          pass = value.includes(String(part));
        } else if (Array.isArray(value)) {
          pass = value.some((item) => deepEqual(item, part));
        } else if (typeof value === 'object' && value !== null && typeof part === 'object' && part !== null) {
          pass = Object.entries(part).every(([k, v]) => deepEqual((value as Record<string, unknown>)[k], v));
        }

        return check(pass, `Expected ${show(value)} to include ${show(part)}`, `Expected ${show(value)} not to include ${show(part)}`);
      },
      property: (...args) => {
        const [name, expected] = args;
        const has = typeof value === 'object' && value !== null && Object.prototype.hasOwnProperty.call(value, String(name));
        const pass = has && (args.length < 2 || deepEqual((value as Record<string, unknown>)[String(name)], expected));

        return check(pass, `Expected ${show(value)} to have property ${String(name)}${args.length > 1 ? ` of ${show(expected)}` : ''}`, `Expected ${show(value)} not to have property ${String(name)}`);
      },
      lengthOf: (n) => check(lengthOf(value) === n, `Expected ${show(value)} to have a length of ${String(n)} but got ${String(lengthOf(value))}`, `Expected ${show(value)} not to have a length of ${String(n)}`),
      above: (n) => check(typeof value === 'number' && value > Number(n), `Expected ${show(value)} to be above ${String(n)}`, `Expected ${show(value)} not to be above ${String(n)}`),
      below: (n) => check(typeof value === 'number' && value < Number(n), `Expected ${show(value)} to be below ${String(n)}`, `Expected ${show(value)} not to be below ${String(n)}`),
      least: (n) => check(typeof value === 'number' && value >= Number(n), `Expected ${show(value)} to be at least ${String(n)}`, `Expected ${show(value)} to be below ${String(n)}`),
      most: (n) => check(typeof value === 'number' && value <= Number(n), `Expected ${show(value)} to be at most ${String(n)}`, `Expected ${show(value)} to be above ${String(n)}`),
      oneOf: (list) => check(Array.isArray(list) && list.some((item) => deepEqual(item, value)), `Expected ${show(value)} to be one of ${show(list)}`, `Expected ${show(value)} not to be one of ${show(list)}`),
      match: (re) => check(re instanceof RegExp && typeof value === 'string' && re.test(value), `Expected ${show(value)} to match ${String(re)}`, `Expected ${show(value)} not to match ${String(re)}`),
    };
    methods.an = methods.a!;
    methods.contain = methods.include!;
    methods.contains = methods.include!;
    methods.includes = methods.include!;
    methods.eqls = methods.eql!;
    methods.equals = methods.equal!;
    methods.length = methods.lengthOf!;
    methods.gt = methods.above!;
    methods.lt = methods.below!;
    methods.gte = methods.least!;
    methods.lte = methods.most!;
    Object.entries(methods).forEach(([name, fn]) => {
      Object.defineProperty(assertion, name, { value: fn, enumerable: false });
    });

    return assertion;
  };

  return build(false);
}
