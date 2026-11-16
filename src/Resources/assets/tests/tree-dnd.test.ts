import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * 1.14 folders: drag & drop of the API tree (_sidebar_tree / _sidebar_folders / _sidebar_endpoints markup).
 */
const TREE = `
<div class="as-tree-svc" data-tree-drop="root"><a href="/svc/1">Svc</a></div>
<div class="as-tree-folders" data-tree-service="1" data-tree-root data-tree-move-url="/move" data-tree-csrf="tok" data-tree-move-error="Could not move">
  <div class="as-tree-folder is-open" data-tree-folder data-tree-node="folder" data-tree-id="10" data-tree-drop="folder" draggable="true">
    <div class="as-tree-folder-label">
      <button type="button" class="as-tree-chevron" data-tree-folder-toggle aria-expanded="true"></button>
      <a href="/f/10" id="f10">Users</a>
    </div>
    <div class="as-tree-folder-children" data-tree-children>
      <div class="as-tree-folder is-open" data-tree-folder data-tree-node="folder" data-tree-id="11" data-tree-drop="folder" draggable="true">
        <div class="as-tree-folder-label"><a href="/f/11" id="f11">Admin</a></div>
        <div class="as-tree-folder-children" data-tree-children></div>
      </div>
      <div class="as-tree-endpoint-group" data-tree-endpoint-group data-tree-node="endpoint" data-tree-id="1" draggable="true"><a id="e1">One</a></div>
    </div>
  </div>
  <div class="as-tree-endpoint-group" data-tree-endpoint-group data-tree-node="endpoint" data-tree-id="2" draggable="true"><a id="e2">Two</a></div>
  <div class="as-tree-endpoint-group" data-tree-endpoint-group data-tree-node="endpoint" data-tree-id="3" draggable="true"><a id="e3">Three</a></div>
</div>`;

async function load(response: { ok: boolean; body: unknown }): Promise<ReturnType<typeof vi.fn>> {
  document.body.innerHTML = TREE;
  const fetchMock = vi.fn().mockResolvedValue({ ok: response.ok, json: async () => response.body });
  vi.stubGlobal('fetch', fetchMock);
  vi.resetModules();
  await import('../src/api-studio-shell');

  return fetchMock;
}

function fire(type: string, target: Element): Event {
  const event = new Event(type, { bubbles: true, cancelable: true });
  target.dispatchEvent(event);

  return event;
}

async function drag(from: Element, to: Element): Promise<void> {
  fire('dragstart', from);
  fire('dragover', to);
  fire('drop', to);
  for (let i = 0; i < 10; i++) {
    await Promise.resolve();
  }
}

const node = (id: string): HTMLElement => document.querySelector<HTMLElement>(`[data-tree-id="${id}"]`)!;

describe('API tree drag & drop (folders)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('moves an endpoint into a folder and updates the tree', async () => {
    const fetchMock = await load({ ok: true, body: { ok: true } });
    await drag(node('2'), document.getElementById('f11')!);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0]! as [string, RequestInit];
    expect(url).toBe('/move');
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
    expect(JSON.parse(String(init.body))).toEqual({ type: 'endpoint', id: 2, parent: 11, before: null });
    expect(node('11').querySelector('[data-tree-children]')!.contains(node('2'))).toBe(true);
  });

  it('places an endpoint before another of the same level', async () => {
    const fetchMock = await load({ ok: true, body: { ok: true } });
    await drag(node('3'), document.getElementById('e2')!);

    expect(JSON.parse(String((fetchMock.mock.calls[0]! as [string, RequestInit])[1].body))).toEqual({ type: 'endpoint', id: 3, parent: null, before: 2 });
    expect(node('3').nextElementSibling).toBe(node('2'));
  });

  it('moves a folder to the service root through the service row', async () => {
    const fetchMock = await load({ ok: true, body: { ok: true } });
    await drag(node('11'), document.querySelector('[data-tree-drop="root"] a')!);

    expect(JSON.parse(String((fetchMock.mock.calls[0]! as [string, RequestInit])[1].body))).toEqual({ type: 'folder', id: 11, parent: null, before: null });
    // Folders stay before the endpoints of their level.
    expect(node('11').nextElementSibling).toBe(node('2'));
  });

  it('never drops a folder into itself or a descendant', async () => {
    const fetchMock = await load({ ok: true, body: { ok: true } });
    const over = fire('dragstart', node('10')) && fire('dragover', document.getElementById('f11')!);
    fire('drop', document.getElementById('f11')!);

    expect((over as Event).defaultPrevented).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('reports a refused move and leaves the tree as it was', async () => {
    await load({ ok: false, body: { error: 'Folders cannot be nested deeper than 3 levels.' } });
    await drag(node('3'), document.getElementById('f10')!);

    expect(document.querySelector('.as-tree-move-error')!.textContent).toBe('Folders cannot be nested deeper than 3 levels.');
    expect(node('3').parentElement!.hasAttribute('data-tree-root')).toBe(true);
  });

  it('toggles a folder with its chevron', async () => {
    await load({ ok: true, body: { ok: true } });
    const button = node('10').querySelector<HTMLButtonElement>('[data-tree-folder-toggle]')!;
    button.click();

    expect(node('10').classList.contains('is-open')).toBe(false);
    expect(button.getAttribute('aria-expanded')).toBe('false');
  });
});
