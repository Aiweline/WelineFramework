/**
 * PDP 个性化推荐收口 + 计划链路 e2e 组套件。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: feature, layer: frontend }
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
const PDP = process.env.WELINE_E2E_PDP_PATH || '/product/192';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite personalization', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-closeout' }, '完整通路 Playwright：文档与 Browser 验收后的前后端闭环', async ({ page }) => {
    const log = fs.readFileSync(path.join(ROOT, 'app/code/Weline/Theme/doc/开发日志.md'), 'utf8');
    expect(log).toContain('2.2.330');
    expect(log).toContain('猜你喜欢');

    await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('.product-detail-layout__personalization').first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('heading', { name: '猜你喜欢' }).first()).toBeVisible();
    await expect(page.getByText('根据当前商品为你推荐').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: '最近浏览' }).first()).toBeVisible();
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总套件：布局槽常显 + 默认注入 + PDP 底部两部件', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/phpunit/phpunit/phpunit'),
        '--configuration',
        path.join(ROOT, 'tests/phpunit/config.xml'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/View/Layouts/ProductLayoutReviewsSlotContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/View/YouMayLikeDefaultInjectionContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(out).toMatch(/OK/);
  });
});
