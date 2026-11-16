import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * scripts.confirm_stored (1.13.2): stored service / endpoint scripts run only after the user approved their code in
 * this browser session; "send without scripts" sends the request and runs no script; code the user typed is not asked.
 */
const markup = (opts: { servicePre?: string; endpointPre?: string; confirm?: string; dialog?: boolean }): string => `
<div class="as-console" id="api-studio-tester"
     data-scripts-mode="inline"
     ${opts.confirm === undefined ? '' : `data-scripts-confirm="${opts.confirm}"`}
     data-execute-url="/api-studio/execute/7"
     data-csrf-token="tok"
     data-environment-maps="{}"
     data-service-protocol="rest"
     data-service-pre-script="${opts.servicePre ?? ''}"
     data-service-post-script="">
  <div class="as-subpanel is-active" data-subpanel="body"><textarea id="tester-body">{"a":1}</textarea></div>
  <div class="as-subpanel" data-subpanel="pre"><textarea id="tester-pre-script">${opts.endpointPre ?? ''}</textarea></div>
  <div class="as-subpanel" data-subpanel="post"><textarea id="tester-post-script"></textarea></div>
  <select id="tester-method"><option value="POST" selected>POST</option></select>
  <button type="button" id="tester-send">Send</button>
  <div id="tester-status"></div>
  <pre id="tester-response"></pre>
  <pre id="tester-script-log"></pre>
</div>
${
  opts.dialog === false
    ? ''
    : `<dialog id="tester-scripts-confirm-modal">
  <div id="tester-scripts-confirm-list"></div>
  <button type="button" id="tester-scripts-confirm-run">Run with scripts</button>
  <button type="button" id="tester-scripts-confirm-skip">Send without scripts</button>
  <button type="button" id="tester-scripts-confirm-cancel">Cancel</button>
</dialog>`
}`;

let runtime: unknown;

beforeAll(async () => {
  // jsdom may lack the modal API of <dialog>.
  const proto = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
  if (typeof proto.showModal !== 'function') {
    proto.showModal = function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
  }
  if (typeof proto.close !== 'function') {
    proto.close = function (this: HTMLDialogElement) {
      this.removeAttribute('open');
      this.dispatchEvent(new Event('close'));
    };
  }
  await import('../src/api-script-runtime');
  runtime = window.ApiStudioScriptRuntime;
});

