/**
 * TASK-P4D-002: CustomerAsset projection inside the official Customer account layout.
 *
 * @weline-e2e-spec { module: Weline_CustomerAsset, type: plan, layer: frontend }
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

const MODULE = 'Weline_CustomerAsset';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p4d2-account-assets-fixture.php');
const DIRECT = { useProxy: false };

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`P4D2 asset fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function applyFixtureSession(page, session) {
  const target = new URL(
    process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://127.0.0.1',
  );
  const isStandardPort = target.port === ''
    || (target.protocol === 'https:' && target.port === '443')
    || (target.protocol === 'http:' && target.port === '80');
  const cookieName = isStandardPort
    ? session.name
    : `${session.name}_${target.port}`;
  await page.context().addCookies([{
    name: cookieName,
    value: session.id,
    domain: target.hostname,
    path: session.cookie_path || '/',
    httpOnly: true,
    secure: target.protocol === 'https:',
    sameSite: 'Lax',
    expires: Math.floor(Date.now() / 1000) + Number(session.cookie_lifetime || 3600),
  }]);
}

async function openAssetsSection(page) {
  await gotoFrontend(page, '/customer/account/index#assets', {
    timeout: 60000,
    settleMs: 1000,
    ...DIRECT,
  });
  const nav = page.locator(
    '[data-account-nav-link="true"][data-section="assets"]',
  ).first();
  if (await nav.isVisible({ timeout: 5000 }).catch(() => false)) {
    await nav.click();
  } else {
    await page.evaluate(() => {
      window.location.hash = 'assets';
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
  }

  const section = page.locator('[data-account-section="assets"]').first();
  await expect(section).toBeVisible({ timeout: 30000 });
  return section;
}

moduleDescribe(test, MODULE, 'TASK-P4D-002 顾客资产账户验收', () => {
  test.setTimeout(240000);

  /** @type {{ customer_id:number, session:{name:string,id:string,cookie_path:string,cookie_lifetime:number}, expected:Record<string, unknown> }|null} */
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
        session_id: fixture.session.id,
      });
    } catch (_) {
      // best-effort cleanup; the fixture uses an isolated e2e email prefix.
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P4D2-BROWSER-01' },
    '测试 WLS Session 下的官方 #assets：PostgreSQL 余额、预占与最近账本记录',
    async ({ page }) => {
      expect(fixture, 'fixture 必须准备成功').toBeTruthy();
      const consoleErrors = [];
      const pageErrors = [];
      page.on('console', (message) => {
        if (message.type() === 'error') {
          consoleErrors.push(message.text());
        }
      });
      page.on('pageerror', (error) => {
        pageErrors.push(String(error && error.message ? error.message : error));
      });

      await applyFixtureSession(page, fixture.session);
      const section = await openAssetsSection(page);

      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await expect(section).toHaveAttribute('weline-code', 'customer_asset.hook.account_sidebar_content.assets_section');
      await expect(
        page.locator('[data-account-nav-link="true"][data-section="assets"]').first(),
      ).toBeVisible();
      await expect(section).toContainText('我的资产');
      await expect(section).toContainText('储值余额');

      const asset = section.locator('.customer-asset-account__asset').first();
      await expect(asset).toBeVisible();
      await expect(asset.locator('.customer-asset-account__code')).toHaveText('credit');

      const balances = asset.locator('.customer-asset-account__balances');
      const balanceRows = balances.locator('.customer-asset-account__balance');
      await expect(balanceRows.nth(0)).toContainText('可用余额');
      await expect(balanceRows.nth(0).locator('.customer-asset-account__amount')).toHaveText('1,200');
      await expect(balanceRows.nth(1)).toContainText('已预占');
      await expect(balanceRows.nth(1).locator('.customer-asset-account__amount')).toHaveText('300');
      await expect(balanceRows.nth(2)).toContainText('当前可使用');
      await expect(balanceRows.nth(2).locator('.customer-asset-account__amount')).toHaveText('900');

      const ledger = asset.locator('.customer-asset-account__ledger');
      await expect(ledger).toContainText('最近变动');
      await expect(ledger.locator('li')).toHaveCount(2);
      await expect(ledger).toContainText('增加');
      await expect(ledger).toContainText('预占');

      expect(page.url()).toMatch(/\/customer\/account/);
      expect(page.url()).not.toMatch(/\/account\/assets(?:\/|$)/);
      expect(pageErrors, `pageerror: ${pageErrors.join(' | ')}`).toHaveLength(0);
      expect(
        consoleErrors,
        `console error: ${consoleErrors.join(' | ')}`,
      ).toHaveLength(0);
    },
  );
});
