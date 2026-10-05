/**
 * 首页必入注入冒烟：禁止 Runtime Error / presence 标记可见。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: e2e, layer: frontend }
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

const MODULE = 'Weline_Theme';
const ROOT = path.resolve(__dirname, '../../../../../../..');
// Public nginx host; do not default to :9555 worker port (ERR_TIMED_OUT behind managed TLS).
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite homepage required injection smoke', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-layout' }, '首页无 Runtime Error 且必入部件有 presence', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/?e2e_required_injection=${Date.now()}`, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });

    await expect(page.locator('body')).not.toContainText(
      /Fatal error|ParseError|WLS Runtime Error|required_default_injection_|unexpected token "endif"/i,
    );
    await expect(page.locator('#hamburger-menu, [data-widget-code="all-menu"]').first()).toBeAttached({
      timeout: 20000,
    });
    await expect(page.locator('[data-widget-code="checkout-delivery-context"], [data-checkout-delivery-widget]').first())
      .toBeAttached({ timeout: 20000 });
    await expect(page.locator('header, .weline-header, [weline-code*="header"]').first()).toBeAttached();
  });

  moduleCase(test, { module: MODULE, id: 'B-source-contract' }, '源码契约：纯 PHTML 固化下 Overlay 禁运行时播种且部件声明保留', async () => {
    const allMenu = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/widgets/navigation/all-menu/default.phtml'),
      'utf8',
    );
    const category = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/widgets/navigation/category-menu/default.phtml'),
      'utf8',
    );
    const footer = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/widgets/container/footer/default.phtml'),
      'utf8',
    );
    const overlay = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'),
      'utf8',
    );
    const storeMusic = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/StoreMusic/view/templates/frontend/widgets/store-music.phtml'),
      'utf8',
    );

    expect(allMenu).toContain('data-widget-code="all-menu"');
    expect(category).toContain('data-widget-code="category-menu"');
    expect(footer).toContain('data-widget-code="footer-container"');
    // Pure-PHTML contract: storefront overlay is a no-op compatibility boundary
    // (relationships are emitted into derived PHTML at save time only).
    expect(overlay).toMatch(/Compatibility boundary:\s*layout relationships are emitted only into PHTML at save time/);
    expect(overlay).toMatch(/:\s*string\s*\{\s*return\s+\$rendered;\s*\}/);
    expect(overlay).not.toContain('data-required-injection-presence');
    expect(storeMusic).toContain('data-widget-code="store-music"');
    expect(storeMusic).toContain("presenceStub('inactive')");
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：必入注入契约 UT', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/bin/phpunit'),
        '--no-configuration',
        '--bootstrap',
        path.join(ROOT, 'app/bootstrap.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/LayoutEntity/RequiredDefaultInjectionContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/LayoutEntity/RequiredDefaultInjectionGhostSlotTest.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/Service/AllMenu/AllMenuNavTreeContractTest.php'),
        path.join(ROOT, 'app/code/Weline/StoreMusic/Test/Unit/Widget/StoreMusicDefaultInjectionContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' },
    );
    expect(out).toMatch(/OK \(/);
  });
});
