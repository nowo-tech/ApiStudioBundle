import { describe, expect, it } from 'vitest';
import {
  APPROVAL_STORAGE_KEY,
  MAX_APPROVALS,
  hasDynamicCode,
  isStoredField,
  loadApprovals,
  pendingScripts,
  rememberApprovals,
  revealHiddenCharacters,
  scriptStats,
  sha256Hex,
} from '../src/lib/script-approval';

/** scripts.confirm_stored (1.13.2): hash and session approval of stored scripts. */
class MemoryStorage implements Storage {
  private data = new Map<string, string>();
  get length(): number {
    return this.data.size;
  }
  clear(): void {
    this.data.clear();
  }
  getItem(key: string): string | null {
    return this.data.has(key) ? this.data.get(key)! : null;
  }
  key(index: number): string | null {
    return [...this.data.keys()][index] ?? null;
  }
  removeItem(key: string): void {
    this.data.delete(key);
  }
  setItem(key: string, value: string): void {
    this.data.set(key, value);
  }
}

describe('script approval (scripts.confirm_stored)', () => {
  it('hashes the exact code with SHA-256', async () => {
    expect(await sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    expect(await sha256Hex('abc ')).not.toBe(await sha256Hex('abc'));
  });

  it('asks for every non-empty stored script until it is approved', async () => {
    const storage = new MemoryStorage();
    const scripts = [
      { label: 'service pre', code: "pm.environment.set('a', 1)" },
      { label: 'service post', code: '   ' },
      { label: 'pre-request', code: '' },
    ];

    const pending = await pendingScripts(scripts, storage);
    expect(pending.map((p) => p.label)).toEqual(['service pre']);
    expect(pending[0]!.hash).toMatch(/^[0-9a-f]{64}$/);

    rememberApprovals(pending.map((p) => p.hash), storage);
    expect(await pendingScripts(scripts, storage)).toEqual([]);
  });

  it('asks again when the approved code changes', async () => {
    const storage = new MemoryStorage();
    const first = await pendingScripts([{ label: 'service pre', code: 'pm.console.log(1)' }], storage);
    rememberApprovals(first.map((p) => p.hash), storage);

    const changed = await pendingScripts([{ label: 'service pre', code: 'pm.console.log(2)' }], storage);
    expect(changed).toHaveLength(1);
  });

  it('never remembers a missing hash and ignores foreign storage content', () => {
    const storage = new MemoryStorage();
    rememberApprovals([null], storage);
    expect(loadApprovals(storage)).toEqual([]);

    storage.setItem(APPROVAL_STORAGE_KEY, '{"not":"a list"}');
    expect(loadApprovals(storage)).toEqual([]);
    storage.setItem(APPROVAL_STORAGE_KEY, '[1, "x", "' + 'a'.repeat(64) + '"]');
    expect(loadApprovals(storage)).toEqual(['a'.repeat(64)]);
    storage.setItem(APPROVAL_STORAGE_KEY, 'not json');
    expect(loadApprovals(storage)).toEqual([]);
    expect(loadApprovals(null)).toEqual([]);
    rememberApprovals(['b'.repeat(64)], null);
  });

  it('keeps at most MAX_APPROVALS hashes, dropping the oldest', () => {
    const storage = new MemoryStorage();
    const hashes = Array.from({ length: MAX_APPROVALS + 5 }, (_, i) => i.toString(16).padStart(64, '0'));
    rememberApprovals(hashes, storage);
    const kept = loadApprovals(storage);
    expect(kept).toHaveLength(MAX_APPROVALS);
    expect(kept[0]).toBe(hashes[5]);
  });

  it('asks every time without Web Crypto (insecure context)', async () => {
    const original = globalThis.crypto;
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });
    try {
      expect(await sha256Hex('abc')).toBeNull();
      const storage = new MemoryStorage();
      const pending = await pendingScripts([{ label: 'service pre', code: 'x()' }], storage);
      rememberApprovals(pending.map((p) => p.hash), storage);
      expect(await pendingScripts([{ label: 'service pre', code: 'x()' }], storage)).toHaveLength(1);
    } finally {
      Object.defineProperty(globalThis, 'crypto', { value: original, configurable: true });
    }
  });

  it('treats an endpoint field as stored only when the server rendered code in it', () => {
    const typed = document.createElement('textarea');
    typed.value = 'pm.console.log("mine")';
    expect(isStoredField(typed)).toBe(false);

    const stored = document.createElement('textarea');
    stored.textContent = 'pm.console.log("saved")';
    stored.value = 'edited';
    expect(isStoredField(stored)).toBe(true);
    expect(isStoredField(null)).toBe(false);
  });

  it('falls back to window.sessionStorage', () => {
    window.sessionStorage.removeItem(APPROVAL_STORAGE_KEY);
    rememberApprovals(['c'.repeat(64)]);
    expect(loadApprovals()).toEqual(['c'.repeat(64)]);
    window.sessionStorage.removeItem(APPROVAL_STORAGE_KEY);
  });

  it('reveals bidi, zero-width and control characters (RC-M1)', () => {
    const code = "const ok = 1; /*\u202E*/ steal()\u200B;\u2066x\u2069\uFEFF\u0007\u0085";
    const revealed = revealHiddenCharacters(code);
    expect(revealed.hidden).toBe(7);
    expect(revealed.text).toContain('⟨U+202E⟩');
    expect(revealed.text).toContain('⟨U+200B⟩');
    expect(revealed.text).toContain('⟨U+2066⟩');
    expect(revealed.text).toContain('⟨U+2069⟩');
    expect(revealed.text).toContain('⟨U+FEFF⟩');
    expect(revealed.text).toContain('⟨U+0007⟩');
    expect(revealed.text).toContain('⟨U+0085⟩');
  });

  it('keeps line feeds, tabs and CRLF line ends but flags a lone carriage return', () => {
    expect(revealHiddenCharacters('a\n\tb\r\nc')).toEqual({ text: 'a\n\tb\r\nc', hidden: 0 });
    expect(revealHiddenCharacters('visible\rhidden').hidden).toBe(1);
  });

  it('counts lines and characters so padding is visible', () => {
    expect(scriptStats('')).toEqual({ lines: 0, chars: 0 });
    expect(scriptStats('a\r\nb\nc')).toEqual({ lines: 3, chars: 6 });
    expect(scriptStats('x' + '\n'.repeat(200) + 'steal()').lines).toBe(201);
    expect(scriptStats('😀').chars).toBe(1);
  });

  it('flags code built at run time', () => {
    expect(hasDynamicCode('eval(x)')).toBe(true);
    expect(hasDynamicCode('new Function ("a")')).toBe(true);
    expect(hasDynamicCode('await import("x")')).toBe(true);
    expect(hasDynamicCode('pm.environment.set("evaluation", 1)')).toBe(false);
  });
});
