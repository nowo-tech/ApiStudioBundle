/**
 * The sandbox frame as a relay (spec 003, 1.15; audit RW-M1): scripts no longer run in the frame document (which a
 * script could navigate to send data out) but in a dedicated Worker the frame creates. The frame passes the console's
 * run to the worker once the worker is ready, relays the worker's `pm.sendRequest` asks and their answers bound to the
 * run nonce, forwards the worker's result and stops the worker. Everything else is dropped; the console validates
 * what arrives (lib/sandbox.ts).
 *
 * @packageDocumentation
 */
import { isRunMessage } from './sandbox';

export type RelayWorker = Pick<Worker, 'postMessage' | 'terminate' | 'onmessage' | 'onerror'>;

type Message = { v?: unknown; kind?: unknown; nonce?: unknown };

/**
 * Wires $worker to the frame's parent through $postToParent; returns the handler for the parent's messages. The frame
 * says `ready` to the console only after the worker said it (a worker the CSP refused never gets a run: the console's
 * time limit fails the phase).
 */
export function createFrameRelay(worker: RelayWorker, postToParent: (message: unknown) => void): (data: unknown) => void {
  let workerReady = false;
  let nonce: string | null = null;
  let done = false;

  const stop = (): void => {
    done = true;
    worker.terminate();
  };

  worker.onmessage = (event: MessageEvent) => {
    const m = event.data as Message | null;
    if (done || typeof m !== 'object' || m === null || m.v !== 1) {
      return;
    }
    if (m.kind === 'ready' && !workerReady) {
      workerReady = true;
      postToParent({ v: 1, kind: 'ready' });

      return;
    }
    if (nonce === null || m.nonce !== nonce) {
      return;
    }
    if (m.kind === 'sendRequest') {
      postToParent(m);
    } else if (m.kind === 'result') {
      postToParent(m);
      stop();
    }
  };
  worker.onerror = () => {
    if (!done && nonce !== null) {
      postToParent({ v: 1, kind: 'error', nonce });
    }
    stop();
  };

  return (data: unknown): void => {
    if (done || !workerReady) {
      return;
    }
    if (nonce === null) {
      if (isRunMessage(data)) {
        nonce = data.nonce;
        worker.postMessage(data);
      }

      return;
    }
    const m = data as Message | null;
    if (typeof m === 'object' && m !== null && m.v === 1 && m.kind === 'sendResult' && m.nonce === nonce) {
      worker.postMessage(m);
    }
  };
}
