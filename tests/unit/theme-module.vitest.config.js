import { defineConfig } from 'vitest/config';

/**
 * Weline_Theme 模块 JS 测试运行配置。
 *
 * 背景：仓库既有 `tests/unit/vitest.config.js` 的 root 固定在 `tests/unit/`，
 * 其 include 不覆盖 `app/code/**`，导致模块内 10 个 `.test.js|.cjs` 与
 * `test/Browser/*.spec.js` 长期无运行器执行（见 dev/audit/theme-legacy-audit-20261002.md）。
 *
 * 用法（在 tests/unit/ 下执行）：
 *   ./node_modules/.bin/vitest run --config theme-module.vitest.config.js --root ../..
 */
export default defineConfig({
  test: {
    environment: 'happy-dom',
    watch: false,
    globals: true,
    include: ['app/code/Weline/Theme/test/**/*.{test,mjs,cjs,ts}'],
    exclude: ['**/node_modules/**', '**/vendor/**', '**/var/**', '**/generated/**', '**/test/e2e/**'],
    testTimeout: 10000,
    hookTimeout: 10000,
  },
});
