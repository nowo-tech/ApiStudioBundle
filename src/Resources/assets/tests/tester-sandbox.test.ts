import { afterEach, describe, expect, it, vi } from 'vitest';
import { executeRun, type RunMessage } from '../src/lib/sandbox';

/**
 * Spec 003 (1.15), `scripts.mode: sandbox`: the console never evaluates script code in the page; each phase runs in a
 * sandboxed frame (simulated here: the frame's answer is computed with the frame runtime) and its result drives the
 * request.
 */
const CONSOLE = (servicePre: string, servicePost = ''): string => `
<div class="as-console" id="api-studio-tester"
     data-scripts-mode="sandbox"
     data-scripts-sandbox-url="/api-studio/scripts/sandbox"
     data-scripts-timeout-ms="1000"
     data-scripts-confirm="0"
     data-execute-url="/api-studio/execute/7"
     data-csrf-token="tok"
     data-environment-maps="{}"
     data-service-protocol="rest"
     data-service-pre-script="${servicePre}"
     data-service-post-script="${servicePost}">
  <div class="as-subpanel is-active" data-subpanel="body"><textarea id="tester-body">{"a":1}</textarea></div>
  <div class="as-subpanel" data-subpanel="pre"><textarea id="tester-pre-script"></textarea></div>
  <div class="as-subpanel" data-subpanel="post"><textarea id="tester-post-script"></textarea></div>
  <select id="tester-method"><option value="POST" selected>POST</option></select>
  <button type="button" id="tester-send">Send</button>
  <div id="tester-status"></div>
  <pre id="tester-response"></pre>
  <pre id="tester-script-log"></pre>
</div>`;

let observer: MutationObserver | null = null;

/** Answers every sandbox frame the console creates like the real frame would (ready, then the run's result). */
function fakeFrames(): void {
  observer = new MutationObserver((mutations) => {
    mutations.forEach((m) =>
      m.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLIFrameElement)) {
          return;
        }
        const source = node.contentWindow as Window;
        vi.spyOn(source, 'postMessage').mockImplementation((message: unknown) => {
          window.dispatchEvent(new MessageEvent('message', { data: executeRun(message as RunMessage), source, origin: 'null' }));
        });
        window.dispatchEvent(new MessageEvent('message', { data: { v: 1, kind: 'ready' }, source, origin: 'null' }));
      }),
    );
  });
  observer.observe(document.body, { childList: true });
}

async function boot(html: string): Promise<{ fetchMock: ReturnType<typeof vi.fn>; runScriptSafe: ReturnType<typeof vi.fn> }> {
  document.body.innerHTML = html;
  const runScriptSafe = vi.fn();
  (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime = {
    storageKey: () => 'k',
    loadRuntimeOverrides: () => ({}),
    saveRuntimeOverrides: () => undefined,
    createPm: () => {
      throw new Error('the page must not build an inline pm in sandbox mode');
    },
    runScriptSafe,
  };
  const fetchMock = vi.fn().mockResolvedValue({
    json: async () => ({ request_method: 'POST', response_status: 201, response_body: '{"id":5}', response_headers: {}, request_headers: {}, duration_ms: 1, success: true, error_message: null }),
  });
  vi.stubGlobal('fetch', fetchMock);
  fakeFrames();
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

  return { fetchMock, runScriptSafe };
}

const log = (): string => document.getElementById('tester-script-log')!.textContent ?? '';

describe('api-tester with scripts.mode: sandbox', () => {
  afterEach(() => {
    observer?.disconnect();
    vi.unstubAllGlobals();
    delete (window as unknown as Record<string, unknown>).ApiStudioScriptRuntime;
  });

  it('runs pre and post scripts in the sandbox, never in the page, and applies their result', async () => {
    const { fetchMock, runScriptSafe } = await boot(
      CONSOLE("pm.request.setHeader('X-Sandboxed', 'yes'); pm.console.log('pre ran')", "pm.test('created', () => pm.expect(pm.response.code).to.equal(201))"),
    );
    document.getElementById('tester-send')!.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    const payload = JSON.parse(String((fetchMock.mock.calls[0]! as [string, RequestInit])[1].body)) as { headers: Record<string, string> };
    expect(payload.headers['X-Sandboxed']).toBe('yes');
    await vi.waitFor(() => expect(log()).toContain('✓ created'));
    expect(log()).toContain('[service pre] pre ran');
    expect(runScriptSafe).not.toHaveBeenCalled();
    expect(document.querySelector('iframe')).toBeNull();
  });

  it('does not send when a pre-request script fails', async () => {
    const { fetchMock } = await boot(CONSOLE("throw new Error('nope')"));
    document.getElementById('tester-send')!.click();
    await vi.waitFor(() => expect(log()).toContain('[service pre] nope'));

    expect(fetchMock).not.toHaveBeenCalled();
    expect((document.getElementById('tester-send') as HTMLButtonElement).disabled).toBe(false);
  });
});
