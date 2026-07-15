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
  'api-form-locale-tabs',
  'api-script-runtime',
  'api-studio-shell',
  'api-tester',
];

for (const name of entries) {
  await build({
    configFile: false,
    build: {
      outDir,
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
