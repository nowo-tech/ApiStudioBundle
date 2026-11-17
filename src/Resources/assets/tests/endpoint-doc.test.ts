import { afterEach, describe, expect, it, vi } from 'vitest';

const PANEL = (extra = ''): string => `
<div id="endpoint-doc-panel" data-save-url="/save" data-csrf="tok" data-fallback-title="Untitled" data-empty-description="No description"
     data-ui-locale="en" data-documentation='{"en":{"title":"Hello","description":"World"},"es":{"title":"","description":""}}' ${extra}>
  <div id="endpoint-doc-view">
    <div data-nowo-ui-locale-tabs>
      <div data-nowo-ui-tabs-panel data-locale="en" data-active><span data-doc-title></span><span data-doc-description></span></div>
      <div data-nowo-ui-tabs-panel data-locale="es"><span data-doc-title></span><span data-doc-description></span></div>
    </div>
  </div>
  <div id="endpoint-doc-edit-form" hidden>
    <input id="endpoint-doc-title-input"><textarea id="endpoint-doc-desc-input"></textarea>
  </div>
  <button type="button" id="endpoint-doc-edit">Edit</button>
  <button type="button" id="endpoint-doc-save" hidden>Save</button>
  <button type="button" id="endpoint-doc-cancel" hidden>Cancel</button>
  <p id="endpoint-doc-error" hidden></p>
  <h2 id="endpoint-doc-title-docs"></h2><p id="endpoint-doc-desc-docs"></p>
</div>`;

const registered: Array<[string, EventListenerOrEventListenerObject]> = [];
const addEventListener = document.addEventListener.bind(document);

async function boot(html: string): Promise<void> {
  document.body.innerHTML = html;
  vi.resetModules();
  vi.spyOn(document, 'addEventListener').mockImplementation((type: string, listener: EventListenerOrEventListenerObject, options?: boolean | AddEventListenerOptions) => {
    registered.push([type, listener]);
    addEventListener(type, listener, options);
  });
  await import('../src/api-endpoint-doc');
  document.dispatchEvent(new Event('DOMContentLoaded'));
}

const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;
const flush = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('api-endpoint-doc', () => {
  afterEach(() => {
    for (const [type, listener] of registered.splice(0)) {
      document.removeEventListener(type, listener);
    }
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  it('renders the documentation of the active locale and the fallbacks', async () => {
    await boot(PANEL());

    expect(document.querySelector('[data-locale="en"] [data-doc-title]')?.textContent).toBe('Hello');
    expect($('endpoint-doc-title-docs').textContent).toBe('Hello');
    expect($('endpoint-doc-desc-docs').classList.contains('is-empty')).toBe(false);
  });

  it('does nothing without the panel or with a partial one', async () => {
    await boot('<p>no panel</p>');
    expect(document.body.textContent).toContain('no panel');

    await boot('<div id="endpoint-doc-panel"></div>');
    expect($('endpoint-doc-panel').textContent).toBe('');
  });

  it('edits the active locale and cancels', async () => {
    await boot(PANEL());

    $('endpoint-doc-edit').click();
    expect($<HTMLInputElement>('endpoint-doc-title-input').value).toBe('Hello');
    expect($<HTMLTextAreaElement>('endpoint-doc-desc-input').value).toBe('World');
    expect($('endpoint-doc-edit-form').hidden).toBe(false);
    expect($('endpoint-doc-view').hidden).toBe(true);
    expect($('endpoint-doc-save').hidden).toBe(false);

    $('endpoint-doc-cancel').click();
    expect($('endpoint-doc-edit-form').hidden).toBe(true);
    expect($('endpoint-doc-edit').hidden).toBe(false);
  });

  it('follows a locale tab change, rendering the empty locale with the fallbacks', async () => {
    await boot(PANEL());
    $('endpoint-doc-edit').click();

    const tabs = document.querySelector('[data-nowo-ui-locale-tabs]') as HTMLElement;
    const es = tabs.querySelector('[data-locale="es"]') as HTMLElement;
    tabs.dispatchEvent(new CustomEvent('nowo-ui:tabs:change', { detail: { panel: es } }));

    expect($('endpoint-doc-edit-form').hidden).toBe(true);
    expect(es.querySelector('[data-doc-title]')?.textContent).toBe('Untitled');
    expect(es.querySelector('[data-doc-description]')?.textContent).toBe('No description');
    expect(es.querySelector('[data-doc-description]')?.classList.contains('is-empty')).toBe(true);

    tabs.dispatchEvent(new CustomEvent('nowo-ui:tabs:change', { detail: { panel: es } }));
    tabs.dispatchEvent(new CustomEvent('nowo-ui:tabs:change', { detail: {} }));
    tabs.dispatchEvent(new CustomEvent('nowo-ui:tabs:change'));
  });

  it('saves the edited documentation', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ locale: 'en', title: 'New', description: 'Text' }) });
    vi.stubGlobal('fetch', fetchMock);
    await boot(PANEL());
    $('endpoint-doc-edit').click();
    $<HTMLInputElement>('endpoint-doc-title-input').value = '  New ';
    $<HTMLTextAreaElement>('endpoint-doc-desc-input').value = ' Text ';

    $('endpoint-doc-save').click();
    await flush();

    expect(fetchMock).toHaveBeenCalledWith('/save', expect.objectContaining({ method: 'POST' }));
    expect(JSON.parse(fetchMock.mock.calls[0]![1].body as string)).toEqual({ locale: 'en', title: 'New', description: 'Text' });
    expect($('endpoint-doc-title-docs').textContent).toBe('New');
    expect($('endpoint-doc-edit-form').hidden).toBe(true);
    expect($<HTMLButtonElement>('endpoint-doc-save').disabled).toBe(false);
  });

  it('shows the error of a failed save, with a fallback message, and for a thrown value', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ ok: false, json: () => Promise.resolve({ error: 'Denied' }) })
      .mockResolvedValueOnce({ ok: false, json: () => Promise.resolve({}) })
      .mockRejectedValueOnce('offline');
    vi.stubGlobal('fetch', fetchMock);
    await boot(PANEL());
    $('endpoint-doc-edit').click();

    $('endpoint-doc-save').click();
    await flush();
    expect($('endpoint-doc-error').textContent).toBe('Denied');
    expect($('endpoint-doc-error').hidden).toBe(false);

    $('endpoint-doc-save').click();
    await flush();
    expect($('endpoint-doc-error').textContent).toBe('Save failed.');

    $('endpoint-doc-save').click();
    await flush();
    expect($('endpoint-doc-error').textContent).toBe('offline');
  });

  it('does not save without a save URL and works without the error element or the docs view', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    await boot(PANEL().replace('data-save-url="/save"', '').replace('<p id="endpoint-doc-error" hidden></p>', '').replace('<h2 id="endpoint-doc-title-docs"></h2><p id="endpoint-doc-desc-docs"></p>', ''));
    $('endpoint-doc-edit').click();

    $('endpoint-doc-save').click();
    await flush();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('fails quietly without an error element when the save fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('down')));
    await boot(PANEL().replace('<p id="endpoint-doc-error" hidden></p>', ''));
    $('endpoint-doc-edit').click();

    $('endpoint-doc-save').click();
    await flush();

    expect($<HTMLButtonElement>('endpoint-doc-save').disabled).toBe(false);
  });

  it('starts from an invalid documentation island and no locale', async () => {
    await boot(PANEL().replace(/data-documentation='[^']*'/, "data-documentation='{broken'").replace('data-ui-locale="en"', '').replace('data-active', ''));

    expect($('endpoint-doc-title-docs').textContent).toBe('Untitled');
  });
});
