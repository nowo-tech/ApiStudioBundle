import axe from 'axe-core';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * AS-A11Y-1: the workspace row of _sidebar_tree.html.twig. The markup below mirrors the template (the Twig/DOM test
 * in tests/Integration/Twig/SidebarTreeTemplateTest.php pins the template to the same shape).
 */
const CURRENT = `
<nav class="as-sidebar-tree" id="as-nav-tree" aria-label="API navigation">
  <div class="as-tree-group is-open" data-tree-group>
    <div class="as-tree-label is-active">
      <button type="button" class="as-tree-chevron" data-tree-toggle aria-expanded="true" aria-controls="as-tree-ws-1"
              aria-label="Show or hide the services of Alpha"><span aria-hidden="true">▶</span></button>
      <a href="/ws/1">Alpha</a>
    </div>
    <div class="as-tree-children" id="as-tree-ws-1"><div class="as-tree-svc"><a href="/svc/1">Svc</a></div></div>
  </div>
  <div class="as-tree-group" data-tree-group>
    <div class="as-tree-label">
      <button type="button" class="as-tree-chevron" data-tree-toggle aria-expanded="false" aria-controls="as-tree-ws-2"
              aria-label="Show or hide the services of Beta"><span aria-hidden="true">▶</span></button>
      <a href="/ws/2">Beta</a>
    </div>
    <div class="as-tree-children" id="as-tree-ws-2"></div>
  </div>
</nav>`;

/** The pre-1.12.4 row, still rendered by hosts that override the template. */
const LEGACY = `
<nav aria-label="API navigation">
  <div class="as-tree-group" data-tree-group>
    <div class="as-tree-label" data-tree-toggle role="button" tabindex="0">
      <span class="as-tree-chevron" aria-hidden="true">▶</span>
      <a href="/ws/9">Legacy</a>
    </div>
    <div class="as-tree-children"></div>
  </div>
</nav>`;

async function loadShell(html: string): Promise<void> {
  document.body.innerHTML = html;
  vi.resetModules();
  await import('../src/api-studio-shell');
}

function nestedInteractive(): Promise<axe.Result[]> {
  return axe
    .run(document.body, { runOnly: { type: 'rule', values: ['nested-interactive'] } })
    .then((r) => r.violations);
}

describe('sidebar tree toggle (AS-A11Y-1)', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('keeps no interactive element inside a [data-tree-toggle]', async () => {
    await loadShell(CURRENT);
    const toggles = document.querySelectorAll('[data-tree-toggle]');
    expect(toggles).toHaveLength(2);
    toggles.forEach((t) => {
      expect(t.tagName).toBe('BUTTON');
      expect(t.getAttribute('type')).toBe('button');
      expect(t.querySelector('a, button, input, select, textarea, [tabindex], [role="button"]')).toBeNull();
      expect(document.getElementById(t.getAttribute('aria-controls') ?? '')).not.toBeNull();
    });
  });

  it('passes axe nested-interactive on the current markup and fails on the legacy one', async () => {
    await loadShell(CURRENT);
    expect(await nestedInteractive()).toEqual([]);

    await loadShell(LEGACY);
    expect((await nestedInteractive()).map((v) => v.id)).toEqual(['nested-interactive']);
  });

  it('toggles the group and aria-expanded on click', async () => {
    await loadShell(CURRENT);
    const [first, second] = Array.from(document.querySelectorAll<HTMLButtonElement>('[data-tree-toggle]'));
    const firstGroup = first!.closest('[data-tree-group]')!;
    const secondGroup = second!.closest('[data-tree-group]')!;

    second!.click();
    expect(secondGroup.classList.contains('is-open')).toBe(true);
    expect(second!.getAttribute('aria-expanded')).toBe('true');

    second!.click();
    expect(secondGroup.classList.contains('is-open')).toBe(false);
    expect(second!.getAttribute('aria-expanded')).toBe('false');

    first!.click();
    expect(firstGroup.classList.contains('is-open')).toBe(false);
    expect(first!.getAttribute('aria-expanded')).toBe('false');
  });

  it('syncs aria-expanded with the group state on load', async () => {
    await loadShell(CURRENT.replace('aria-expanded="true"', 'aria-expanded="false"'));
    expect(document.querySelector('[data-tree-toggle]')!.getAttribute('aria-expanded')).toBe('true');
  });

  it('leaves Enter / Space to the native button (no double toggle)', async () => {
    await loadShell(CURRENT);
    const second = document.querySelectorAll<HTMLButtonElement>('[data-tree-toggle]')[1]!;
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    second.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    expect(second.closest('[data-tree-group]')!.classList.contains('is-open')).toBe(false);
  });

  it('keeps legacy overrides working: link navigates, Enter / Space toggle the row', async () => {
    await loadShell(LEGACY);
    const row = document.querySelector<HTMLElement>('[data-tree-toggle]')!;
    const group = row.closest('[data-tree-group]')!;
    expect(row.getAttribute('aria-expanded')).toBe('false');

    const link = row.querySelector('a')!;
    const navigate = new MouseEvent('click', { bubbles: true, cancelable: true });
    link.dispatchEvent(navigate);
    expect(navigate.defaultPrevented).toBe(false);
    expect(group.classList.contains('is-open')).toBe(false);

    row.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    expect(group.classList.contains('is-open')).toBe(true);
    expect(row.getAttribute('aria-expanded')).toBe('true');

    row.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }));
    expect(group.classList.contains('is-open')).toBe(false);

    link.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    expect(group.classList.contains('is-open')).toBe(false);

    row.click();
    expect(group.classList.contains('is-open')).toBe(true);
  });

  it('ignores a toggle outside a group', async () => {
    await loadShell('<button type="button" data-tree-toggle aria-expanded="false">x</button>');
    const btn = document.querySelector<HTMLButtonElement>('[data-tree-toggle]')!;
    btn.click();
    expect(btn.getAttribute('aria-expanded')).toBe('false');
  });
});
