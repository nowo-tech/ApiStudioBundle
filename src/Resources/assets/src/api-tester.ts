import type { BodyValidationResult, EnvironmentMaps, ScriptContext, StringMap } from './types/global';
import {
  escapeHtmlAttr,
  hasUnresolved,
  parseJson,
  resolveMap,
  resolveTemplate,
  truncateSnippet,
} from './lib/utils';

const RT = window.ApiStudioScriptRuntime;
const BT = window.ApiStudioBodyTools;

type KvTableConfig = {
  bodyId: string;
  addBtnId: string;
  rowAttr: string;
  resolvedClass: string;
};

type BodyToolsController = {
  validateBeforeSend: () => boolean;
  clearRequestValidation: () => void;
  formatResponse: (text: string | null) => string;
  clearResponseValidation: () => void;
};

type ExamplesController = {
  setLastResponse: (snapshot: ResponseSnapshot) => void;
  clearLastResponse: () => void;
  loadRequestExampleById: (id: string) => boolean;
};

type ResponseSnapshot = {
  status_code: number;
  response_body: string;
  response_headers: StringMap;
};

type ExecuteResponse = {
  request_url?: string;
  request_method?: string;
  request_headers?: StringMap;
  response_status?: number | null;
  response_headers?: StringMap;
  response_body?: string | null;
  duration_ms?: number;
  success?: boolean;
  error_message?: string | null;
};

function httpMethodClass(method: string | null | undefined): string {
  const map: StringMap = {
    GET: 'as-method-get',
    POST: 'as-method-post',
    PUT: 'as-method-put',
    PATCH: 'as-method-patch',
    DELETE: 'as-method-delete',
  };

  return map[String(method ?? '').toUpperCase()] ?? 'as-method-default';
}

function syncMethodSelectStyle(): void {
  const wrap = document.getElementById('tester-method-wrap');
  const select = document.getElementById('tester-method') as HTMLSelectElement | null;
  if (!wrap || !select) {
    return;
  }

  wrap.className = `as-method-select-wrap as-method ${httpMethodClass(select.value)}`;
}

function getSelectedHttpMethod(root: HTMLElement): string | null {
  if (root.dataset.serviceProtocol !== 'rest') {
    return null;
  }

  const select = document.getElementById('tester-method') as HTMLSelectElement | null;

  return select?.value ?? null;
}

