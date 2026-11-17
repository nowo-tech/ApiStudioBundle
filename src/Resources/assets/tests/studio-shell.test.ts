import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The workbench UI of the API tree and the service dialogs (api-studio-shell.ts): filter, collections, endpoint rows,
 * the export / import / delete dialogs, console sub-tabs and the folder tree (drag and drop in tree-dnd.test.ts).
 */
const PAGE = `
<input id="as-tree-filter">
<div data-tree-endpoint-group data-tree-search="users list"><button data-tree-ep-toggle aria-expanded="false"></button><div class="as-tree-use-cases" hidden></div></div>
<div data-tree-endpoint-group data-tree-search="orders"></div>
<a data-tree-item="alpha service">a</a><a data-tree-item="beta">b</a><a>no item attribute</a>

<div data-service-collection><button type="button" data-service-collection-toggle aria-expanded="false"></button><div class="as-service-collection-body" hidden></div></div>
<div data-service-collection><button type="button" data-service-collection-toggle></button></div>

<div data-endpoint-block><button type="button" data-endpoint-toggle aria-expanded="false"></button><div class="as-endpoint-cases" hidden></div></div>
<div data-endpoint-block><button type="button" data-endpoint-toggle></button></div>
<div data-tree-endpoint-group><button type="button" data-tree-ep-toggle></button></div>

<details open><summary>menu</summary>
  <button type="button" data-service-action="export" data-service-name="Billing" data-export-openapi-url="/export/1.json" id="act-export"></button>
  <button type="button" data-service-action="import" data-service-name="Billing" data-import-openapi-url="/import/openapi" data-import-postman-url="/import/postman" id="act-import"></button>
  <button type="button" data-service-action="delete" data-service-name="Billing" data-delete-url="/delete/1" data-delete-token="tok" data-delete-redirect="/home" id="act-delete"></button>
  <button type="button" data-service-action="unknown" id="act-unknown"></button>
</details>
<div popover id="pop"><button type="button" data-service-action="unknown" id="act-popover"></button></div>

<dialog id="as-service-export-modal"><span id="as-service-export-subtitle"></span>
  <label><input type="radio" name="as-export-format" value="openapi" checked></label>
  <button type="button" id="as-service-export-confirm"></button></dialog>
<dialog id="as-service-import-modal"><span id="as-service-import-subtitle"></span>
  <form id="as-service-import-form"><input type="file" id="as-service-import-file">
    <input type="radio" name="as-import-format" value="openapi" checked><input type="radio" name="as-import-format" value="postman">
    <div id="as-import-postman-vars" hidden></div></form></dialog>
<dialog id="as-service-delete-modal" data-warning-template="Delete %name%?" data-confirm-template="Type %name%">
  <form id="as-service-delete-form"><span id="as-service-delete-warning"></span><label id="as-service-delete-label"></label>
    <input id="as-service-delete-input"><input id="as-service-delete-csrf"><input id="as-service-delete-redirect">
    <button id="as-service-delete-submit" disabled></button></form></dialog>

<div class="as-console-request">
  <div class="as-subtabs" data-subtabs>
    <button type="button" class="as-subtab is-active" data-subtab="body" id="tab-body"></button>
    <button type="button" class="as-subtab" data-subtab="headers" id="tab-headers"></button>
  </div>
  <div class="as-subpanel is-active" data-subpanel="body"></div><div class="as-subpanel" data-subpanel="headers"></div>
</div>`;

async function load(html: string = PAGE): Promise<void> {
  document.body.innerHTML = html;
  vi.resetModules();
  await import('../src/api-studio-shell');
}

const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;

