const STORAGE_KEY = 'nowo_api_studio_theme';

type Theme = 'light' | 'dark';

function getTheme(): Theme {
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function setTheme(theme: Theme): void {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem(STORAGE_KEY, theme);
  const btn = document.getElementById('as-theme-toggle');
  if (btn) {
    btn.textContent = theme === 'dark' ? '☀' : '☾';
  }
}

document.getElementById('as-theme-toggle')?.addEventListener('click', () => {
  setTheme(getTheme() === 'dark' ? 'light' : 'dark');
});

setTheme(getTheme());

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

function resetKebabPanel(panel: HTMLElement): void {
  panel.style.removeProperty('top');
  panel.style.removeProperty('left');
}

function positionKebabPanel(toggle: HTMLButtonElement, panel: HTMLElement): void {
  panel.hidden = false;
  panel.style.visibility = 'hidden';

  const rect = toggle.getBoundingClientRect();
  const panelWidth = panel.offsetWidth;
  const panelHeight = panel.offsetHeight;
  const margin = 8;
  const gap = 4;

  let left = rect.right - panelWidth;
  left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));

  let top = rect.bottom + gap;
  if (top + panelHeight > window.innerHeight - margin) {
    top = Math.max(margin, rect.top - panelHeight - gap);
  }

  panel.style.top = `${top}px`;
  panel.style.left = `${left}px`;
  panel.style.visibility = '';
}

function closeKebabMenus(except: HTMLElement | null = null): void {
  document.querySelectorAll<HTMLElement>('[data-kebab-menu]').forEach((menu) => {
    if (except && menu === except) {
      return;
    }

    menu.classList.remove('is-open');
    const panel = menu.querySelector<HTMLElement>('.as-kebab-panel');
    const toggle = menu.querySelector<HTMLButtonElement>('[data-kebab-toggle]');
    if (panel) {
      panel.hidden = true;
      resetKebabPanel(panel);
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
}

function repositionOpenKebabMenus(): void {
  document.querySelectorAll<HTMLElement>('[data-kebab-menu].is-open').forEach((menu) => {
    const panel = menu.querySelector<HTMLElement>('.as-kebab-panel');
    const toggle = menu.querySelector<HTMLButtonElement>('[data-kebab-toggle]');
    if (panel && toggle && !panel.hidden) {
      positionKebabPanel(toggle, panel);
    }
  });
}

document.querySelectorAll<HTMLButtonElement>('[data-kebab-toggle]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const menu = btn.closest('[data-kebab-menu]') as HTMLElement | null;
    const panel = menu?.querySelector<HTMLElement>('.as-kebab-panel');
    if (!menu || !panel) {
      return;
    }

    const willOpen = !menu.classList.contains('is-open');
    closeKebabMenus(willOpen ? menu : null);
    menu.classList.toggle('is-open', willOpen);
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

    if (willOpen) {
      positionKebabPanel(btn, panel);
    } else {
      panel.hidden = true;
      resetKebabPanel(panel);
    }
  });
});

document.addEventListener('click', (e) => {
  const target = e.target as HTMLElement;
  if (target.closest('[data-kebab-menu]')) {
    return;
  }
  closeKebabMenus();
});

window.addEventListener('scroll', repositionOpenKebabMenus, true);
window.addEventListener('resize', repositionOpenKebabMenus);

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

const localeMenu = document.getElementById('as-locale-menu');
const localeBtn = document.getElementById('as-locale-btn');
if (localeMenu && localeBtn) {
  localeBtn.addEventListener('click', () => {
    localeMenu.classList.toggle('is-open');
  });
  document.addEventListener('click', (e) => {
    if (!localeMenu.contains(e.target as Node)) {
      localeMenu.classList.remove('is-open');
    }
  });
}

function closeModal(modal: HTMLElement | null): void {
  if (!modal) {
    return;
  }
  modal.hidden = true;
}

function openModal(modal: HTMLElement | null): void {
  if (!modal) {
    return;
  }
  modal.hidden = false;
}

document.querySelectorAll<HTMLElement>('[data-modal-close]').forEach((el) => {
  el.addEventListener('click', () => {
    closeModal(el.closest('.as-modal') as HTMLElement | null);
  });
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') {
    return;
  }
  document.querySelectorAll<HTMLElement>('.as-modal:not([hidden])').forEach((modal) => {
    closeModal(modal);
  });
  closeKebabMenus();
});

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

document.querySelectorAll<HTMLButtonElement>('[data-service-action]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    closeKebabMenus();

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
