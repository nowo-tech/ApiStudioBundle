import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Reference Vite config for Api Studio Bundle frontend assets (REQ-ASSETS-001).
 *
 * Multi-entry IIFE builds are orchestrated by `scripts/build-assets.mjs`
 * (`pnpm run build`), which calls Vite's programmatic `build()` with the same
 * `outDir` and `src/Resources/assets/src` inputs documented here.
 */
export default defineConfig({
  build: {
    outDir: 'src/Resources/public',
    emptyOutDir: false,
    minify: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        'api-body-tools': resolve('src/Resources/assets/src/api-body-tools.ts'),
        'api-endpoint-doc': resolve('src/Resources/assets/src/api-endpoint-doc.ts'),
        'api-form-locale-tabs': resolve('src/Resources/assets/src/api-form-locale-tabs.ts'),
        'api-script-runtime': resolve('src/Resources/assets/src/api-script-runtime.ts'),
        'api-studio-shell': resolve('src/Resources/assets/src/api-studio-shell.ts'),
        'api-tester': resolve('src/Resources/assets/src/api-tester.ts'),
      },
    },
  },
});
