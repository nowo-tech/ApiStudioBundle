import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Deterministic source hash: a hash of the TypeScript/CSS sources and pnpm-lock.yaml. It only changes when the code or
 * the dependencies change; it is the change detector of buildTimeLabel() below.
 *
 * @param {string} root - The bundle root (holds `pnpm-lock.yaml`)
 * @param {string} dir - Source directory to hash, relative to the root
 * @returns {string} The first 12 hex characters of the sha256
 */
export function sourceBuildId(root, dir) {
  const base = resolve(root, dir);
  const hash = createHash('sha256');
  const files = readdirSync(base, { recursive: true })
    .map(String)
    .filter((file) => !file.includes('.test.') && statSync(resolve(base, file)).isFile())
    .sort();
  for (const file of files) {
    hash.update(file).update(readFileSync(resolve(base, file)));
  }
  hash.update(readFileSync(resolve(root, 'pnpm-lock.yaml')));

  return hash.digest('hex').slice(0, 12);
}

/**
 * Readable, reproducible build time for the "script loaded" console line ("2026-11-23 14:05 UTC").
 * The committed `build-info.json` ({ sourceHash, builtAt }) keeps it stable: when the source hash equals the stored
 * one, the stored `builtAt` is reused (a rebuild of unchanged sources never rewrites the committed assets); when it
 * differs, the new hash is stored with the current UTC minute. Commit `build-info.json` with the rebuilt assets: a CI
 * dist-freshness rebuild of a clean checkout then reproduces the same bytes, and fails visibly if it was forgotten.
 *
 * @param {string} root - The bundle root (holds `pnpm-lock.yaml` and `build-info.json`)
 * @param {string} dir - Source directory to hash, relative to the root
 * @returns {string} The build time label
 */
export function buildTimeLabel(root, dir) {
  const sourceHash = sourceBuildId(root, dir);
  const file = resolve(root, 'build-info.json');
  let info = {};
  try {
    info = JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    // Missing or unreadable: written below.
  }
  if (info.sourceHash !== sourceHash || typeof info.builtAt !== 'string') {
    info = { sourceHash, builtAt: `${new Date().toISOString().slice(0, 16)}Z` };
    writeFileSync(file, `${JSON.stringify(info, null, 2)}\n`);
    console.warn(`build-info.json updated (${info.builtAt}): commit it together with the rebuilt assets.`);
  }

  return info.builtAt.replace('T', ' ').replace('Z', ' UTC');
}
