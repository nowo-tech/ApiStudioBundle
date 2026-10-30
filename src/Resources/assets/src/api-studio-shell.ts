/**
 * Api Studio workbench UI (REQ-UI-003): API tree, service collections, endpoint rows, the service dialogs (UiKit
 * native <dialog>) and the request console sub-tabs. The page chrome (theme, locale, layout) belongs to UiKit / the
 * host layout.
 *
 * @packageDocumentation
 */

document.querySelectorAll<HTMLElement>('[data-tree-toggle]').forEach((el) => {
  function toggle(): void {
    el.closest('[data-tree-group]')?.classList.toggle('is-open');
  }

  el.addEventListener('click', (e) => {
    if ((e.target as HTMLElement).tagName === 'A') {
      return;
    }
    e.preventDefault();
    toggle();
  });

  el.addEventListener('keydown', (e) => {
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