async function boot(html: string): Promise<ReturnType<typeof vi.fn>> {
  document.body.innerHTML = html;
  (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime = runtime;
  const fetchMock = vi.fn().mockResolvedValue({
    json: async () => ({
      request_method: 'POST',
      response_status: 200,
      response_body: '{}',
      response_headers: {},
      request_headers: {},
      duration_ms: 1,
      success: true,
      error_message: null,
    }),
  });
  vi.stubGlobal('fetch', fetchMock);
  const ready: EventListener[] = [];
  const native = document.addEventListener.bind(document);
  const spy = vi.spyOn(document, 'addEventListener').mockImplementation((type: string, listener: unknown, options?: unknown) => {
    if (type === 'DOMContentLoaded') {
      ready.push(listener as EventListener);

      return;
    }
    native(type, listener as EventListener, options as AddEventListenerOptions);
  });
  vi.resetModules();
  await import('../src/api-tester');
  spy.mockRestore();
  ready.forEach((listener) => listener(new Event('DOMContentLoaded')));

  return fetchMock;
}

const dialog = (): HTMLDialogElement => document.getElementById('tester-scripts-confirm-modal') as HTMLDialogElement;
const log = (): string => document.getElementById('tester-script-log')!.textContent ?? '';

async function send(): Promise<void> {
  document.getElementById('tester-send')!.click();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('api-tester stored script confirmation (scripts.confirm_stored)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    window.sessionStorage.clear();
  });

  it('shows the stored code and sends without scripts on request', async () => {
    const fetchMock = await boot(markup({ servicePre: "pm.console.log('STORED-RAN')", confirm: '1' }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));

    expect(fetchMock).not.toHaveBeenCalled();
    const shown = document.querySelector<HTMLElement>('#tester-scripts-confirm-list pre')!;
    expect(shown.textContent).toBe("pm.console.log('STORED-RAN')");
    expect(shown.dataset.scriptLabel).toBe('service pre');

    document.getElementById('tester-scripts-confirm-skip')!.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(log()).not.toContain('STORED-RAN');
    expect(log()).toContain('Sent without scripts');

    // Not approved: asked again on the next send.
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
  });

  it('runs the approved code, remembers it and asks again once it changes', async () => {
    const fetchMock = await boot(markup({ endpointPre: "pm.console.log('STORED-RAN')" }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    document.getElementById('tester-scripts-confirm-run')!.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(log()).toContain('[pre-request] STORED-RAN');

    // Approved for this session: no dialog.
    await send();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(dialog().open).toBe(false);

    // Changed code: asked again.
    (document.getElementById('tester-pre-script') as HTMLTextAreaElement).value = "pm.console.log('CHANGED')";
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    expect(document.querySelector('#tester-scripts-confirm-list pre')!.textContent).toBe("pm.console.log('CHANGED')");
  });

  it('does not ask for code the user typed in an empty script field', async () => {
    const fetchMock = await boot(markup({}));
    (document.getElementById('tester-pre-script') as HTMLTextAreaElement).value = "pm.console.log('MINE')";
    await send();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    expect(dialog().open).toBe(false);
    expect(log()).toContain('[pre-request] MINE');
  });

  it('does not send when the dialog is dismissed', async () => {
    const fetchMock = await boot(markup({ servicePre: "pm.console.log('STORED-RAN')" }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    dialog().close();
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(fetchMock).not.toHaveBeenCalled();
    expect(log()).not.toContain('STORED-RAN');
  });

  it('sends without scripts when an override dropped the dialog', async () => {
    const fetchMock = await boot(markup({ servicePre: "pm.console.log('STORED-RAN')", dialog: false }));
    await send();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    expect(log()).not.toContain('STORED-RAN');
  });

  it('runs stored scripts without asking when confirm_stored is off (1.13.1 behaviour)', async () => {
    const fetchMock = await boot(markup({ servicePre: "pm.console.log('STORED-RAN')", confirm: '0' }));
    await send();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    expect(dialog().open).toBe(false);
    expect(log()).toContain('[service pre] STORED-RAN');
  });

  it('shows padded and long scripts whole, with their size (RC-M1)', async () => {
    const padded = "pm.console.log('ok')" + '&#10;'.repeat(120) + "pm.console.log('PAYLOAD')";
    const long = 'pm.console.log(1);' + ' '.repeat(3000) + "pm.console.log('FAR-RIGHT')";
    await boot(markup({ servicePre: padded, endpointPre: long }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));

    const shown = [...document.querySelectorAll<HTMLElement>('#tester-scripts-confirm-list pre')];
    expect(shown).toHaveLength(2);
    shown.forEach((pre) => {
      expect(pre.className).toBe('as-script-confirm-code');
      expect(pre.classList.contains('as-script-readonly')).toBe(false);
    });
    expect(shown[0]!.textContent).toContain('PAYLOAD');
    expect(shown[1]!.textContent).toContain('FAR-RIGHT');
    expect(document.querySelector('[data-script-stats="service pre"]')!.textContent).toContain('121 lines');
    expect(document.querySelector('[data-script-stats="pre-request"]')!.textContent).toMatch(/1 lines · 3045 characters/);
  });

  it('shows bidi and invisible characters as code points with a warning (RC-M1)', async () => {
    await boot(markup({ servicePre: "pm.console.log('a')/*&#x202E;*/;evil()&#x200B;" }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));

    const pre = document.querySelector('#tester-scripts-confirm-list pre')!;
    expect(pre.textContent).toContain('⟨U+202E⟩');
    expect(pre.textContent).toContain('⟨U+200B⟩');
    expect(pre.textContent).not.toMatch(/[\u202E\u200B]/);
    expect(document.querySelector('[data-script-warning="hidden"]')!.textContent).toContain('2');
  });

  it('warns about code built at run time (RC-I2)', async () => {
    await boot(markup({ servicePre: 'eval(pm.response.text())' }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));

    expect(document.querySelector('[data-script-warning="dynamic"]')).not.toBeNull();
  });

  it('sends once on a double click and re-enables Send after a cancel (RC-L1)', async () => {
    const fetchMock = await boot(markup({ servicePre: "pm.console.log('STORED-RAN')" }));
    const button = document.getElementById('tester-send') as HTMLButtonElement;
    button.click();
    button.click();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    expect(button.disabled).toBe(true);
    expect(document.querySelectorAll('#tester-scripts-confirm-list pre')).toHaveLength(1);

    dialog().close();
    await vi.waitFor(() => expect(button.disabled).toBe(false));
    expect(fetchMock).not.toHaveBeenCalled();

    button.click();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    document.getElementById('tester-scripts-confirm-skip')!.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    await vi.waitFor(() => expect(button.disabled).toBe(false));
  });

  it('runs the approved snapshot, not code changed while the dialog was open (RC-L2)', async () => {
    const fetchMock = await boot(markup({ endpointPre: "pm.console.log('APPROVED')" }));
    await send();
    await vi.waitFor(() => expect(dialog().open).toBe(true));
    (document.getElementById('tester-pre-script') as HTMLTextAreaElement).value = "pm.console.log('SWAPPED')";
    document.getElementById('tester-scripts-confirm-run')!.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    expect(log()).toContain('[pre-request] APPROVED');
    expect(log()).not.toContain('SWAPPED');
  });
});
