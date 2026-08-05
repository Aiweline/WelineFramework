/**
 * 万能商城内核计划 DOC_BROWSER 用例固化
 *
 * - TEST-DOC-01：计划与任务落盘可见
 * - TEST-DOC-02：计划最终文本含 ID / CLOSED / §8.7
 * - TEST-DOC-03：受影响 SystemConfig AI-INDEX/README 摘要可见
 *
 * 默认目标：PLAYWRIGHT_DOC_ORIGIN 或 http://127.0.0.1:9596/
 * 启动页：evidence/e2e/doc-browser/index.html（python3 -m http.server）
 *
 * @weline-e2e-spec { module: Weline_Framework, type: plan, layer: docs }
 */

const { test, expect } = require('@playwright/test');

const DOC_ORIGIN = (process.env.PLAYWRIGHT_DOC_ORIGIN || 'http://127.0.0.1:9596').replace(/\/$/, '');

test.describe('计划 DOC_BROWSER 用例', () => {
  test.setTimeout(60000);

  test('[case:TEST-DOC-01] 计划与任务落盘页可见关键标记', async ({ page }) => {
    const res = await page.goto(`${DOC_ORIGIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    expect(res && res.ok(), `DOC HTTP ${res && res.status()}`).toBeTruthy();
    await expect(page.locator('h1')).toContainText(/Universal Mall E2E Plan/i);
    await expect(page.locator('[data-testid="test-doc-01"]')).toContainText(/TEST-DOC-01/);
    await expect(page.locator('[data-testid="test-doc-01"]')).toContainText(/SOLIDIFIED_E2E/);
    await expect(page.locator('[data-testid="test-doc-01"]')).toContainText(/存在/);
  });

  test('[case:TEST-DOC-02] 计划最终文本含 CLOSED 与关键 TEST ID', async ({ page }) => {
    await page.goto(`${DOC_ORIGIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const section = page.locator('[data-testid="test-doc-02"]');
    await expect(section).toContainText(/TEST-DOC-02/);
    await expect(section).toContainText(/CLOSED|可见/);
    await expect(section).toContainText(/TEST-P1C-01/);
    await expect(section).toContainText(/TEST-SEC-07/);
    await expect(section).toContainText(/8\.7|§8\.7|E2E/);
  });

  test('[case:TEST-DOC-03] SystemConfig 模块文档摘要可见', async ({ page }) => {
    await page.goto(`${DOC_ORIGIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const section = page.locator('[data-testid="test-doc-03"]');
    await expect(section).toContainText(/TEST-DOC-03/);
    await expect(section).toContainText(/AI-INDEX/);
    await expect(section).toContainText(/README/);
    await expect(section).toContainText(/Weline_SystemConfig|SystemConfig|配置/);
  });
});
