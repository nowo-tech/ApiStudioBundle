/**
 * Server runner guard for Node (spec 003, 1.15; audit RW-L1).
 *
 * @packageDocumentation
 */

export type NodeLike = {
  stdin: AsyncIterable<Uint8Array>;
  stdout: { write: (data: string, callback: () => void) => boolean };
  exit: (code: number) => never;
  versions?: { node?: string };
  permission?: { has?: (scope: string, reference?: string) => boolean };
};

/**
 * Why this Node cannot run scripts, or null (audit RW-L1): only Node 25+ blocks the network under `--permission`.
 * On older Node, or without `--permission`, a script reaches the network with `fetch` or `node:http` (a full-read SSRF:
 * internal services, cloud metadata endpoints), so the runner refuses every run instead.
 */
export function nodeRefusal(node: NodeLike | undefined): string | null {
  if (!node) {
    return null;
  }
  const major = Number(String(node.versions?.node ?? '0').split('.')[0]);
  if (!Number.isInteger(major) || major < 25) {
    return `The script runner refuses Node ${node.versions?.node ?? '?'}: Node 25 or later is required (older Node cannot block the network under --permission). Use Deno (the default command) or Node 25+ with --permission.`;
  }
  let netAllowed = true;
  try {
    netAllowed = typeof node.permission?.has !== 'function' || node.permission.has('net');
  } catch {
    netAllowed = true;
  }
  if (netAllowed) {
    return 'The script runner refuses Node without --permission (or with network access granted): scripts would reach the network.';
  }

  return null;
}

