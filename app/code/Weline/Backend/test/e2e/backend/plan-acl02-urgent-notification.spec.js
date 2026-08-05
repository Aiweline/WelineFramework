/**
 * 万能商城内核计划：scoped urgent 通知有权可见 / 无权不可见（TEST-ACL-02）
 *
 * - 有权用户（Website default VIEW ObjectScopeGrant）在通知中心可见域名/支付严重事件标题与敏感摘要
 * - 无权用户（同通知路由 ACL、零 ObjectScopeGrant）列表不含该条；详情直链「不存在或无权」
 * - 同 dedupe_key 两次 emit → occurrence>=2，UI 仍一条
 *
 * @weline-e2e-spec { module: Weline_Backend, type: plan, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Backend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-acl02-urgent-fixture.php');
const NOTIFICATION_ROUTE = buildModuleBackendRoute(MODULE, 'notification');

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
    throw new Error(`acl02 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function openNotificationCenter(page, username, password) {
  await loginAsAdmin(page, {
    username,
    password,
    timeout: 90000,
    settleMs: 800,
    useProxy: false,
  });
  await gotoBackend(page, NOTIFICATION_ROUTE, {
    timeout: 60000,
    settleMs: 1000,
    useProxy: false,
  });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  await expect(page.locator('body')).toContainText(/通知中心|Notification/i);
}

moduleDescribe(test, MODULE, '计划 ACL-02 scoped urgent 通知可见性', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-ACL-02' },
    '有权用户可见域名/支付严重事件；无权用户不可见敏感摘要',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.notification_id, '必须写入 SystemNotification').toBeGreaterThan(0);
      expect(fixture.occurrence_count, 'dedupe 后 occurrence_count 须 >= 2').toBeGreaterThanOrEqual(2);

      try {
        // —— 有权用户：列表 + 详情含敏感摘要 ——
        await openNotificationCenter(
          page,
          fixture.authorized.username,
          fixture.authorized.password,
        );
        await expect(
          page.locator('body'),
          `有权用户必须看到标题 ${fixture.title}`,
        ).toContainText(fixture.title);

        await gotoBackend(page, `${NOTIFICATION_ROUTE}/detail?id=${fixture.notification_id}`, {
          timeout: 60000,
          settleMs: 800,
          useProxy: false,
        });
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await expect(page.locator('body')).not.toContainText(/通知不存在或无权查看/i);
        await expect(page.locator('body')).toContainText(fixture.title);
        await expect(
          page.locator('body'),
          '详情必须暴露敏感摘要（token / domain / payment）',
        ).toContainText(fixture.sensitive_needle);

        // —— 无权用户：列表不含；详情直链拒绝 ——
        await page.context().clearCookies();
        await openNotificationCenter(
          page,
          fixture.denied.username,
          fixture.denied.password,
        );
        await expect(
          page.locator('body'),
          `无权用户不得看到标题 ${fixture.title}`,
        ).not.toContainText(fixture.title);
        await expect(page.locator('body')).not.toContainText(fixture.sensitive_needle);

        await gotoBackend(page, `${NOTIFICATION_ROUTE}/detail?id=${fixture.notification_id}`, {
          timeout: 60000,
          settleMs: 800,
          useProxy: false,
        });
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await expect(
          page.locator('body'),
          '无权用户直链详情必须拒绝',
        ).toContainText(/通知不存在或无权查看/i);
        await expect(page.locator('body')).not.toContainText(fixture.sensitive_needle);
      } finally {
        runFixture('cleanup', {
          notification_id: fixture.notification_id,
          dedupe_key: fixture.dedupe_key,
          authorized: fixture.authorized,
          denied: fixture.denied,
        });
      }
    },
  );
});
