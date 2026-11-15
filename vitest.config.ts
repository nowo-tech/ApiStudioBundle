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
    },
  },
});
