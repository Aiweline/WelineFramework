/**
 * 营销能力分期路线图 — 后台通路 e2e（Ch1–6 列表/表单/看板）
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: flow, layer: backend, feature: marketing-lifecycle-roadmap }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Marketing';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';

async function openBackend(page, ...parts) {
  const route = buildModuleBackendRoute(MODULE, ...parts);
  await gotoBackend(page, route, { timeout: 90000, settleMs: 800 });
  await waitForBackendShellReady(page);
  // 只扫后台主内容区：整页 body 可能夹带建站助手/前台探针的 JS Uncaught 噪声
  const shell = page.locator(CONTENT_SHELL).first();
  await expect(shell).toBeVisible({ timeout: 20000 });
  await expect(shell).not.toContainText(FATAL);
  return route;
}

moduleDescribe(test, MODULE, '营销路线图后台通路', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-001' },
    'Ch1：挽回列表 + 新建表单含购物车遗弃类型',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await openBackend(page, 'winback', 'index');
      await expect(page.locator('[data-testid="marketing-winback-management"]').first()).toBeVisible({ timeout: 20000 });
      await expect(page.getByRole('heading', { name: /挽回活动/ }).first()).toBeVisible();

      await openBackend(page, 'winback', 'add');
      await expect(page.locator('[data-testid="marketing-winback-form"], form').first()).toBeVisible({ timeout: 20000 });
      const typeSelect = page.locator('select[name="type"], [data-testid="marketing-winback-type"]').first();
      await expect(typeSelect).toBeVisible();
      const options = await typeSelect.locator('option').allTextContents();
      const joined = options.join(' ');
      expect(/购物车遗弃|cart_abandon/i.test(joined)).toBeTruthy();
      const values = await typeSelect.locator('option').evaluateAll((els) => els.map((e) => e.value));
      expect(values).toContain('cart_abandon_reminder');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-002' },
    'Ch2：新建挽回表单含激励规则与分群字段',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await openBackend(page, 'winback', 'add');
      await expect(page.locator('input[name="incentive_rule_id"], [data-testid="marketing-winback-incentive-rule"]').first()).toBeVisible({
        timeout: 20000,
      });
      await expect(page.locator('input[name="segment_id"], [data-testid="marketing-winback-segment"]').first()).toBeVisible();
      await expect(page.locator('input[name="max_steps"]').first()).toBeVisible();
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-003' },
    'Ch3：生命周期活动列表与新建表单',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await openBackend(page, 'lifecycle', 'index');
      await expect(page.locator('[data-testid="marketing-lifecycle-management"]').first()).toBeVisible({ timeout: 20000 });
      await expect(page.getByText(/生命周期/).first()).toBeVisible();

      await openBackend(page, 'lifecycle', 'add');
      await expect(page.locator('[data-testid="marketing-lifecycle-form"]').first()).toBeVisible({ timeout: 20000 });
      const typeSelect = page.locator('[data-testid="marketing-lifecycle-type"], select[name="type"]').first();
      await expect(typeSelect).toBeVisible();
      const values = await typeSelect.locator('option').evaluateAll((els) => els.map((e) => e.value));
      expect(values).toContain('welcome_customer');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-004' },
    'Ch4：营销分群列表与新建表单',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await openBackend(page, 'segment', 'index');
      await expect(page.locator('[data-testid="marketing-segment-management"]').first()).toBeVisible({ timeout: 20000 });

      await openBackend(page, 'segment', 'add');
      await expect(page.locator('[data-testid="marketing-segment-form"]').first()).toBeVisible({ timeout: 20000 });
      const kind = page.locator('[data-testid="marketing-segment-kind"], select[name="kind"]').first();
      await expect(kind).toBeVisible();
      const values = await kind.locator('option').evaluateAll((els) => els.map((e) => e.value));
      expect(values).toEqual(expect.arrayContaining(['new_customer', 'returning', 'idle_days']));
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-ROADMAP-005' },
    'Ch6：挽回运营看板只读汇总（无打开率文案）',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await openBackend(page, 'winbackdashboard', 'index');
      await expect(page.locator('[data-testid="marketing-winback-dashboard"]').first()).toBeVisible({ timeout: 20000 });
      await expect(page.getByText(/挽回运营看板|只读汇总/).first()).toBeVisible();
      await expect(page.getByText(/不含.*打开率|不含.*点击率/).first()).toBeVisible();
      const headers = await page.locator('[data-testid="marketing-winback-dashboard-table"] thead').innerText();
      expect(/打开率|点击率|open rate|click rate/i.test(headers)).toBeFalsy();
      await expect(page.locator('[data-testid="marketing-winback-dashboard-table"]').first()).toBeVisible();
    },
  );
});
