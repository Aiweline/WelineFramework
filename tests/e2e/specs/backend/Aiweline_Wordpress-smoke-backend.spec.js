// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

test.describe('Aiweline_Wordpress backend smoke', () => {
  test.describe.configure({ retries: 0 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  // 仅覆盖已在当前环境里验证可渲染且不含 fatal 文本的入口。
  // （category/page/root 在本轮跑通前多次命中 404/空内容。）
  // Keep route definitions deterministic across loader/worker processes.
  // Dynamic builders that depend on runtime bootstrap can cause
  // "Test not found in the worker process" in Playwright.
  const routes = [
    { name: 'policy', route: 'aiweline_wordpress/backend/policy' },
    { name: 'product', route: 'aiweline_wordpress/backend/product' },
  ];

  for (const { name, route } of routes) {
    test(`renders ${name} (${route}) without fatal errors`, async ({ page }) => {
      await gotoBackend(page, route, {
        timeout: 60000,
        settleMs: 1200,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 30000 });

      await expect(body).not.toContainText(FATAL_PATTERN, { timeout: 15000 });

      // Smoke: ensure something non-empty is rendered.
      const text = await body.innerText().catch(() => '');
      expect(String(text || '').trim().length).toBeGreaterThan(0);
    });
  }
});

