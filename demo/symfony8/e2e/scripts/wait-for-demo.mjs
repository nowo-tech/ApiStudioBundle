// REQ-DEMO-013: wait until the demo answers HTTP before Playwright navigates (ensure-up does not wait for FrankenPHP).
// Usage: node scripts/wait-for-demo.mjs [url] [timeoutSeconds]
const url = process.argv[2] ?? process.env.DEMO_BASE_URL ?? 'http://127.0.0.1:8000';
const timeout = Number(process.argv[3] ?? 120) * 1000;
const started = Date.now();
for (;;) {
  try {
    const res = await fetch(url, { redirect: 'manual' });
    if (res.status < 500) {
      console.log(`demo ready: ${url} (${res.status})`);
      break;
    }
  } catch {
    // not listening yet
  }
  if (Date.now() - started > timeout) {
    console.error(`demo not reachable at ${url} after ${timeout / 1000}s`);
    process.exit(1);
  }
  await new Promise((r) => setTimeout(r, 2000));
}
