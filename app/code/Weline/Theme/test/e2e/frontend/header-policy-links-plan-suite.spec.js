/**
 * Header 政策/关于链接部件通路。
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
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite header policy links', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-layout' }, '完整通路：关于我们直链 + 购物政策细分下拉', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/?e2e_policy_menu=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const nav = page.locator('[data-testid="header-policy-links"]').first();
    await expect(nav).toBeAttached({ timeout: 20000 });
    await expect(nav.locator('a.header-policy-links__inline').filter({ hasText: '关于我们' }).first()).toBeAttached({
      timeout: 15000,
    });
    await expect(nav.locator('.header-policy-links__trigger, button').filter({ hasText: '购物政策' }).first()).toBeAttached();
    await expect(nav.locator('a').filter({ hasText: '配送政策' }).first()).toBeAttached();
    await expect(nav.locator('.header-policy-links__section').filter({ hasText: '法律与隐私' }).first()).toBeAttached();
    await expect(nav.locator('.header-policy-links__section').filter({ hasText: '配送与售后' }).first()).toBeAttached();
    await expect(page.locator('.header-main-nav [data-testid="header-policy-links"] > a.header-policy-links__item')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);
  });

  moduleCase(test, { module: MODULE, id: 'B-source-contract' }, '源码契约：header 槽与部件模板', async () => {
    const header = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml'),
      'utf8'
    );
    const widget = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/widgets/header/header-policy-links/default.phtml'),
      'utf8'
    );
    expect(header).toContain('id="header-policy-links"');
    expect(header).toContain('name="header-policy-links"');
    expect(header).toContain('multiple="true"');
    expect(header).toContain('partials::header::policy-links');
    expect(header.indexOf('id="category-menu"')).toBeLessThan(header.indexOf('id="header-policy-links"'));
    expect(widget).toContain('data-testid="header-policy-links"');
    expect(widget).toContain('type="header_policy_links"');
    expect(widget).toContain('@widget.exclusive {false}');
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：HeaderPolicyLinks 契约', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/phpunit/phpunit/phpunit'),
        '--configuration',
        path.join(ROOT, 'tests/phpunit/config.xml'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/HeaderPolicyLinksHelperTest.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/HeaderPolicyLinksWidgetContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(out).toMatch(/OK/);
  });
});
