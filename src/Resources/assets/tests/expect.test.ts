import { describe, expect as vexpect, it } from 'vitest';
import { deepEqual, expect } from '../src/lib/expect';

/** Spec 003 (1.15): the chai BDD subset of pm.expect, in the inline runtime and the sandbox alike. */
type Chain = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const e = (value: unknown): Chain => expect(value) as Chain;

describe('pm.expect (chai subset)', () => {
  it('keeps the 1.x equal message', () => {
    vexpect(() => e(1).to.equal(2)).toThrow('Expected 2 but got 1');
    vexpect(() => e('a').to.equal('a')).not.toThrow();
  });

  it('supports chains, negation and the common assertions', () => {
    e({ a: [1, { b: 2 }] }).to.eql({ a: [1, { b: 2 }] });
    e(true).to.be.true;
    e(false).to.be.false;
    e(null).to.be.null;
    e(undefined).to.be.undefined;
    e('x').to.be.ok;
    e([]).to.be.empty;
    e({}).to.be.empty;
    e('').to.be.empty;
    e([1]).to.be.an('array');
    e(null).to.be.a('null');
    e('abc').to.be.a('string').and.to.include('b');
    e([1, { a: 1 }]).to.include({ a: 1 });
    e({ a: 1, b: 2 }).to.include({ b: 2 });
    e({ id: 7 }).to.have.property('id', 7);
    e({ id: 7 }).to.have.property('id');
    e([1, 2, 3]).to.have.lengthOf(3);
    e(5).to.be.above(4).and.below(6);
    e(5).to.be.at.least(5).and.at.most(5);
    e(201).to.be.oneOf([200, 201]);
    e('Bearer x').to.match(/^Bearer /);
    e(1).to.not.equal(2);
    e([]).not.to.include(1);
    e({ a: 1 }).to.not.have.property('b');
  });

  it('reports readable failures', () => {
    vexpect(() => e([1, 2]).to.have.lengthOf(3)).toThrow('Expected [1,2] to have a length of 3 but got 2');
    vexpect(() => e({ a: 1 }).to.eql({ a: 2 })).toThrow('to deeply equal');
    vexpect(() => e(1).to.not.equal(1)).toThrow('not to equal');
    vexpect(() => e('x').to.be.a('number')).toThrow('to be a number');
    vexpect(() => e(3).to.be.above(5)).toThrow('above 5');
    vexpect(() => e('a').to.match(/b/)).toThrow('to match');
    vexpect(() => e(1).to.be.oneOf([2])).toThrow('one of');
    vexpect(() => e(0).to.be.ok).toThrow('truthy');
    vexpect(() => e(1).to.be.true).toThrow('to be true');
  });

  it('compares deeply', () => {
    vexpect(deepEqual([1, { a: [2] }], [1, { a: [2] }])).toBe(true);
    vexpect(deepEqual([1], { 0: 1 })).toBe(false);
    vexpect(deepEqual({ a: 1 }, { a: 1, b: 2 })).toBe(false);
    vexpect(deepEqual(NaN, NaN)).toBe(true);
  });
});
