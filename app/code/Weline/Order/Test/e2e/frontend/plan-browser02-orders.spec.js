/**
 * 万能商城内核计划：顾客账户 CheckoutGroup partial/退款/发票展示（TEST-BROWSER-02）
 *
 * - 必须走官方 Weline_Customer account 布局（#orders → account.sidebar.content）
 * - 真实夹具：同一 Group 两笔 Order；当前 RefundCase processing；两笔 issued Invoice；
 *   履约状态 partial/pending
 * - 决定性 DOM：data-partial="1"、退款/发票/履约语义、data-partial-expanded
 *
 * @weline-e2e-spec { module: Weline_Order, type: plan, layer: frontend }
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

const MODULE = 'Weline_Order';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-browser02-orders-fixture.php');
const DIRECT = { useProxy: false };

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`browser02 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function loginAsCustomer(page, email, password) {
  await gotoFrontend(page, '/customer/account/login', { timeout: 60000, settleMs: 800, ...DIRECT });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

  await page.waitForFunction(() => window.Weline
    && ((window.Weline.Api && typeof window.Weline.Api.resource === 'function')
      || typeof window.Weline.load === 'function'), { timeout: 30000 });
  const result = await page.evaluate(async ({ email, password }) => {
    let api = window.Weline.Api;
    if (!api || typeof api.resource !== 'function') {
      api = await window.Weline.load('api');
    }
    const account = await api.resource('account');
    return account.login({
      email,
      username: email,
      password,
    }, { useProxy: false });
  }, { email, password });
  expect(
    result && (result.success === true || result.data?.success === true),
    `account.login failed: ${JSON.stringify(result)}`,
  ).toBeTruthy();
  await gotoFrontend(page, '/customer/account/index', { timeout: 60000, settleMs: 800, ...DIRECT });
  await expect(page).not.toHaveURL(/customer\/account\/login/, { timeout: 15000 });
}

async function openOrdersSection(page) {
  await gotoFrontend(page, '/customer/account/index#orders', { timeout: 60000, settleMs: 1000, ...DIRECT });
  // 侧栏懒加载：点击「我的订单」触发 section=orders 内容注入
  const nav = page.locator('[data-account-nav-link="true"][data-section="orders"]').first();
  if (await nav.isVisible({ timeout: 5000 }).catch(() => false)) {
    await nav.click();
  } else {
    await page.evaluate(() => {
      window.location.hash = 'orders';
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
  }

  const orders = page.locator('[data-account-orders="true"]');
  await expect(orders).toBeVisible({ timeout: 30000 });
  return orders;
}

moduleDescribe(test, MODULE, '计划账户 CheckoutGroup Browser 用例', () => {
  test.setTimeout(240000);

  /** @type {{ customer_id:number, email:string, password:string, group_uuid:string, order_ids:number[], order_uuids:string[], order_numbers:string[] }|null} */
  let fixture = null;

  test.beforeAll(() => {
    fixture = runFixture('prepare');
  });

  test.afterAll(() => {
    if (!fixture) {
      return;
    }
    try {
      runFixture('cleanup', {
        customer_id: fixture.customer_id,
        group_uuid: fixture.group_uuid,
        order_ids: fixture.order_ids,
      });
    } catch (_) {
      // best-effort
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-BROWSER-02' },
    '账户 #orders：partial Group 展开 + 退款处理中 + 已开票语义',
    async ({ page }) => {
      expect(fixture, 'fixture 必须准备成功').toBeTruthy();

      await loginAsCustomer(page, fixture.email, fixture.password);
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      const orders = await openOrdersSection(page);
      await expect(orders).toHaveAttribute('data-account-layout', 'customer-sidebar');
      await expect(page.locator('[data-orders-empty="true"]')).toHaveCount(0);

      const group = orders.locator(`[data-group-uuid="${fixture.group_uuid}"]`).first();
      await expect(group, '必须渲染夹具 CheckoutGroup').toBeVisible({ timeout: 15000 });
      await expect(group).toHaveAttribute('data-partial', '1');
      await expect(group).toHaveAttribute('data-view', 'partial_expanded');

      await expect(group.locator('[data-refund-semantic="true"]').first()).toContainText(/退款处理中|退款/);
      await expect(group.locator('[data-invoice-semantic="true"]').first()).toContainText(/已开票|发票/);
      await expect(group.locator('[data-fulfillment-semantic="true"]').first()).toContainText(/部分履约|待发货|履约/);

      const expanded = group.locator('[data-partial-expanded="true"]');
      await expect(expanded).toBeVisible();
      await expect(expanded.locator('li')).toHaveCount(2);
      await expect(expanded).toContainText(fixture.order_numbers[0]);
      await expect(expanded).toContainText(fixture.order_numbers[1]);
      await expect(expanded.locator('[data-refund-label="true"]').first()).toContainText(/退款处理中|无退款|退款/);
      await expect(expanded.locator('[data-invoice-label="true"]').first()).toContainText(/已开票|发票/);
      await expect(expanded.locator('[data-fulfillment-label="true"]').first()).toContainText(/部分履约|待发货|履约/);

      // 布局契约：仍在官方 Customer account 壳内，不是独立 /account/orders 页
      expect(page.url()).toMatch(/\/customer\/account/);
      expect(page.url()).not.toMatch(/\/account\/orders(?:\/|$)/);
      await expect(page.locator('[data-account-nav-link="true"][data-section="orders"]').first()).toBeVisible();
    },
  );
});
