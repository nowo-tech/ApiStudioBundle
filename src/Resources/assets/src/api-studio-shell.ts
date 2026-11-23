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
 * Open a UiKit dialog (native <dialog>); closing is handled by the UiKit dialog runtime ([data-nowo-ui-dialog-close],
 * Escape, backdrop).
 *
 * @param modal The dialog element.
 * @returns void
 */
function openModal(modal: HTMLElement | null): void {
  if (modal instanceof HTMLDialogElement && !modal.open) {
    modal.showModal();
  }
}

/**
 * Close the UiKit kebab menu (details-based or popover) that holds an item.
 *
 * @param item The clicked menu item.
 * @returns void
 */
function closeMenuOf(item: Element): void {
  item.closest('details')?.removeAttribute('open');
  const popover = item.closest<HTMLElement>('[popover]');
  if (popover && typeof popover.hidePopover === 'function' && popover.matches(':popover-open')) {
    popover.hidePopover();
  }
}

const exportModal = document.getElementById('as-service-export-modal');
const exportSubtitle = document.getElementById('as-service-export-subtitle');
const exportConfirm = document.getElementById('as-service-export-confirm');
let exportOpenApiUrl = '';

exportConfirm?.addEventListener('click', () => {
  const format = document.querySelector<HTMLInputElement>('input[name="as-export-format"]:checked')?.value;
  if (format === 'openapi' && exportOpenApiUrl) {
    window.location.assign(exportOpenApiUrl);
  }
});

const importModal = document.getElementById('as-service-import-modal');
const importSubtitle = document.getElementById('as-service-import-subtitle');
const importForm = document.getElementById('as-service-import-form') as HTMLFormElement | null;
const importFile = document.getElementById('as-service-import-file') as HTMLInputElement | null;
const importPostmanVars = document.getElementById('as-import-postman-vars');
let importOpenApiUrl = '';
let importPostmanUrl = '';

function syncImportForm(): void {
  if (!importForm) {
    return;
  }

  const format = document.querySelector<HTMLInputElement>('input[name="as-import-format"]:checked')?.value ?? 'openapi';
  importForm.action = format === 'postman' ? importPostmanUrl : importOpenApiUrl;
  if (importPostmanVars) {
    importPostmanVars.hidden = format !== 'postman';
  }
}

document.querySelectorAll<HTMLInputElement>('input[name="as-import-format"]').forEach((radio) => {
  radio.addEventListener('change', syncImportForm);
});

const deleteModal = document.getElementById('as-service-delete-modal');
const deleteForm = document.getElementById('as-service-delete-form') as HTMLFormElement | null;
const deleteWarning = document.getElementById('as-service-delete-warning');
const deleteLabel = document.getElementById('as-service-delete-label');
const deleteInput = document.getElementById('as-service-delete-input') as HTMLInputElement | null;
const deleteSubmit = document.getElementById('as-service-delete-submit') as HTMLButtonElement | null;
const deleteCsrf = document.getElementById('as-service-delete-csrf') as HTMLInputElement | null;
const deleteRedirect = document.getElementById('as-service-delete-redirect') as HTMLInputElement | null;
let deleteExpectedName = '';

deleteInput?.addEventListener('input', () => {
  if (!deleteSubmit) {
    return;
  }
  deleteSubmit.disabled = deleteInput.value !== deleteExpectedName;
});

document.querySelectorAll<HTMLElement>('[data-service-action]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    closeMenuOf(btn);

    const action = btn.getAttribute('data-service-action');
    const serviceName = btn.getAttribute('data-service-name') ?? '';

    if (action === 'export') {
      exportOpenApiUrl = btn.getAttribute('data-export-openapi-url') ?? '';
      if (exportSubtitle) {
        exportSubtitle.textContent = serviceName;
      }
      openModal(exportModal);
      return;
    }

    if (action === 'import') {
      importOpenApiUrl = btn.getAttribute('data-import-openapi-url') ?? '';
      importPostmanUrl = btn.getAttribute('data-import-postman-url') ?? '';
      if (importSubtitle) {
        importSubtitle.textContent = serviceName;
      }
      if (importFile) {
        importFile.value = '';
      }
      syncImportForm();
      openModal(importModal);
      return;
    }

    if (action === 'delete') {
      deleteExpectedName = serviceName;
      if (deleteForm) {
        deleteForm.action = btn.getAttribute('data-delete-url') ?? '';
      }
      if (deleteCsrf) {
        deleteCsrf.value = btn.getAttribute('data-delete-token') ?? '';
      }
      if (deleteRedirect) {
        deleteRedirect.value = btn.getAttribute('data-delete-redirect') ?? '';
      }
      const warningTemplate = deleteModal?.dataset.warningTemplate ?? '';
      const confirmTemplate = deleteModal?.dataset.confirmTemplate ?? '';
      if (deleteWarning) {
        deleteWarning.textContent = warningTemplate.replace('%name%', serviceName);
      }
      if (deleteLabel) {
        deleteLabel.textContent = confirmTemplate.replace('%name%', serviceName);
      }
      if (deleteInput) {
        deleteInput.value = '';
      }
      if (deleteSubmit) {
        deleteSubmit.disabled = true;
      }
      openModal(deleteModal);
      deleteInput?.focus();
    }
  });
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
