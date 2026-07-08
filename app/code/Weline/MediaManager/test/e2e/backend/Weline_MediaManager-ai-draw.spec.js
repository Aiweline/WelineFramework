/**
 * Weline_MediaManager AI 作图 E2E
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: feature, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_MediaManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_MediaManager AI 作图', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-AI-001' },
    '文件管理页展示 AI 作图入口与弹窗结构',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'manager');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);

      const aiBtn = page.locator('#mmf-btn-ai-draw');
      await expect(aiBtn).toBeVisible({ timeout: 15000 });
      await aiBtn.click();

      await expect(page.locator('#mmf-ai-draw-overlay')).toHaveClass(/visible/);
      await expect(page.locator('#mmf-ai-prompt')).toBeVisible();
      await expect(page.locator('#mmf-ai-btn-generate')).toBeVisible();
      await expect(page.locator('.mmf-ai-tab[data-mode="batch"]')).toBeVisible();
    }
  );
});