function buildUrl(
  baseUrl: string,
  path: string,
  queryParams: StringMap,
  variables: StringMap,
): string {
  const base = resolveTemplate(String(baseUrl || '').replace(/\/$/, ''), variables);
  let p = resolveTemplate(String(path || ''), variables);
  if (p && !p.startsWith('/')) {
    p = `/${p}`;
  }

  let url = base + p;
  const qp = resolveMap(queryParams, variables);
  const keys = Object.keys(qp);
  if (keys.length) {
    url += `?${keys.map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(qp[k]!)}`).join('&')}`;
  }

  return url;
}

function getEnvId(): string {
  const select = document.getElementById('tester-environment') as HTMLSelectElement | null;

  return select?.value ?? '';
}

function getBaseVariables(root: HTMLElement): StringMap {
  const maps = parseJson<EnvironmentMaps>(root.dataset.environmentMaps, {});
  const envId = getEnvId();
  if (!envId || !maps[envId]) {
    return {};
  }

  return { ...(maps[envId].variables ?? {}) };
}

function getMergedVariables(root: HTMLElement): StringMap {
  const base = getBaseVariables(root);
  const envId = getEnvId();
  const overrides = RT ? RT.loadRuntimeOverrides(envId) : {};

  return { ...base, ...overrides };
}

function saveRuntimeOverrides(envId: string, overrides: StringMap): void {
  if (RT && envId) {
    RT.saveRuntimeOverrides(envId, overrides);
  }
}

function collectKvFromTable(bodySelector: string, rowAttr: string): StringMap {
  const items: StringMap = {};
  document.querySelectorAll<HTMLTableRowElement>(`${bodySelector} [${rowAttr}]`).forEach((row) => {
    const enabled = row.querySelector<HTMLInputElement>('.as-kv-enabled');
    if (enabled && !enabled.checked) {
      return;
    }

    const key = (row.querySelector<HTMLInputElement>('.as-kv-key')?.value ?? '').trim();
    const value = row.querySelector<HTMLInputElement>('.as-kv-value')?.value ?? '';
    if (key !== '') {
      items[key] = value;
    }
  });

  return items;
}

function collectHeadersFromTable(): StringMap {
  return collectKvFromTable('#tester-headers-body', 'data-header-row');
}

function collectParamsFromTable(): StringMap {
  return collectKvFromTable('#tester-params-body', 'data-param-row');
}

function refreshKvRows(
  bodySelector: string,
  rowAttr: string,
  resolvedSelector: string,
  variables: StringMap,
): void {
  document.querySelectorAll<HTMLTableRowElement>(`${bodySelector} [${rowAttr}]`).forEach((row) => {
    const value = row.querySelector<HTMLInputElement>('.as-kv-value')?.value ?? '';
    const resolved = row.querySelector(resolvedSelector);
    if (resolved) {
      resolved.textContent = resolveTemplate(value, variables);
    }
  });
}

function refreshHeaderRows(variables: StringMap): void {
  refreshKvRows('#tester-headers-body', 'data-header-row', '.as-resolved-header', variables);
}

function refreshParamRows(variables: StringMap): void {
  refreshKvRows('#tester-params-body', 'data-param-row', '.as-resolved-param', variables);
}

function createKvRow(rowAttr: string, resolvedClass: string, key: string, value: string): HTMLTableRowElement {
  const tr = document.createElement('tr');
  tr.className = 'as-kv-row';
  tr.setAttribute(rowAttr, '');
  tr.innerHTML =
    '<td><input type="checkbox" class="as-kv-enabled" checked></td>' +
    `<td><input type="text" class="as-kv-key" value="${escapeHtmlAttr(key)}"></td>` +
    `<td><input type="text" class="as-kv-value" value="${escapeHtmlAttr(value)}"></td>` +
    `<td><code class="${resolvedClass}"></code></td>` +
    '<td><button type="button" class="as-btn as-btn-ghost as-kv-remove" title="Remove">×</button></td>';

  return tr;
}

function fillKvTable(bodyId: string, rowAttr: string, resolvedClass: string, data: StringMap): void {
  const body = document.getElementById(bodyId);
  if (!body) {
    return;
  }

  body.innerHTML = '';
  const keys = Object.keys(data ?? {});
  if (!keys.length) {
    body.appendChild(createKvRow(rowAttr, resolvedClass, '', ''));

    return;
  }

  keys.forEach((key) => {
    body.appendChild(createKvRow(rowAttr, resolvedClass, key, data[key]!));
  });
}

function initKvTable(root: HTMLElement, config: KvTableConfig): void {
  const body = document.getElementById(config.bodyId);
  const addBtn = document.getElementById(config.addBtnId);
  if (!body) {
    return;
  }

  function onInput(): void {
    refreshKvRows(`#${config.bodyId}`, config.rowAttr, config.resolvedClass, getMergedVariables(root));
    refreshPreview(root);
  }

  if (!body.querySelector(`[${config.rowAttr}]`)) {
    body.appendChild(createKvRow(config.rowAttr, config.resolvedClass, '', ''));
  }

  body.addEventListener('input', (event) => {
    const target = event.target as HTMLElement;
    if (target.matches('.as-kv-key, .as-kv-value')) {
      onInput();
    }
  });
  body.addEventListener('change', (event) => {
    const target = event.target as HTMLElement;
    if (target.matches('.as-kv-enabled')) {
      onInput();
    }
  });
  body.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    if (target.matches('.as-kv-remove')) {
      target.closest(`[${config.rowAttr}]`)?.remove();
      if (!body.querySelector(`[${config.rowAttr}]`)) {
        body.appendChild(createKvRow(config.rowAttr, config.resolvedClass, '', ''));
      }
      onInput();
    }
  });

  addBtn?.addEventListener('click', () => {
    body.appendChild(createKvRow(config.rowAttr, config.resolvedClass, '', ''));
    body.querySelector<HTMLInputElement>(`[${config.rowAttr}]:last-child .as-kv-key`)?.focus();
    onInput();
  });
}