describe('api-studio-shell', () => {
  beforeEach(() => {
    HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    });
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('filters the endpoint groups and the tree items', async () => {
    await load();
    const filter = $<HTMLInputElement>('as-tree-filter');
    const groups = document.querySelectorAll<HTMLElement>('[data-tree-endpoint-group]');
    const items = document.querySelectorAll<HTMLElement>('[data-tree-item]');

    filter.value = ' USERS ';
    filter.dispatchEvent(new Event('input'));
    expect(groups[0]!.style.display).toBe('');
    expect(groups[1]!.style.display).toBe('none');
    expect(items[0]!.style.display).toBe('none');

    filter.value = 'beta';
    filter.dispatchEvent(new Event('input'));
    expect(items[1]!.style.display).toBe('');

    filter.value = '';
    filter.dispatchEvent(new Event('input'));
    expect(groups[1]!.style.display).toBe('');
  });

  it('opens and closes the service collections, endpoint blocks and tree endpoint groups', async () => {
    await load();
    const collection = document.querySelector<HTMLElement>('[data-service-collection]')!;
    const toggle = collection.querySelector<HTMLButtonElement>('[data-service-collection-toggle]')!;
    const panel = collection.querySelector<HTMLElement>('.as-service-collection-body')!;
    toggle.click();
    expect(collection.classList.contains('is-open')).toBe(true);
    expect(panel.hidden).toBe(false);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    toggle.click();
    expect(panel.hidden).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    document.querySelectorAll<HTMLButtonElement>('[data-service-collection-toggle]')[1]!.click();

    const block = document.querySelector<HTMLElement>('[data-endpoint-block]')!;
    block.querySelector<HTMLButtonElement>('[data-endpoint-toggle]')!.click();
    expect(block.classList.contains('is-open')).toBe(true);
    expect(block.querySelector<HTMLElement>('.as-endpoint-cases')!.hidden).toBe(false);
    document.querySelectorAll<HTMLButtonElement>('[data-endpoint-toggle]')[1]!.click();

    const group = document.querySelector<HTMLElement>('[data-tree-endpoint-group]')!;
    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    group.querySelector<HTMLButtonElement>('[data-tree-ep-toggle]')!.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(group.classList.contains('is-open')).toBe(true);
    expect(group.querySelector<HTMLElement>('.as-tree-use-cases')!.hidden).toBe(false);
    document.querySelectorAll<HTMLButtonElement>('[data-tree-ep-toggle]')[1]!.click();
  });

  it('opens the export dialog and goes to the OpenAPI download on confirm', async () => {
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign } as unknown as Location);
    await load();

    $('act-export').click();
    expect($('as-service-export-subtitle').textContent).toBe('Billing');
    expect($<HTMLDialogElement>('as-service-export-modal').open).toBe(true);
    expect(document.querySelector('details')!.hasAttribute('open')).toBe(false);

    $('as-service-export-confirm').click();
    expect(assign).toHaveBeenCalledWith('/export/1.json');
  });

  it('does not navigate when the format is not OpenAPI or there is no URL', async () => {
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign } as unknown as Location);
    await load(PAGE.replace('value="openapi" checked></label>', 'value="other" checked></label>'));
    $('act-export').click();
    $('as-service-export-confirm').click();
    expect(assign).not.toHaveBeenCalled();

    await load();
    $('as-service-export-confirm').click();
    expect(assign).not.toHaveBeenCalled();
  });

  it('opens the import dialog and switches the form action with the format', async () => {
    await load();
    const form = $<HTMLFormElement>('as-service-import-form');

    $('act-import').click();
    expect($('as-service-import-subtitle').textContent).toBe('Billing');
    expect(form.action).toContain('/import/openapi');
    expect($('as-import-postman-vars').hidden).toBe(true);

    const postman = document.querySelectorAll<HTMLInputElement>('input[name="as-import-format"]')[1]!;
    postman.checked = true;
    postman.dispatchEvent(new Event('change'));
    expect(form.action).toContain('/import/postman');
    expect($('as-import-postman-vars').hidden).toBe(false);
  });

  it('opens the delete dialog, fills it and enables the button only for the exact name', async () => {
    await load();

    $('act-delete').click();
    expect($<HTMLFormElement>('as-service-delete-form').action).toContain('/delete/1');
    expect($<HTMLInputElement>('as-service-delete-csrf').value).toBe('tok');
    expect($<HTMLInputElement>('as-service-delete-redirect').value).toBe('/home');
    expect($('as-service-delete-warning').textContent).toBe('Delete Billing?');
    expect($('as-service-delete-label').textContent).toBe('Type Billing');
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);

    const input = $<HTMLInputElement>('as-service-delete-input');
    input.value = 'Billin';
    input.dispatchEvent(new Event('input'));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);
    input.value = 'Billing';
    input.dispatchEvent(new Event('input'));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(false);
  });

  it('ignores an unknown action and closes a popover menu', async () => {
    const hide = vi.fn();
    await load();
    const pop = $('pop');
    (pop as unknown as { hidePopover: () => void }).hidePopover = hide;
    vi.spyOn(pop, 'matches').mockReturnValue(true);

    $('act-unknown').click();
    $('act-popover').click();

    expect(hide).toHaveBeenCalled();
    expect(HTMLDialogElement.prototype.showModal).not.toHaveBeenCalled();
  });

  it('works when the dialogs are missing from the page', async () => {
    await load(`<button type="button" data-service-action="export" id="e"></button><button type="button" data-service-action="import" id="i"></button><button type="button" data-service-action="delete" id="d"></button>`);

    $('e').click();
    $('i').click();
    $('d').click();

    expect(HTMLDialogElement.prototype.showModal).not.toHaveBeenCalled();
  });

  it('does not reopen an open dialog', async () => {
    await load();
    $<HTMLDialogElement>('as-service-export-modal').setAttribute('open', '');
    Object.defineProperty($('as-service-export-modal'), 'open', { value: true, configurable: true });

    $('act-export').click();

    expect(HTMLDialogElement.prototype.showModal).not.toHaveBeenCalled();
  });

  it('switches the console sub-tabs and their panels', async () => {
    await load();

    $('tab-headers').click();

    expect($('tab-headers').classList.contains('is-active')).toBe(true);
    expect($('tab-body').classList.contains('is-active')).toBe(false);
    expect(document.querySelector('[data-subpanel="headers"]')!.classList.contains('is-active')).toBe(true);
    expect(document.querySelector('[data-subpanel="body"]')!.classList.contains('is-active')).toBe(false);
  });

  it('keeps the sub-tabs inert without a root', async () => {
    await load('<div class="as-subtabs" data-subtabs><button class="as-subtab" data-subtab="x"></button></div>');
    document.querySelector<HTMLButtonElement>('.as-subtab')!.click();
    expect(document.querySelector('.as-subtab')!.classList.contains('is-active')).toBe(true);
  });

  it('expands a folder row', async () => {
    await load('<div data-tree-folder><button type="button" data-tree-folder-toggle aria-expanded="false"></button></div><button type="button" data-tree-folder-toggle id="orphan"></button>');
    const folder = document.querySelector<HTMLElement>('[data-tree-folder]')!;
    const toggle = folder.querySelector<HTMLButtonElement>('[data-tree-folder-toggle]')!;

    toggle.click();
    expect(folder.classList.contains('is-open')).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    toggle.click();
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    $('orphan').click();
  });
});
