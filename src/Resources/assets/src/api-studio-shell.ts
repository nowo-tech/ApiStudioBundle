/**
 * Api Studio workbench UI (REQ-UI-003): API tree, service collections, endpoint rows, the service dialogs (UiKit
 * native <dialog>) and the request console sub-tabs. The page chrome (theme, locale, layout) belongs to UiKit / the
 * host layout.
 *
 * @packageDocumentation
 */
import { createBundleLogger } from './logger';

declare const __API_STUDIO_BUILD_TIME__: string;

createBundleLogger('api-studio/studio-shell', {
  buildTime: typeof __API_STUDIO_BUILD_TIME__ !== 'undefined' ? __API_STUDIO_BUILD_TIME__ : undefined,
}).scriptLoaded();

/*
 * Workspace rows (AS-A11Y-1): the toggle is the chevron <button data-tree-toggle> (native Enter / Space), with
 * aria-expanded kept in sync with the group. Host overrides still on the pre-1.12.4 markup (a role="button" row
 * wrapping the link) keep working: a click on the link navigates, and Enter / Space on the row toggles it.
 */
document.querySelectorAll<HTMLElement>('[data-tree-toggle]').forEach((el) => {
  const group = el.closest('[data-tree-group]');
  if (!group) {
    return;
  }

  function sync(open: boolean): void {
    el.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function toggle(): void {
    sync(group!.classList.toggle('is-open'));
  }

  sync(group.classList.contains('is-open'));

  el.addEventListener('click', (e) => {
    if (e.target instanceof Element && e.target.closest('a')) {
      return;
    }
    e.preventDefault();
    toggle();
  });

  if (el instanceof HTMLButtonElement) {
    return;
  }

  el.addEventListener('keydown', (e) => {
    if (e.target !== el) {
      return;
    }
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggle();
    }
  });
});

const filter = document.getElementById('as-tree-filter') as HTMLInputElement | null;
if (filter) {
  filter.addEventListener('input', () => {
    const q = filter.value.toLowerCase().trim();
    document.querySelectorAll<HTMLElement>('[data-tree-endpoint-group]').forEach((group) => {
      const text = group.getAttribute('data-tree-search') ?? '';
      group.style.display = !q || text.includes(q) ? '' : 'none';
    });
    document.querySelectorAll<HTMLElement>('[data-tree-item]').forEach((row) => {
      const text = row.getAttribute('data-tree-item') ?? '';
      row.style.display = !q || text.includes(q) ? '' : 'none';
    });
  });
}

document.querySelectorAll<HTMLButtonElement>('[data-service-collection-toggle]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    const collection = btn.closest('[data-service-collection]');
    const panel = collection?.querySelector<HTMLElement>('.as-service-collection-body');
    if (!collection || !panel) {
      return;
    }

    const open = collection.classList.toggle('is-open');
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
});

document.querySelectorAll<HTMLButtonElement>('[data-endpoint-toggle]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const block = btn.closest('[data-endpoint-block]');
    const panel = block?.querySelector<HTMLElement>('.as-endpoint-cases');
    if (!block || !panel) {
      return;
    }

    const open = block.classList.toggle('is-open');
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
});

document.querySelectorAll<HTMLButtonElement>('[data-tree-ep-toggle]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const group = btn.closest('[data-tree-endpoint-group]');
    const panel = group?.querySelector<HTMLElement>('.as-tree-use-cases');
    if (!group || !panel) {
      return;
    }

    const open = group.classList.toggle('is-open');
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
});

/**
 * The service Delete dialog is loaded when it opens (owner rule 2026-11-23, every dialog loads its content on demand): its
 * confirmation field carries the service name (`data-as-delete-expected`) and the Delete button (`data-as-delete-submit`,
 * bound to the form with `form="…"`) is enabled only while the typed text equals it. Delegated, so it also works for the
 * fragment that is inserted after the page loaded; the server checks the name again.
 */
document.addEventListener('input', (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-as-delete-expected') || !input.form) {
    return;
  }
  const submit = document.querySelector<HTMLButtonElement>(`button[data-as-delete-submit][form="${CSS.escape(input.form.id)}"]`);
  if (submit) {
    submit.disabled = input.value !== input.getAttribute('data-as-delete-expected');
  }
});

document.querySelectorAll<HTMLElement>('.as-subtabs[data-subtabs]').forEach((bar) => {
  const root = bar.closest('.as-console-request, .as-console-response') ?? bar.parentElement;
  if (!root) {
    return;
  }

  bar.querySelectorAll<HTMLButtonElement>('.as-subtab').forEach((tab) => {
    tab.addEventListener('click', () => {
      const name = tab.getAttribute('data-subtab');
      bar.querySelectorAll('.as-subtab').forEach((t) => {
        t.classList.toggle('is-active', t === tab);
      });
      root.querySelectorAll<HTMLElement>('.as-subpanel').forEach((p) => {
        p.classList.toggle('is-active', p.getAttribute('data-subpanel') === name);
      });
    });
  });
});

/*
 * 1.14 folders: folder rows of the API tree expand / collapse with their chevron button.
 */
document.querySelectorAll<HTMLButtonElement>('[data-tree-folder-toggle]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const folder = btn.closest('[data-tree-folder]');
    if (!folder) {
      return;
    }
    btn.setAttribute('aria-expanded', folder.classList.toggle('is-open') ? 'true' : 'false');
  });
});

