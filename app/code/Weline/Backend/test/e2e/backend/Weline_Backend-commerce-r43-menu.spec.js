/**
 * R4.3 后台能力：所有入口必须从真实侧栏菜单进入，禁止深链假绿。
 *
 * @weline-e2e-spec { module: Weline_Backend, type: flow, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  collectBackendMenuSnapshot,
  expectBackendMenuTopology,
  gotoBackend,
  installBackendBrowserGuards,
  loginAsAdmin,
  moduleCase,
  moduleDescribe,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Backend';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const MANIFEST_PATH = path.join(ROOT_DIR, 'tests/e2e/manifests/commerce-kernel-r43.json');
const FIXTURE_SCRIPT = path.join(__dirname, 'commerce-r43-acl-fixture.php');

function manifest() {
  return JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
}

function runFixture(action, payload = {}) {
  const output = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(output).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines.at(-1) || '{}');
  if (!result.ok) throw new Error(`R4.3 ACL fixture ${action} failed: ${result.error || output}`);
  return result;
}

function visibleR43Sources(snapshot, data) {
  const expected = new Set(data.capabilities.map((item) => item.sourceId));
  return snapshot.map((item) => item.sourceId).filter((source) => expected.has(source)).sort();
}

function r43Topology(snapshot, data) {
  const expected = new Set(data.capabilities.map((item) => item.sourceId));
  return snapshot
    .filter((item) => expected.has(item.sourceId))
    .map((item) => ({
      sourceId: item.sourceId,
      parentSource: item.parentSource,
      title: item.title,
      href: item.href,
      isGroup: item.isGroup,
    }))
    .sort((left, right) => left.sourceId.localeCompare(right.sourceId));
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function aclDenialResponseRules(data) {
  return data.capabilities.map((capability) => {
    const actionPath = String(capability.action).split('?', 1)[0].replace(/^\/+|\/+$/g, '');
    return { statuses: [401, 403], pattern: new RegExp(`/${escapeRegExp(actionPath)}(?:[/?]|$)`, 'i') };
  });
}

function assertDedicatedRuntimeBinding() {
  const instance = String(process.env.WELINE_E2E_WLS_INSTANCE || '');
  if (!/^ai-test-commerce-r43-[a-z0-9-]+$/i.test(instance)) {
    throw new Error('R4.3 requires a dedicated WELINE_E2E_WLS_INSTANCE');
  }
  const origin = String(process.env.PLAYWRIGHT_TARGET_ORIGIN || '');
  if (!origin) throw new Error('R4.3 requires PLAYWRIGHT_TARGET_ORIGIN');
  const target = new URL(origin);
  const port = Number(target.port || (target.protocol === 'https:' ? 443 : 80));
  if (!Number.isInteger(port) || port < 9502 || port === 9501) {
    throw new Error(`R4.3 refuses shared/reserved target port: ${port}`);
  }
  const rawStatus = execFileSync('php', ['bin/w', 'server:status', instance], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const status = rawStatus.replace(/\u001b\[[0-9;]*m/g, '');
  if (!status.includes(`实例名称：${instance}`)
    || !status.includes(`:${port}`)
    || !/状态：全部运行中/.test(status)) {
    throw new Error(`R4.3 target origin is not bound to the named live WLS instance: ${instance}@${port}`);
  }
}

async function expectDirectAccessDenied(page, capability, response) {
  const actionPath = String(capability.action).split('?', 1)[0];
  const normalizedAction = `/${actionPath.replace(/^\/+|\/+$/g, '')}`;
  const finalUrl = new URL(page.url());
  const status = response ? response.status() : 0;
  const denialReason = finalUrl.searchParams.get('no_access_reason') || '';
  const explicitReasons = new Set([
    'no_role',
    'no_any_permission',
    'no_permission_for_route',
    'no_usable_permission',
  ]);
  const body = await page.locator('body').innerText().catch(() => '');
  const explicitBody = /无权限|没有访问.*权限|permission denied|access denied|object_scope_access_denied/i
    .test(body);

  expect(status, `未授权直达不能以 404 假装 ACL 拒绝: ${capability.sourceId}`).not.toBe(404);
  const stayedOnAction = decodeURI(finalUrl.pathname).includes(normalizedAction);
  expect(
    stayedOnAction && status >= 200 && status < 400,
    `未授权直达仍以成功响应停留在目标路由: ${capability.sourceId}; status=${status}`
  ).toBe(false);
  expect(
    status === 401
      || status === 403
      || explicitReasons.has(denialReason)
      || explicitBody,
    `未授权直达没有明确 ACL 拒绝证据: ${capability.sourceId}; status=${status}; url=${finalUrl}`
  ).toBe(true);
  expect(denialReason, `登录态丢失不能充当 ACL 拒绝: ${capability.sourceId}`).not.toBe('not_logged_in');
}

async function loginIdentity(page, identity) {
  await page.context().clearCookies();
  await loginAsAdmin(page, {
    username: identity.username,
    password: identity.password,
    timeout: 90000,
    settleMs: 800,
    useProxy: false,
  });
  const sidebar = page.locator('.vertical-menu').first();
  await expect(sidebar, `ACL 账号侧栏未渲染: ${identity.profile}`).toBeVisible({ timeout: 20000 });
  await expect(sidebar, `ACL 账号 user_id 串号: ${identity.profile}`)
    .toHaveAttribute('data-backend-user-id', String(identity.user_id));
  await expect(sidebar, `ACL 账号 role_id 串号: ${identity.profile}`)
    .toHaveAttribute('data-backend-role-id', String(identity.role_id));
}

moduleDescribe(test, MODULE, 'R4.3 万能商城后台菜单与 ACL', () => {
  test.setTimeout(20 * 60 * 1000);
  test.beforeAll(() => assertDedicatedRuntimeBinding());

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-MENU-001' },
    '能力清单与真实后台菜单拓扑一致且没有重复入口',
    async ({ page }, testInfo) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page);
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      const snapshot = await expectBackendMenuTopology(page, data);
      expect(visibleR43Sources(snapshot, data)).toEqual(
        data.capabilities.map((item) => item.sourceId).sort()
      );
      await testInfo.attach('commerce-r43-menu-snapshot.json', {
        body: Buffer.from(JSON.stringify(snapshot, null, 2)),
        contentType: 'application/json',
      });
      guards.assertClean();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-MENU-002' },
    '逐个真实点击全部管理入口并验证目标页面锚点',
    async ({ page }) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page);
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });

      for (const capability of data.capabilities) {
        await test.step(`${capability.capabilityId}: ${capability.title}`, async () => {
          await openBackendMenuBySource(page, capability.sourceId, {
            title: capability.title,
            parentSources: capability.parentSources || [capability.parentSource],
            urlIncludes: capability.urlIncludes,
            pageAnchor: capability.pageAnchor,
          });
        });
      }
      guards.assertClean();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-MENU-003' },
    '折叠侧栏、移动视口和菜单搜索仍能进入代表性工作台',
    async ({ page }) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page);
      const representatives = [];
      const parents = new Set();
      for (const capability of data.capabilities) {
        if (!parents.has(capability.parentSource)) {
          parents.add(capability.parentSource);
          representatives.push(capability);
        }
      }

      await page.setViewportSize({ width: 768, height: 900 });
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      const toggle = page.locator('#vertical-menu-btn, button.vertical-menu-btn, .button-menu-mobile').first();
      if (await toggle.isVisible().catch(() => false)) await toggle.click();

      for (const capability of representatives) {
        await openBackendMenuBySource(page, capability.sourceId, {
          title: capability.title,
          forceSearch: true,
          urlIncludes: capability.urlIncludes,
          pageAnchor: capability.pageAnchor,
        });
      }
      guards.assertClean();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-001' },
    '全权限临时角色显示全部能力入口',
    async ({ page }) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page);
      const fixture = runFixture('prepare');
      try {
        await loginIdentity(page, fixture.full);
        const snapshot = await expectBackendMenuTopology(page, data);
        expect(visibleR43Sources(snapshot, data)).toEqual(
          data.capabilities.map((item) => item.sourceId).sort()
        );
        for (const capability of data.capabilities) {
          await test.step(`full allow ${capability.capabilityId}`, async () => {
            await openBackendMenuBySource(page, capability.sourceId, {
              title: capability.title,
              parentSources: capability.parentSources || [capability.parentSource],
              urlIncludes: capability.urlIncludes,
              pageAnchor: capability.pageAnchor,
            });
          });
        }
        guards.assertClean();
      } finally {
        runFixture('cleanup', fixture);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-002' },
    '零业务权限角色看不到任何能力入口且逐入口直达均被拒绝',
    async ({ page }) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page, {
        allowedResponses: aclDenialResponseRules(data),
      });
      const fixture = runFixture('prepare');
      try {
        await loginIdentity(page, fixture.denied);
        const snapshot = await collectBackendMenuSnapshot(page);
        expect(visibleR43Sources(snapshot, data)).toEqual([]);

        for (const capability of data.capabilities) {
          await test.step(`deny ${capability.capabilityId}`, async () => {
            const response = await gotoBackend(page, capability.action, {
              timeout: 60000,
              settleMs: 250,
              useProxy: false,
            });
            await expectDirectAccessDenied(page, capability, response);
          });
        }
        guards.assertClean();
      } finally {
        runFixture('cleanup', fixture);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-003' },
    'commerce:catalog 标签授权只展开商品与目录分支',
    async ({ page }) => {
      const data = manifest();
      const guards = installBackendBrowserGuards(page, {
        allowedResponses: aclDenialResponseRules(data),
      });
      const fixture = runFixture('prepare');
      try {
        await loginIdentity(page, fixture.partial);
        const snapshot = await collectBackendMenuSnapshot(page);
        const actual = visibleR43Sources(snapshot, data);
        const expected = data.capabilities
          .filter((item) => item.accessProfile === 'catalog_manager')
          .map((item) => item.sourceId)
          .sort();
        expect(actual).toEqual(expected);

        for (const capability of data.capabilities.filter(
          (item) => item.accessProfile === 'catalog_manager'
        )) {
          await test.step(`partial allow ${capability.capabilityId}`, async () => {
            await openBackendMenuBySource(page, capability.sourceId, {
              title: capability.title,
              parentSources: capability.parentSources || [capability.parentSource],
              urlIncludes: capability.urlIncludes,
              pageAnchor: capability.pageAnchor,
            });
          });
        }

        for (const capability of data.capabilities.filter(
          (item) => item.accessProfile !== 'catalog_manager'
        )) {
          await test.step(`partial deny ${capability.capabilityId}`, async () => {
            const response = await gotoBackend(page, capability.action, {
              timeout: 60000,
              settleMs: 250,
              useProxy: false,
            });
            await expectDirectAccessDenied(page, capability, response);
          });
        }
        guards.assertClean();
      } finally {
        runFixture('cleanup', fixture);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-REFRESH-001' },
    '重复菜单收集、页面刷新和新浏览器上下文保持相同拓扑',
    async ({ page, browser }) => {
      const guards = installBackendBrowserGuards(page);
      if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
        throw new Error('CK-R43-REFRESH-001 requires WELINE_E2E_ISOLATED_DB=1');
      }
      const instance = String(process.env.WELINE_E2E_WLS_INSTANCE || '');
      if (!/^ai-test-commerce-r43-[a-z0-9-]+$/i.test(instance)) {
        throw new Error('CK-R43-REFRESH-001 requires a dedicated WELINE_E2E_WLS_INSTANCE');
      }
      execFileSync('php', ['bin/w', 'menu:collect'], { cwd: ROOT_DIR, stdio: 'pipe' });
      execFileSync('php', ['bin/w', 'menu:collect'], { cwd: ROOT_DIR, stdio: 'pipe' });

      const data = manifest();
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await expectBackendMenuTopology(page, data);
      const first = r43Topology(await collectBackendMenuSnapshot(page), data);
      execFileSync('php', ['bin/w', 'server:reload', instance], { cwd: ROOT_DIR, stdio: 'pipe' });
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expectBackendMenuTopology(page, data);
      const afterReload = r43Topology(await collectBackendMenuSnapshot(page), data);

      const context = await browser.newContext();
      const freshPage = await context.newPage();
      const freshGuards = installBackendBrowserGuards(freshPage);
      try {
        await loginAsAdmin(freshPage, { timeout: 90000, settleMs: 800 });
        await expectBackendMenuTopology(freshPage, data);
        const fresh = r43Topology(await collectBackendMenuSnapshot(freshPage), data);
        expect(afterReload).toEqual(first);
        expect(fresh).toEqual(first);
        freshGuards.assertClean();
      } finally {
        await context.close();
      }
      guards.assertClean();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-RENAME-001' },
    '菜单收集在孤儿清理前迁移 Checkout 与旧订单状态角色授权',
    async () => {
      if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
        throw new Error('CK-R43-ACL-RENAME-001 requires WELINE_E2E_ISOLATED_DB=1');
      }
      const fixture = runFixture('prepare-rename');
      try {
        execFileSync('php', ['bin/w', 'menu:collect'], { cwd: ROOT_DIR, stdio: 'pipe' });
        const result = runFixture('assert-rename', { identity: fixture.identity });
        expect(result.before_sources).toEqual([
          'Weline_Checkout::order_list',
          'Weline_Checkout::order_update_status',
          'Weline_Checkout::order_view',
          'Weline_Order::status_index',
        ]);
        expect(result.expected_sources).toEqual([
          'Weline_Order::order_list',
          'Weline_Order::order_update_status',
          'Weline_Order::order_view',
          'Weline_Order::status_manage',
        ]);
        expect(result.sources).toEqual(result.expected_sources);
      } finally {
        runFixture('cleanup', {
          full: fixture.identity,
          partial: null,
          denied: null,
        });
      }
    }
  );
});
