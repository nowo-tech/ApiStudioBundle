import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');
const srcDir = resolve(root, 'src/Resources/assets/src');
const outDir = resolve(root, 'src/Resources/public');

const entries = [
  'api-body-tools',
  'api-endpoint-doc',
  'api-script-sandbox',
  'api-script-runtime',
  'api-studio-shell',
  'api-tester',
];

// 1.15: the server-side script runner sidecar (Deno / Node), next to the bundle code rather than in public/.
const runnerDir = resolve(root, 'src/Resources/runner');
const targets = [...entries.map((name) => [name, outDir]), ['api-script-runner', runnerDir]];

for (const [name, dir] of targets) {
  await build({
    configFile: false,
    build: {
      outDir: dir,
      emptyOutDir: false,
      minify: true,
      sourcemap: false,
      lib: {
        entry: resolve(srcDir, `${name}.ts`),
        formats: ['iife'],
        name: toGlobalName(name),
        fileName: () => `${name}.js`,
      },
    },
  });
  console.log(`built ${name}.js`);
}

function toGlobalName(entry) {
  return entry
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
}
