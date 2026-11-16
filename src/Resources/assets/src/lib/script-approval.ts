/**
 * Approval of stored pre / post request scripts before the console runs them (`scripts.confirm_stored`, 1.13.2).
 *
 * A stored script (service scripts, and endpoint scripts saved on the server) runs in the page with the session of
 * whoever sends the request. With confirmation on, the console shows the code first; an approval is remembered for
 * the browser session per SHA-256 of the exact code, so any change to the code asks again. Code the user typed in the
 * console (an endpoint script field that was empty when the page loaded) is the user's own and is never asked.
 *
 * @packageDocumentation
 */

/** sessionStorage key of the approved script hashes (JSON array of lowercase hex SHA-256 digests). */
export const APPROVAL_STORAGE_KEY = 'nowo_api_studio_script_approvals';

/** Oldest approvals are dropped beyond this many hashes. */
export const MAX_APPROVALS = 200;

export type StoredScript = {
  label: string;
  code: string;
};

export type PendingScript = StoredScript & {
  /** Null when the browser offers no Web Crypto digest (insecure context): the script is then asked every time. */
  hash: string | null;
};

/**
 * Lowercase hex SHA-256 of the UTF-8 text, or null without `crypto.subtle` (plain-HTTP origins other than localhost).
 */
export async function sha256Hex(text: string): Promise<string | null> {
  const subtle = globalThis.crypto?.subtle;
  if (!subtle) {
    return null;
  }

  try {
    const digest = await subtle.digest('SHA-256', new TextEncoder().encode(text));

    return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
  } catch {
    return null;
  }
}

function safeStorage(storage?: Storage | null): Storage | null {
  if (storage !== undefined) {
    return storage;
  }

  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
}

/** Approved hashes of this browser session (empty when storage is unavailable or holds something else). */
export function loadApprovals(storage?: Storage | null): string[] {
  const store = safeStorage(storage);
  if (!store) {
    return [];
  }

  try {
    const parsed: unknown = JSON.parse(store.getItem(APPROVAL_STORAGE_KEY) || '[]');

    return Array.isArray(parsed) ? parsed.filter((h): h is string => typeof h === 'string' && /^[0-9a-f]{64}$/.test(h)) : [];
  } catch {
    return [];
  }
}

/** Adds the hashes to the session approvals (null hashes are never remembered). */
export function rememberApprovals(hashes: Array<string | null>, storage?: Storage | null): void {
  const store = safeStorage(storage);
  if (!store) {
    return;
  }

  const approvals = loadApprovals(store);
  hashes.forEach((hash) => {
    if (hash && !approvals.includes(hash)) {
      approvals.push(hash);
    }
  });

  try {
    store.setItem(APPROVAL_STORAGE_KEY, JSON.stringify(approvals.slice(-MAX_APPROVALS)));
  } catch {
    // Storage full or blocked: the scripts are asked again next time.
  }
}

/**
 * The stored scripts that still need an approval: non-empty and whose code hash this session has not approved.
 */
export async function pendingScripts(scripts: StoredScript[], storage?: Storage | null): Promise<PendingScript[]> {
  const approvals = loadApprovals(storage);
  const pending: PendingScript[] = [];

  for (const script of scripts) {
    if (!script.code || !script.code.trim()) {
      continue;
    }

    const hash = await sha256Hex(script.code);
    if (hash === null || !approvals.includes(hash)) {
      pending.push({ ...script, hash });
    }
  }

  return pending;
}

/**
 * An endpoint script field holds stored code when it was not empty as rendered by the server (`defaultValue`): the
 * current value is then checked, even after the user edited it. A field that was empty only holds what the user typed.
 */
export function isStoredField(field: HTMLTextAreaElement | null): boolean {
  return Boolean(field && field.defaultValue.trim() !== '');
}
