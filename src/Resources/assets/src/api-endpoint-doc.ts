import type { DocumentationByLocale } from './types/global';

function parseJson<T>(raw: string | undefined, fallback: T): T {
  if (!raw) {
    return fallback;
  }

  try {
    return JSON.parse(raw) as T;
  } catch {
    return fallback;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const panel = document.getElementById('endpoint-doc-panel');
  if (!panel) {
    return;
  }

  const saveUrl = panel.dataset.saveUrl ?? '';
  const csrf = panel.dataset.csrf ?? '';
  const fallbackTitle = panel.dataset.fallbackTitle ?? '';
  const emptyLabel = panel.dataset.emptyDescription ?? '';
  let activeLocale = panel.dataset.uiLocale ?? 'en';
  const docs = parseJson<DocumentationByLocale>(panel.dataset.documentation, {});

  const editBtn = document.getElementById('endpoint-doc-edit') as HTMLButtonElement | null;
  const saveBtn = document.getElementById('endpoint-doc-save') as HTMLButtonElement | null;
  const cancelBtn = document.getElementById('endpoint-doc-cancel') as HTMLButtonElement | null;
  const viewBlock = document.getElementById('endpoint-doc-view') as HTMLElement | null;
  const formBlock = document.getElementById('endpoint-doc-edit-form') as HTMLElement | null;
  const titleView = document.getElementById('endpoint-doc-title-view');
  const descView = document.getElementById('endpoint-doc-desc-view');
  const titleDocs = document.getElementById('endpoint-doc-title-docs');
  const descDocs = document.getElementById('endpoint-doc-desc-docs');
  const titleInput = document.getElementById('endpoint-doc-title-input') as HTMLInputElement | null;
  const descInput = document.getElementById('endpoint-doc-desc-input') as HTMLTextAreaElement | null;
  const localeTabs = document.querySelectorAll<HTMLButtonElement>(
    '#endpoint-doc-locale-tabs [data-doc-locale]',
  );

  if (!editBtn || !saveBtn || !cancelBtn || !viewBlock || !formBlock || !titleInput || !descInput) {
    return;
  }

  const editButton = editBtn;
  const saveButton = saveBtn;
  const cancelButton = cancelBtn;
  const viewPanel = viewBlock;
  const formPanel = formBlock;
  const titleField = titleInput;
  const descField = descInput;

  function ensureLocale(locale: string): void {
    if (!docs[locale]) {
      docs[locale] = { title: '', description: '' };
    }
  }

  function displayTitle(rawTitle: string): string {
    return rawTitle && rawTitle.trim() ? rawTitle.trim() : fallbackTitle;
  }

  function renderView(locale: string): void {
    ensureLocale(locale);
    const entry = docs[locale]!;
    const shownTitle = displayTitle(entry.title);
    const desc = entry.description || '';

    if (titleView) titleView.textContent = shownTitle;
    if (descView) {
      descView.textContent = desc || emptyLabel;
      descView.classList.toggle('is-empty', desc === '');
    }
    if (titleDocs) titleDocs.textContent = shownTitle;
    if (descDocs) {
      descDocs.textContent = desc || emptyLabel;
      descDocs.classList.toggle('is-empty', desc === '');
    }
  }

  function loadForm(locale: string): void {
    ensureLocale(locale);
    titleField.value = docs[locale]!.title || '';
    descField.value = docs[locale]!.description || '';
  }

  function setEditing(editing: boolean): void {
    viewPanel.hidden = editing;
    formPanel.hidden = !editing;
    editButton.hidden = editing;
    saveButton.hidden = !editing;
    cancelButton.hidden = !editing;
    localeTabs.forEach((tab) => {
      tab.disabled = editing;
    });
  }

  function setActiveLocale(locale: string): void {
    activeLocale = locale;
    localeTabs.forEach((tab) => {
      const isActive = tab.getAttribute('data-doc-locale') === locale;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    renderView(locale);
  }

  localeTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      if (tab.disabled) return;
      const locale = tab.getAttribute('data-doc-locale');
      if (!locale || locale === activeLocale) return;
      setEditing(false);
      setActiveLocale(locale);
    });
  });

  editButton.addEventListener('click', () => {
    loadForm(activeLocale);
    setEditing(true);
    titleField.focus();
  });

  cancelButton.addEventListener('click', () => {
    setEditing(false);
  });

  saveButton.addEventListener('click', async () => {
    if (!saveUrl) return;
    saveButton.disabled = true;

    try {
      const response = await fetch(saveUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({
          locale: activeLocale,
          title: titleField.value.trim(),
          description: descField.value.trim(),
        }),
      });
      const data = (await response.json()) as {
        locale: string;
        title?: string;
        description?: string;
        error?: string;
      };
      if (!response.ok) {
        throw new Error(data.error || 'Save failed.');
      }

      docs[data.locale] = {
        title: data.title || '',
        description: data.description || '',
      };
      renderView(data.locale);
      setEditing(false);
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    } finally {
      saveButton.disabled = false;
    }
  });

  setActiveLocale(activeLocale);
});
