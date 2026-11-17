import { defineConfig } from 'vitest/config';

/**
 * Vitest for the console scripts (jsdom). The entries are IIFE scripts with top-level side effects: each test builds
 * its DOM first, then imports the entry with a fresh module registry.
 */
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/Resources/assets/tests/**/*.test.ts'],
    restoreMocks: true,
    clearMocks: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'text-summary'],
      reportsDirectory: './coverage-ts',
      include: ['src/Resources/assets/src/**/*.ts'],
      exclude: ['**/types/**'],
      // REQ-TEST-003 (TS/JS >= 90 % lines): the gate sits a little under the measured figures (lines 96.1, statements 96.0,
      // functions 96.3, branches 86.4) so a drop fails CI. The security-sensitive frame relay and lockdown keep their
      // lines, statements and functions at 100 %.
      thresholds: {
        lines: 95,
        statements: 95,
        functions: 95,
        branches: 85,
        'src/Resources/assets/src/lib/{frame-relay,lockdown,node-refusal,require}.ts': { lines: 100, statements: 100, functions: 100 },
      },
    },
  },
});