function initHeadersTable(root: HTMLElement): void {
  initKvTable(root, {
    bodyId: 'tester-headers-body',
    addBtnId: 'tester-headers-add',
    rowAttr: 'data-header-row',
    resolvedClass: 'as-resolved-header',
  });
}

function initParamsTable(root: HTMLElement): void {
  initKvTable(root, {
    bodyId: 'tester-params-body',
    addBtnId: 'tester-params-add',
    rowAttr: 'data-param-row',
    resolvedClass: 'as-resolved-param',
  });
}

function formatHeadersBlock(headers: StringMap | null | undefined): string {
  if (!headers || !Object.keys(headers).length) {
    return '—';
  }

  return Object.keys(headers)
    .map((k) => `${k}: ${headers[k]}`)
    .join('\n');
}

function refreshPreview(root: HTMLElement, variables?: StringMap): void {
  const vars = variables ?? getMergedVariables(root);
  const baseUrl = root.dataset.serviceBaseUrl ?? '';
  const path = root.dataset.endpointPath ?? '';
  const queryParams = collectParamsFromTable();

  const urlInput = document.getElementById('tester-url-display') as HTMLInputElement | null;
  const warn = document.getElementById('tester-url-unresolved');
  if (urlInput) {
    const url = buildUrl(baseUrl, path, queryParams, vars);
    urlInput.value = url;
    if (warn) {
      warn.hidden = !hasUnresolved(url);
    }
  }

  refreshParamRows(vars);
  refreshHeaderRows(vars);
}

function appendScriptLog(lines: string[] | null | undefined): void {
  const el = document.getElementById('tester-script-log');
  if (!el || !lines?.length) {
    return;
  }

  const block = lines.join('\n');
  el.textContent = el.textContent ? `${el.textContent}\n${block}` : block;
  el.scrollTop = el.scrollHeight;
}

function clearScriptLog(): void {
  const el = document.getElementById('tester-script-log');
  if (el) {
    el.textContent = '';
  }
}

function runScripts(scripts: Array<{ code: string; label: string }>, ctx: ScriptContext): void {
  scripts.forEach((item) => {
    if (!item.code || !String(item.code).trim()) {
      return;
    }

    const result = RT.runScriptSafe(item.code, ctx, item.label);
    if (result.logs.length) {
      appendScriptLog(result.logs.map((l) => `[${item.label}] ${l}`));
    }
    if (!result.ok) {
      throw new Error(`[${item.label}] ${result.error}`);
    }
  });
}

function formatBody(body: string | null, root: HTMLElement): string {
  if (body === null || body === '') {
    return '';
  }

  if (!BT) {
    try {
      return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
      return body;
    }
  }

  const format = BT.resolveBodyFormat(
    root.dataset.serviceProtocol ?? 'rest',
    root.dataset.endpointContentType ?? '',
  );

  return BT.formatDisplay(body, format);
}

function bodyValidationLabels(root: HTMLElement): Record<string, string> {
  return {
    empty: root.dataset.i18nBodyEmpty ?? 'Empty body',
    valid_json: root.dataset.i18nBodyValidJson ?? 'Valid JSON',
    valid_json_with_variables:
      root.dataset.i18nBodyValidJsonVars ?? 'Valid JSON (with {{variables}})',
    valid_xml: root.dataset.i18nBodyValidXml ?? 'Valid XML',
    valid_xml_with_variables:
      root.dataset.i18nBodyValidXmlVars ?? 'Valid XML (with {{variables}})',
  };
}

function showBodyValidation(
  el: HTMLElement | null,
  result: BodyValidationResult | null,
  root: HTMLElement,
): void {
  if (!el) {
    return;
  }

  if (!result) {
    el.hidden = true;
    el.textContent = '';
    el.className = 'as-body-validation';

    return;
  }

  const labels = bodyValidationLabels(root);
  el.hidden = false;
  el.className = `as-body-validation ${result.valid ? 'is-valid' : 'is-invalid'}`;
  el.textContent = result.valid
    ? BT
      ? BT.messageLabel(result, labels)
      : result.message
    : result.message;
}

