/**
 * 营销路线图 Ch5：店面满额进度 / 新客礼部件渲染与契约
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: flow, layer: frontend, feature: storefront-cart-progress }
 * @weline-e2e-runtime wls
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Marketing';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'cart-progress-fixture.php');
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Call to undefined|Class .* not found/i;

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 60000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`cart-progress fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

moduleDescribe(test, MODULE, 'Ch5 店面满额进度', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-006' },
    '契约：cart-progress / welcome-gift 已注册且模板含 testid',
    async () => {
      const reg = runFixture('registry');
      expect(reg.generated_has_cart_progress).toBeTruthy();
      expect(reg.widget_declares_slot).toBeTruthy();
      expect(reg.cart_progress_testid).toBeTruthy();
      expect(reg.welcome_testid).toBeTruthy();
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-007' },
    '渲染：满额进度条 + 新客礼入口可见；Service 进度计算正确',
    async ({ page }) => {
      const math = runFixture('build_progress', { threshold: 99, current: 40, currency: 'CNY' });
      expect(math.build.enabled).toBeTruthy();
      expect(math.build.percent).toBeGreaterThan(0);
      expect(math.build.percent).toBeLessThan(100);
      expect(math.build.remaining).toBeGreaterThan(0);

      const rendered = runFixture('render_cart_progress', {
        threshold: 99,
        show_welcome: true,
        welcome_label: '新客礼',
        welcome_url: '/account/register',
      });
      expect(rendered.has_testid).toBeTruthy();
      expect(rendered.has_welcome).toBeTruthy();

      await page.setContent(
        `<!doctype html><html><body><main id="main-content">${rendered.html}</main></body></html>`,
        { waitUntil: 'domcontentloaded' },
      );
      await expect(page.locator('[data-testid="marketing-cart-progress"]').first()).toBeVisible();
      await expect(page.locator('[role="progressbar"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="marketing-welcome-gift-link"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="marketing-welcome-gift-link"]').first()).toHaveAttribute(
        'href',
        /account\/register/,
      );
      await expect(page.locator('.mkt-cart-progress__msg, .mkt-cart-progress__label').first()).toBeVisible();
      await expect(page.locator('main#main-content')).not.toContainText(FATAL);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-008' },
    '渲染：welcome-gift 独立部件',
    async ({ page }) => {
      const rendered = runFixture('render_welcome_gift');
      expect(rendered.has_testid).toBeTruthy();
      await page.setContent(
        `<!doctype html><html><body><main id="main-content">${rendered.html}</main></body></html>`,
        { waitUntil: 'domcontentloaded' },
      );
      await expect(page.locator('[data-testid="marketing-welcome-gift"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="marketing-welcome-gift"] a').first()).toBeVisible();
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-009' },
    '店面：购物车页槽位 cart-summary-discount 存在（可挂载进度条）',
    async ({ page }) => {
      await gotoFrontend(page, '/cart', { timeout: 90000, settleMs: 800 });
      const body = await page.locator('body').innerText().catch(() => '');
      // 整页可能夹建站助手 Uncaught 噪声，只扫主内容区
      const shell = page.locator('main#main-content, main, [data-wslot="cart-summary-discount"]').first();
      await expect(shell).toBeVisible({ timeout: 20000 });
      await expect(page.locator('[data-wslot="cart-summary-discount"]').first()).toBeAttached();
      expect(/WLS Runtime Error|Fatal error|ParseError/i.test(body)).toBeFalsy();
    },
  );
});
