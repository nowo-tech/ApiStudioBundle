import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The workbench UI of the API tree and the service dialogs (api-studio-shell.ts): filter, collections, endpoint rows,
 * the service delete dialog's type-to-confirm button, console sub-tabs and the folder tree (drag and drop in tree-dnd.test.ts).
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

<form id="as-service-delete-form"><input id="as-service-delete-input" data-as-delete-expected="Billing"></form>
<button type="submit" form="as-service-delete-form" id="as-service-delete-submit" data-as-delete-submit disabled></button>
<input id="other-input">

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

  it('enables the delete button of the dialog only for the exact service name', async () => {
    await load();
    const input = $<HTMLInputElement>('as-service-delete-input');

    input.value = 'Billin';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);

    input.value = 'Billing';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(false);

    input.value = 'billing';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);
  });

  it('ignores the other inputs and a field without a form', async () => {
    await load();
    const other = $<HTMLInputElement>('other-input');
    other.value = 'Billing';
    other.dispatchEvent(new Event('input', { bubbles: true }));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);

    document.body.insertAdjacentHTML('beforeend', '<input id="loose" data-as-delete-expected="x">');
    $<HTMLInputElement>('loose').dispatchEvent(new Event('input', { bubbles: true }));
    expect($<HTMLButtonElement>('as-service-delete-submit').disabled).toBe(true);
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
