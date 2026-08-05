/** @weline-e2e-spec { module: Weline_Cdn, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Cdn';
const PARENT = 'Weline_Cdn::cdn_manager';
const FIXTURE = path.join(__dirname, 'Weline_Cdn-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['Weline_Cdn::cdn_account_manager', '账户管理', 'cdn-account-management', 'CK-R43-CDN-001'],
  ['Weline_Cdn::cdn_domain_manager', '域名管理', 'cdn-domain-management', 'CK-R43-CDN-002'],
  ['Weline_Cdn::cdn_rules_manager', '规则管理', 'cdn-rules-management', 'CK-R43-CDN-003'],
  ['Weline_Cdn::cdn_api_rules_manager', 'API规则管理', 'cdn-api-rules-management', 'CK-R43-CDN-004'],
  ['Weline_Cdn::cdn_warmup_manager', '预热管理', 'cdn-warmup-management', 'CK-R43-CDN-005'],
  ['Weline_Cdn::cdn_attack_log_manager', '攻击日志', 'cdn-attack-log-management', 'CK-R43-CDN-006'],
];
moduleDescribe(test, MODULE, 'R4.3 CDN 后台菜单', () => {
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CDN-WRITE-001' }, '账户管理通过菜单创建本地 CDN 账户并持久化密封凭据', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_account' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[0][0], { parentSources: [PARENT], title: ITEMS[0][1], pageAnchor: `[data-testid="${ITEMS[0][2]}"]` });
      await page.getByTestId('cdn-account-create').locator(':scope > button').click();
      const editorFrame = page.frameLocator('iframe[id^="cdn_account_add_offcanvas"][id$="Iframe"]');
      const form = editorFrame.getByTestId('cdn-account-editor-form');
      await expect(form).toBeVisible();
      await form.locator('[name="adapter"]').selectOption(fixture.adapter);
      await form.locator('[name="name"]').fill(fixture.name);
      await form.locator('[name="description"]').fill(fixture.description);
      await form.locator('[name="status"]').selectOption('active');
      await form.locator('[name="credentials[api_token]"]').fill(fixture.api_token);
      await editorFrame.getByTestId('cdn-account-editor-submit').click();
      const persisted = await waitForFixture({ action: 'inspect_account', token: fixture.token, adapter: fixture.adapter });
      expect(persisted.account_id).toBeGreaterThan(0);
      expect(persisted.credentials_sealed).toBe(true);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_account', token: fixture.token });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CDN-WRITE-002' }, '域名管理通过菜单创建隔离域名并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_domain' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[1][0], { parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: `[data-testid="${ITEMS[1][2]}"]` });
      await page.getByTestId('cdn-domain-create').click();
      const form = page.getByTestId('cdn-domain-editor-form');
      await expect(form).toBeVisible();
      await form.locator('[name="site_id"]').selectOption(String(fixture.website_id));
      await form.locator('[name="adapter"]').selectOption(fixture.adapter);
      await form.locator('[name="domain_name"]').fill(fixture.domain_name);
      await form.locator('[name="zone_id"]').fill(fixture.zone_id);
      const inheritDefault = form.locator('[name="inherit_default"]');
      if (!(await inheritDefault.isChecked())) await inheritDefault.check();
      const enabled = form.locator('[name="enabled"]');
      if (!(await enabled.isChecked())) await enabled.check();
      await page.getByTestId('cdn-domain-editor-submit').click();
      await expect.poll(async () => form.getAttribute('data-state'), {
        timeout: 10000,
        intervals: [100, 250, 500],
      }).toMatch(/^(saved|error)$/);
      expect(await form.getAttribute('data-state'), await form.getAttribute('data-message')).toBe('saved');
      const persisted = await waitForFixture({ action: 'inspect_domain', token: fixture.token, website_id: fixture.website_id, adapter: fixture.adapter });
      expect(persisted.domain_id).toBeGreaterThan(0);
      expect(persisted.domain_name).toBe(fixture.domain_name);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_domain', token: fixture.token });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CDN-WRITE-003' }, '规则管理通过菜单保存域名覆盖规则且不推送外部 CDN', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_rules' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[2][0], { parentSources: [PARENT], title: ITEMS[2][1], pageAnchor: `[data-testid="${ITEMS[2][2]}"]` });
      await page.locator('#domainSelect').selectOption(String(fixture.domain_id));
      const expectedRules = { browser_case: 'CK-R43-CDN-WRITE-003', token: fixture.token, cache_ttl: 321 };
      await page.locator('#rulesTextarea').fill(JSON.stringify(expectedRules, null, 2));
      await page.locator('[data-cdn-rules-action="save"]').click();
      const persisted = await waitForFixture({ action: 'inspect_rules', token: fixture.token });
      expect(persisted.rules).toEqual(expectedRules);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_rules', token: fixture.token });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CDN-WRITE-004' }, 'API 规则管理通过菜单切换隔离规则状态', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_api_rule' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[3][0], { parentSources: [PARENT], title: ITEMS[3][1], pageAnchor: `[data-testid="${ITEMS[3][2]}"]` });
      const toggle = page.locator(`[data-cdn-api-rules-action="toggle"][data-rule-id="${fixture.rule_id}"]`);
      await expect(toggle).toBeChecked();
      await toggle.uncheck();
      const persisted = await waitForFixture({ action: 'inspect_api_rule', token: fixture.token, rule_id: fixture.rule_id, expected_enabled: fixture.target_enabled });
      expect(persisted.enabled).toBe(fixture.target_enabled);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_api_rule', token: fixture.token, rule_id: fixture.rule_id });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-CDN-WRITE-005' }, '预热管理通过菜单切换隔离 URL 状态且不执行真实预热', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const fixture = runFixture({ action: 'prepare_warmup' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[4][0], { parentSources: [PARENT], title: ITEMS[4][1], pageAnchor: `[data-testid="${ITEMS[4][2]}"]` });
      const toggle = page.locator(`[data-cdn-warmup-action="toggle-enable"][data-url-id="${fixture.warmup_url_id}"]`);
      await expect(toggle).toContainText('禁用');
      await toggle.click();
      await expect.poll(async () => toggle.getAttribute('data-state'), {
        timeout: 10000,
        intervals: [100, 250, 500],
      }).toMatch(/^(saved|error)$/);
      expect(await toggle.getAttribute('data-state'), await toggle.getAttribute('data-message')).toBe('saved');
      const persisted = await waitForFixture({ action: 'inspect_warmup', token: fixture.token, warmup_url_id: fixture.warmup_url_id, expected_enabled: fixture.target_enabled });
      expect(persisted.enabled).toBe(fixture.target_enabled);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_warmup', token: fixture.token, warmup_url_id: fixture.warmup_url_id });
    }
  });
});

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`CDN fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}

async function waitForFixture(payload) {
  let persisted = null;
  await expect.poll(() => {
    try {
      persisted = runFixture(payload);
      return true;
    } catch (_) {
      return false;
    }
  }, { timeout: 10000, intervals: [100, 250, 500] }).toBe(true);
  return persisted;
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 CDN write cases require WELINE_E2E_ISOLATED_DB=1');
}
