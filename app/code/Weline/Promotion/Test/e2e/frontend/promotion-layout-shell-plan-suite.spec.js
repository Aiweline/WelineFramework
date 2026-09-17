/**
 * Promotion shell layout ownership + storefront sample routes (W1).
 *
 * @weline-e2e-spec { module: Weline_Promotion, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Promotion';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite promotion layout shell', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-closeout' }, '店面 /promotion 与 /promotion/deals 同壳 layout=promotion', async ({ page }) => {
    await page.goto(`${BASE}/promotion`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-layout="promotion"]').first()).toBeVisible({ timeout: 20000 });
    await expect(page.locator('body.w-theme-promotion-layout').first()).toBeVisible();
    await expect(page.locator('[data-wslot="promotion-bottom"]').first()).toBeAttached();

    await page.goto(`${BASE}/promotion/deals`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-layout="promotion"]').first()).toBeVisible({ timeout: 20000 });
    await expect(page.locator('body.w-theme-promotion-layout').first()).toBeVisible();
    await expect(page.locator('[data-wslot="promotion-bottom"]').first()).toBeAttached();
  });

  moduleCase(test, { module: MODULE, id: 'B-editor-sample' }, '编辑器源码契约：无 deals 布局键；preview-sample / theme_public_route 已接线', async () => {
    const themeEditorJs = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/statics/js/theme-editor.js'),
      'utf8'
    );
    const themeEditorPhtml = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml'),
      'utf8'
    );
    const themeLayout = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/Model/ThemeLayout.php'),
      'utf8'
    );
    const compiled = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js'),
      'utf8'
    );

    expect(themeLayout).toContain("PAGE_TYPE_PROMOTION = 'promotion'");
    expect(themeLayout).not.toContain('PAGE_TYPE_DEALS');
    expect(themeEditorPhtml).toContain('data-api-preview-sample=');
    expect(themeEditorPhtml).toContain('previewSampleSelect');
    expect(themeEditorJs).toContain('refreshPreviewSample');
    expect(themeEditorJs).toContain('theme_public_route: themePublicRoute');
    expect(compiled).toContain('refreshPreviewSample');
    expect(compiled).toContain('apiPreviewSample');
    expect(fs.existsSync(path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/layouts/promotion/default.phtml'))).toBe(false);
    expect(fs.existsSync(path.join(ROOT, 'app/code/Weline/Promotion/view/theme/frontend/layouts/promotion/default.phtml'))).toBe(true);
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：layout resolve/sample 契约', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/phpunit/phpunit/phpunit'),
        '--configuration',
        path.join(ROOT, 'tests/phpunit/config.xml'),
        path.join(ROOT, 'app/code/Weline/Promotion/Test/Unit/Routing/PromotionLayoutResolveSampleContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Promotion/Test/Unit/Routing/PromotionStorefrontLayoutContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/Service/LayoutResolveServiceContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(out).toMatch(/OK/);
  });
});