function initBodyTools(
  root: HTMLElement,
  bodyField: HTMLTextAreaElement | null,
  responseEl: HTMLElement | null,
): BodyToolsController | null {
  if (!BT) {
    return null;
  }

  const format = BT.resolveBodyFormat(
    root.dataset.serviceProtocol ?? 'rest',
    root.dataset.endpointContentType ?? '',
  );
  const requestValidationEl = document.getElementById('tester-body-validation');
  const responseValidationEl = document.getElementById('tester-response-validation');

  function validateField(text: string): BodyValidationResult {
    return BT.validate(text || '', format);
  }

  document.getElementById('tester-body-format')?.addEventListener('click', () => {
    if (!bodyField) {
      return;
    }

    try {
      bodyField.value = BT.format(bodyField.value, format);
      showBodyValidation(requestValidationEl, validateField(bodyField.value), root);
    } catch (e) {
      showBodyValidation(
        requestValidationEl,
        { valid: false, with_variables: false, message: e instanceof Error ? e.message : String(e) },
        root,
      );
    }
  });

  document.getElementById('tester-body-validate')?.addEventListener('click', () => {
    if (!bodyField) {
      return;
    }

    showBodyValidation(requestValidationEl, validateField(bodyField.value), root);
  });

  document.getElementById('tester-response-format')?.addEventListener('click', () => {
    if (!responseEl) {
      return;
    }

    try {
      responseEl.textContent = BT.format(responseEl.textContent ?? '', format);
      showBodyValidation(responseValidationEl, validateField(responseEl.textContent ?? ''), root);
    } catch (e) {
      showBodyValidation(
        responseValidationEl,
        { valid: false, with_variables: false, message: e instanceof Error ? e.message : String(e) },
        root,
      );
    }
  });

  document.getElementById('tester-response-validate')?.addEventListener('click', () => {
    if (!responseEl) {
      return;
    }

    showBodyValidation(responseValidationEl, validateField(responseEl.textContent ?? ''), root);
  });

  bodyField?.addEventListener('input', () => {
    if (requestValidationEl && !requestValidationEl.hidden) {
      showBodyValidation(requestValidationEl, validateField(bodyField.value), root);
    }
  });

  return {
    validateBeforeSend() {
      if (!bodyField || !String(bodyField.value || '').trim()) {
        return true;
      }

      const result = validateField(bodyField.value);
      showBodyValidation(requestValidationEl, result, root);

      return result.valid;
    },
    clearRequestValidation() {
      showBodyValidation(requestValidationEl, null, root);
    },
    formatResponse(text) {
      return BT.formatDisplay(text ?? '', format);
    },
    clearResponseValidation() {
      showBodyValidation(responseValidationEl, null, root);
    },
  };
}

async function persistVariables(
  root: HTMLElement,
  dirtyKeys: StringMap,
  envId: string,
): Promise<void> {
  const syncTemplate = root.dataset.envSyncUrl ?? '';
  const syncToken = root.dataset.envSyncCsrf ?? '';
  if (!syncTemplate || !envId || !Object.keys(dirtyKeys).length) {
    return;
  }

  const url = syncTemplate.replace('__ENV__', envId);
  await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': syncToken,
    },
    body: JSON.stringify({ variables: dirtyKeys }),
  });
}

function applyVariableChanges(root: HTMLElement, envId: string, ctx: ScriptContext): void {
  const dirty = ctx.getDirtyKeys();
  if (!Object.keys(dirty).length) {
    return;
  }

  const overrides = RT.loadRuntimeOverrides(envId);
  Object.keys(dirty).forEach((key) => {
    if (dirty[key] === '') {
      delete overrides[key];
    } else {
      overrides[key] = dirty[key]!;
    }
  });
  saveRuntimeOverrides(envId, overrides);
  refreshPreview(root, ctx.getVariables());
}