type TreeMove = { type: 'folder' | 'endpoint'; id: number; parent: number | null; before: number | null };

/**
 * Folder of an element inside a service tree (its closest folder row below the root), or null for the root level.
 */
function folderIdOf(el: Element, root: Element): number | null {
  const folder = el.parentElement?.closest<HTMLElement>('[data-tree-folder]');
  if (!folder || !root.contains(folder)) {
    return null;
  }

  return Number(folder.dataset.treeId) || null;
}

/**
 * The move a drop of `dragged` on `target` means: onto a folder = into it (last); onto an endpoint = before it for an
 * endpoint, into the endpoint's folder for a folder; onto the service row = to the service root (last). Null when the
 * drop is not allowed (a folder into itself or one of its descendants, a node onto itself).
 */
function treeMoveFor(dragged: HTMLElement, target: HTMLElement, root: HTMLElement): TreeMove | null {
  const type = dragged.dataset.treeNode === 'folder' ? 'folder' : 'endpoint';
  const id = Number(dragged.dataset.treeId);
  if (!id || dragged === target || (type === 'folder' && dragged.contains(target))) {
    return null;
  }

  if (target.dataset.treeDrop === 'root') {
    return { type, id, parent: null, before: null };
  }
  if (target.dataset.treeNode === 'folder') {
    return { type, id, parent: Number(target.dataset.treeId) || null, before: null };
  }
  if (target.dataset.treeNode === 'endpoint') {
    const parent = folderIdOf(target, root);
    return { type, id, parent, before: type === 'endpoint' ? Number(target.dataset.treeId) || null : null };
  }

  return null;
}

/**
 * Applies a confirmed move to the DOM: folders stay before the endpoints of their level.
 */
function applyTreeMove(dragged: HTMLElement, move: TreeMove, root: HTMLElement): void {
  let container: Element = root;
  if (move.parent !== null) {
    const folder = root.querySelector(`[data-tree-folder][data-tree-id="${move.parent}"]`);
    const children = folder?.querySelector(':scope > [data-tree-children]');
    if (!children) {
      window.location.reload();
      return;
    }
    container = children;
  }

  if (move.type === 'endpoint') {
    const before = move.before !== null ? container.querySelector(`:scope > [data-tree-node="endpoint"][data-tree-id="${move.before}"]`) : null;
    container.insertBefore(dragged, before);
    return;
  }

  const firstEndpoint = container.querySelector(':scope > [data-tree-endpoint-group]');
  container.insertBefore(dragged, firstEndpoint);
}

document.querySelectorAll<HTMLElement>('[data-tree-root][data-tree-move-url]').forEach((root) => {
  const url = root.dataset.treeMoveUrl ?? '';
  const token = root.dataset.treeCsrf ?? '';
  const serviceRow = root.previousElementSibling instanceof HTMLElement && root.previousElementSibling.dataset.treeDrop === 'root'
    ? root.previousElementSibling
    : null;
  let dragged: HTMLElement | null = null;
  let status: HTMLElement | null = null;

  function report(message: string): void {
    if (!status) {
      status = document.createElement('p');
      status.className = 'as-tree-move-error';
      status.setAttribute('role', 'status');
      root.prepend(status);
    }
    status.textContent = message;
  }

  function dropTarget(e: Event): HTMLElement | null {
    const el = e.target instanceof Element ? e.target : null;
    if (!el) {
      return null;
    }
    if (serviceRow && serviceRow.contains(el)) {
      return serviceRow;
    }
    const node = el.closest<HTMLElement>('[data-tree-node]');
    return node && root.contains(node) ? node : null;
  }

  function clearTargets(): void {
    document.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
  }

  root.addEventListener('dragstart', (e) => {
    const node = e.target instanceof Element ? e.target.closest<HTMLElement>('[data-tree-node]') : null;
    if (!node || !root.contains(node)) {
      return;
    }
    e.stopPropagation();
    dragged = node;
    node.classList.add('is-dragging');
    const transfer = (e as DragEvent).dataTransfer;
    if (transfer) {
      transfer.effectAllowed = 'move';
      transfer.setData('text/plain', node.dataset.treeId ?? '');
    }
  });

  root.addEventListener('dragend', () => {
    dragged?.classList.remove('is-dragging');
    dragged = null;
    clearTargets();
  });

  const onOver = (e: Event): void => {
    const target = dropTarget(e);
    if (!dragged || !target || !treeMoveFor(dragged, target, root)) {
      return;
    }
    e.preventDefault();
    clearTargets();
    target.classList.add('is-drop-target');
  };

  const onDrop = async (e: Event): Promise<void> => {
    const target = dropTarget(e);
    const node = dragged;
    clearTargets();
    if (!node || !target) {
      return;
    }
    const move = treeMoveFor(node, target, root);
    if (!move) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(move),
      });
      const data = (await response.json()) as { ok?: boolean; error?: string };
      if (!response.ok || !data.ok) {
        report(data.error || root.dataset.treeMoveError || 'Move failed.');
        return;
      }
      if (status) {
        status.textContent = '';
      }
      applyTreeMove(node, move, root);
    } catch {
      report(root.dataset.treeMoveError || 'Move failed.');
    }
  };

  root.addEventListener('dragover', onOver);
  root.addEventListener('drop', (e) => void onDrop(e));
  serviceRow?.addEventListener('dragover', onOver);
  serviceRow?.addEventListener('drop', (e) => void onDrop(e));
});