function initExamples(root: HTMLElement, bodyField: HTMLTextAreaElement | null): ExamplesController {
  const modal = document.getElementById('tester-example-modal');
  const modalTitle = document.getElementById('tester-example-modal-title');
  const nameInput = document.getElementById('tester-example-name') as HTMLInputElement | null;
  const saveModalBtn = document.getElementById('tester-example-save');
  const saveRequestBtn = document.getElementById('tester-save-request');
  const saveResponseBtn = document.getElementById('tester-save-response') as HTMLButtonElement | null;
  const examplesCsrf = root.dataset.examplesCsrf ?? '';
  const saveRequestUrl = root.dataset.saveRequestUrl ?? '';
  const saveResponseUrl = root.dataset.saveResponseUrl ?? '';
  const deleteRequestTemplate = root.dataset.deleteRequestUrl ?? '';
  const deleteResponseTemplate = root.dataset.deleteResponseUrl ?? '';
  const i18nLoad = root.dataset.i18nLoad ?? 'Load';
  const i18nDelete = root.dataset.i18nDelete ?? 'Delete';
  const i18nEmptyRequest = root.dataset.i18nEmptyRequest ?? '—';
  const i18nEmptyResponse = root.dataset.i18nEmptyResponse ?? '—';
  const i18nConfirmDelete = root.dataset.i18nConfirmDelete ?? 'Delete this example?';
  const i18nSaveRequestTitle = root.dataset.i18nSaveRequestTitle ?? 'Save request example';
  let pendingSaveType: 'request' | 'response' | null = null;
  let lastResponseSnapshot: ResponseSnapshot | null = null;

  function closeModal(): void {
    if (modal) {
      modal.hidden = true;
    }
    pendingSaveType = null;
  }

  function openModal(type: 'request' | 'response', title: string, defaultName: string): void {
    if (!modal || !modalTitle || !nameInput) {
      return;
    }

    pendingSaveType = type;
    modalTitle.textContent = title;
    nameInput.value = defaultName;
    modal.hidden = false;
    nameInput.focus();
    nameInput.select();
  }

  modal?.querySelectorAll('[data-modal-close]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });

  saveModalBtn?.addEventListener('click', async () => {
    const name = (nameInput?.value ?? '').trim();
    if (!name || !pendingSaveType) {
      return;
    }

    try {
      if (pendingSaveType === 'request') {
        await saveRequestExample(name);
      } else if (pendingSaveType === 'response') {
        await saveResponseExample(name);
      }
      closeModal();
    } catch (e) {
      appendScriptLog([`ERROR: ${e instanceof Error ? e.message : String(e)}`]);
    }
  });

  nameInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      saveModalBtn?.click();
    }
    if (e.key === 'Escape') {
      closeModal();
    }
  });

  function renderRequestExampleItem(example: {
    id: number;
    name: string;
    body?: string | null;
    headers?: StringMap;
    query_params?: StringMap;
  }): HTMLDivElement {
    const payload = {
      body: example.body,
      headers: example.headers ?? {},
      query_params: example.query_params ?? {},
    };
    const div = document.createElement('div');
    div.className = 'as-example-item';
    div.dataset.exampleId = String(example.id);
    div.dataset.exampleType = 'request';
    div.dataset.examplePayload = JSON.stringify(payload);
    div.innerHTML =
      '<div class="as-example-item-head">' +
      '<strong></strong>' +
      '<span class="as-example-actions">' +
      `<button type="button" class="as-btn as-btn-ghost as-example-load">${i18nLoad}</button>` +
      `<button type="button" class="as-btn as-btn-ghost as-example-delete">${i18nDelete}</button>` +
      '</span></div>' +
      (example.body ? '<pre class="as-example-snippet"></pre>' : '');
    div.querySelector('strong')!.textContent = example.name;
    const pre = div.querySelector('.as-example-snippet');
    if (pre) {
      pre.textContent = truncateSnippet(example.body);
    }

    return div;
  }

  function renderResponseExampleItem(example: {
    id: number;
    name: string;
    status_code: number;
    response_body?: string | null;
    response_headers?: StringMap;
  }): HTMLDivElement {
    const payload = {
      status_code: example.status_code,
      response_body: example.response_body,
      response_headers: example.response_headers ?? {},
    };
    const div = document.createElement('div');
    div.className = 'as-example-item';
    div.dataset.exampleId = String(example.id);
    div.dataset.exampleType = 'response';
    div.dataset.examplePayload = JSON.stringify(payload);
    div.innerHTML =
      '<div class="as-example-item-head">' +
      '<strong></strong>' +
      '<span class="as-method as-method-default"></span>' +
      '<span class="as-example-actions">' +
      `<button type="button" class="as-btn as-btn-ghost as-example-delete">${i18nDelete}</button>` +
      '</span></div>' +
      (example.response_body ? '<pre class="as-example-snippet"></pre>' : '');
    div.querySelector('strong')!.textContent = example.name;
    div.querySelector('.as-method')!.textContent = String(example.status_code);
    const pre = div.querySelector('.as-example-snippet');
    if (pre) {
      pre.textContent = truncateSnippet(example.response_body);
    }

    return div;
  }

  function ensureExamplesList(listEl: HTMLElement | null): void {
    listEl?.querySelector('.as-examples-empty')?.remove();
  }

  async function saveRequestExample(name: string): Promise<void> {
    if (!saveRequestUrl) {
      throw new Error('Save URL not configured.');
    }

    const payload = {
      name,
      body: bodyField?.value ?? '',
      headers: collectHeadersFromTable(),
      query_params: collectParamsFromTable(),
    };
    const response = await fetch(saveRequestUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': examplesCsrf,
      },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error((data as { error?: string }).error || 'Failed to save request example.');
    }

    const list = document.getElementById('tester-request-examples');
    if (list) {
      ensureExamplesList(list);
      list.appendChild(renderRequestExampleItem(data));
    }
    appendScriptLog([`Request example saved: ${(data as { name: string }).name}`]);
  }

  async function saveResponseExample(name: string): Promise<void> {
    if (!saveResponseUrl || !lastResponseSnapshot) {
      throw new Error('No response to save.');
    }

    const payload = { name, ...lastResponseSnapshot };
    const response = await fetch(saveResponseUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': examplesCsrf,
      },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error((data as { error?: string }).error || 'Failed to save response example.');
    }

    const list = document.getElementById('tester-response-examples');
    if (list) {
      ensureExamplesList(list);
      list.appendChild(renderResponseExampleItem(data));
    }
    appendScriptLog([`Response example saved: ${(data as { name: string }).name}`]);
  }

  saveRequestBtn?.addEventListener('click', () => {
    openModal('request', i18nSaveRequestTitle, '');
  });

  saveResponseBtn?.addEventListener('click', () => {
    if (!lastResponseSnapshot) {
      return;
    }

    openModal(
      'response',
      saveResponseBtn.textContent?.trim() ?? 'Save response',
      `HTTP ${lastResponseSnapshot.status_code}`,
    );
  });

  function loadRequestExampleItem(item: HTMLElement | null): boolean {
    if (!item) {
      return false;
    }

    const payload = parseJson<{ body?: string; headers?: StringMap; query_params?: StringMap }>(
      item.dataset.examplePayload,
      {},
    );
    if (bodyField) {
      bodyField.value = payload.body ?? '';
    }
    fillKvTable('tester-params-body', 'data-param-row', 'as-resolved-param', payload.query_params ?? {});
    fillKvTable('tester-headers-body', 'data-header-row', 'as-resolved-header', payload.headers ?? {});
    refreshPreview(root);
    appendScriptLog([`Loaded request example: ${item.querySelector('strong')?.textContent ?? ''}`]);

    return true;
  }

  function loadRequestExampleById(id: string): boolean {
    if (!id) {
      return false;
    }

    const item = document.querySelector<HTMLElement>(
      `#tester-request-examples .as-example-item[data-example-type="request"][data-example-id="${id}"]`,
    );

    return loadRequestExampleItem(item);
  }

  document.getElementById('tester-request-examples')?.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    const item = target.closest<HTMLElement>('.as-example-item[data-example-type="request"]');
    if (!item) {
      return;
    }

    if (target.closest('.as-example-load')) {
      loadRequestExampleItem(item);

      return;
    }

    if (target.closest('.as-example-delete')) {
      void deleteExample('request', item);
    }
  });

  document.getElementById('tester-response-examples')?.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    const item = target.closest<HTMLElement>('.as-example-item[data-example-type="response"]');
    if (!item || !target.closest('.as-example-delete')) {
      return;
    }

    void deleteExample('response', item);
  });

  async function deleteExample(type: 'request' | 'response', item: HTMLElement): Promise<void> {
    if (!confirm(i18nConfirmDelete)) {
      return;
    }

    const id = item.dataset.exampleId;
    const template = type === 'request' ? deleteRequestTemplate : deleteResponseTemplate;
    if (!template || !id) {
      return;
    }

    const url = template.replace('__EXAMPLE__', id);
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-Token': examplesCsrf },
    });
    const data = await response.json();
    if (!response.ok) {
      appendScriptLog([`ERROR: ${(data as { error?: string }).error || 'Delete failed.'}`]);

      return;
    }

    const list = item.parentElement;
    item.remove();
    if (list && !list.querySelector('.as-example-item')) {
      const empty = document.createElement('p');
      empty.className = 'as-examples-empty';
      empty.textContent = type === 'request' ? i18nEmptyRequest : i18nEmptyResponse;
      list.appendChild(empty);
    }
  }

  return {
    setLastResponse(snapshot) {
      lastResponseSnapshot = snapshot;
      if (saveResponseBtn) {
        saveResponseBtn.disabled = !snapshot;
      }
    },
    clearLastResponse() {
      lastResponseSnapshot = null;
      if (saveResponseBtn) {
        saveResponseBtn.disabled = true;
      }
    },
    loadRequestExampleById,
  };
}

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('api-studio-tester');
  if (!root || !RT) {
    return;
  }

  const executeUrl = root.dataset.executeUrl ?? '';
  const csrfToken = root.dataset.csrfToken ?? '';
  const sendButton = document.getElementById('tester-send') as HTMLButtonElement | null;
  const environmentSelect = document.getElementById('tester-environment');
  const bodyField = document.getElementById('tester-body') as HTMLTextAreaElement | null;
  const preScriptField = document.getElementById('tester-pre-script') as HTMLTextAreaElement | null;
  const postScriptField = document.getElementById('tester-post-script') as HTMLTextAreaElement | null;
  const persistCheckbox = document.getElementById('tester-persist-vars') as HTMLInputElement | null;
  const statusEl = document.getElementById('tester-status');
  const responseEl = document.getElementById('tester-response');
  const responseHeadersEl = document.getElementById('tester-response-headers');
  const durationEl = document.getElementById('tester-duration');

  initHeadersTable(root);
  initParamsTable(root);
  const examples = initExamples(root, bodyField);
  const bodyTools = initBodyTools(root, bodyField, responseEl);
  syncMethodSelectStyle();
  document.getElementById('tester-method')?.addEventListener('change', syncMethodSelectStyle);
  refreshPreview(root);
  environmentSelect?.addEventListener('change', () => {
    refreshPreview(root);
    clearScriptLog();
  });

  const loadExampleId = root.dataset.loadRequestExample;
  if (loadExampleId) {
    examples.loadRequestExampleById(loadExampleId);
  }

  if (!sendButton || !bodyField || !statusEl || !responseEl || !executeUrl) {
    return;
  }

  const sendLabel = sendButton.textContent?.trim() ?? 'Send';
  const servicePre = root.dataset.servicePreScript ?? '';
  const servicePost = root.dataset.servicePostScript ?? '';

  sendButton.addEventListener('click', async () => {
    sendButton.disabled = true;
    sendButton.textContent = '…';
    statusEl.textContent = 'Sending…';
    statusEl.className = '';
    responseEl.textContent = '';
    if (responseHeadersEl) {
      responseHeadersEl.textContent = '';
    }
    clearScriptLog();
    if (durationEl) {
      durationEl.textContent = '';
    }
    examples.clearLastResponse();
    bodyTools?.clearResponseValidation();

    if (bodyTools && !bodyTools.validateBeforeSend()) {
      sendButton.disabled = false;
      sendButton.textContent = sendLabel;
      statusEl.textContent = 'Invalid body';
      statusEl.className = 'as-status-err';
      appendScriptLog(['Request body validation failed — fix or validate in the Body tab.']);

      return;
    }

    const envId = getEnvId();
    let variables = getMergedVariables(root);
    const headerTemplates = collectHeadersFromTable();
    const paramTemplates = collectParamsFromTable();
    const scriptCtx = RT.createPm({
      baseVariables: variables,
      requestBody: bodyField.value,
      requestHeaders: headerTemplates,
      requestQueryParams: paramTemplates,
    });

    try {
      runScripts(
        [
          { code: servicePre, label: 'service pre' },
          { code: preScriptField?.value ?? '', label: 'pre-request' },
        ],
        scriptCtx,
      );

      bodyField.value = scriptCtx.pm.request.body;
      variables = scriptCtx.getVariables();
      const requestHeaders = resolveMap(scriptCtx.pm.request.headers, variables);
      const requestParams = resolveMap(scriptCtx.pm.request.queryParams, variables);

      applyVariableChanges(root, envId, scriptCtx);
      refreshPreview(root, variables);

      const payload: Record<string, unknown> = {
        body: bodyField.value,
        variables,
        headers: requestHeaders,
        query_params: requestParams,
      };
      const httpMethod = getSelectedHttpMethod(root);
      if (httpMethod) {
        payload.method = httpMethod;
      }
      if (envId) {
        payload.environment_id = parseInt(envId, 10);
      }

      const response = await fetch(executeUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(payload),
      });
      const data = (await response.json()) as ExecuteResponse;
      const status = data.response_status != null ? data.response_status : 'ERR';
      const ok = Boolean(data.success);

      scriptCtx.setResponse(status, data.response_body || data.error_message || '');

      runScripts(
        [
          { code: postScriptField?.value ?? '', label: 'post-request' },
          { code: servicePost, label: 'service post' },
        ],
        scriptCtx,
      );

      scriptCtx.runTests().forEach((t) => {
        appendScriptLog([`${t.ok ? '✓ ' : '✗ '}${t.name}${t.error ? `: ${t.error}` : ''}`]);
      });

      applyVariableChanges(root, envId, scriptCtx);

      const allDirty = scriptCtx.getDirtyKeys();
      if (persistCheckbox?.checked && envId && Object.keys(allDirty).length) {
        try {
          await persistVariables(root, allDirty, envId);
          appendScriptLog(['Variables persisted to environment.']);
        } catch (e) {
          appendScriptLog([`Failed to persist variables: ${e instanceof Error ? e.message : String(e)}`]);
        }
      }

      statusEl.textContent = `${data.request_method ?? ''} → ${status}${data.error_message ? ` · ${data.error_message}` : ''}`;
      statusEl.className = ok ? 'as-status-ok' : 'as-status-err';
      responseEl.textContent =
        data.error_message ||
        (bodyTools ? bodyTools.formatResponse(data.response_body ?? null) : formatBody(data.response_body ?? null, root));
      if (responseHeadersEl) {
        responseHeadersEl.textContent =
          `=== Request ===\n${formatHeadersBlock(data.request_headers)}` +
          `\n\n=== Response ===\n${formatHeadersBlock(data.response_headers)}`;
      }
      if (durationEl) {
        durationEl.textContent = `${data.duration_ms ?? 0} ms · ${data.response_body ? data.response_body.length : 0} B`;
      }

      const urlDisplay = document.getElementById('tester-url-display') as HTMLInputElement | null;
      if (data.request_url && urlDisplay) {
        urlDisplay.value = data.request_url;
      }

      examples.setLastResponse({
        status_code: data.response_status ?? 0,
        response_body: data.response_body || data.error_message || '',
        response_headers: data.response_headers ?? {},
      });
    } catch (error) {
      statusEl.textContent = 'Request failed';
      statusEl.className = 'as-status-err';
      responseEl.textContent = error instanceof Error ? error.message : String(error);
      appendScriptLog([`ERROR: ${error instanceof Error ? error.message : String(error)}`]);
    } finally {
      sendButton.disabled = false;
      sendButton.textContent = sendLabel;
    }
  });
});
