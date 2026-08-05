/**
 * 计划用例固化：P1D SEO / Consent / Maintenance（可 Browser 面）
 *
 * - TEST-P1D-03 robots/sitemap/noindex 门禁
 * - TEST-P1D-04 Consent 横幅同意（Website 隔离；单站冒烟）
 * - TEST-P1D-05 Maintenance 页可达（双店对比需多 Store 夹具，缺夹具时验单站维护页）
 *
 * @weline-e2e-spec { module: Weline_Seo, type: plan, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE_SEO = 'Weline_Seo';
const MODULE_CONSENT = 'Weline_Consent';
const MODULE_MAINT = 'Weline_Maintenance';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const STORE_FIXTURE = path.resolve(__dirname, 'plan-p1d-storefront-gates-fixture.php');
const CONSENT_FIXTURE = path.resolve(
  ROOT_DIR,
  'app/code/Weline/Consent/Test/e2e/frontend/plan-p1d05-consent-persistence-fixture.php',
);

function runFixture(script, action, payload = {}) {
  let stdout = '';
  try {
    stdout = execFileSync('php', [script], {
      cwd: ROOT_DIR,
      input: JSON.stringify({ action, ...payload }),
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 240000,
    });
  } catch (error) {
    const captured = [error && error.stdout, error && error.stderr, error && error.message]
      .filter(Boolean)
      .map(String)
      .join(' | ');
    throw new Error(`P1D fixture ${action} process failed: ${captured}`);
  }
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`P1D fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}


moduleDescribe(test, MODULE_SEO, '计划用例：P1D SEO/Consent/Maintenance', () => {
  test.setTimeout(300000);

  moduleCase(
    test,
    { module: MODULE_SEO, id: 'TEST-P1D-03' },
    'dev/test Store 强制 robots、空 sitemap、页面 noindex 与响应头',
    async ({ page }) => {
      const origin = getRuntimeInfo().runtime.target_origin;
      const token = `seo${process.pid}${Date.now()}`;
      const fixture = runFixture(STORE_FIXTURE, 'prepare', { token, origin });
      const prefix = `/${fixture.store_a.path}`;
      const preview = `maintenance_preview_token=${encodeURIComponent(fixture.preview_token)}`;
      try {
        const robots = await page.request.get(`${origin}${prefix}/robots.txt?${preview}`);
        expect(robots.status(), 'test Store robots.txt 应 200').toBe(200);
        const robotsText = await robots.text();
        expect(robotsText).toContain('User-agent: *');
        expect(robotsText).toContain('Disallow: /');
        expect(robotsText).not.toContain('Sitemap:');
        expect(robots.headers()['x-robots-tag']).toBe('noindex, nofollow');
        expect(robotsText).not.toMatch(FATAL_PATTERN);

        const sitemap = await page.request.get(`${origin}${prefix}/sitemap.xml?${preview}`);
        expect(sitemap.status(), 'test Store sitemap.xml 应 200').toBe(200);
        const sitemapBody = await sitemap.text();
        expect(sitemapBody).toContain('<urlset');
        expect(sitemapBody).not.toContain('<url>');
        expect(sitemap.headers()['x-robots-tag']).toBe('noindex, nofollow');
        expect(sitemapBody).not.toMatch(FATAL_PATTERN);

        const document = await page.goto(`${origin}${prefix}/?${preview}`, { waitUntil: 'domcontentloaded' });
        expect(document, 'test Store 页面响应').toBeTruthy();
        expect(document.headers()['x-robots-tag']).toBe('noindex, nofollow');
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex,nofollow');
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

        const normal = await page.request.get(`${origin}/${fixture.store_b.path}/`);
        expect(normal.status(), 'normal Store 页面应可达').toBe(200);
        expect(normal.headers()['x-robots-tag'] || '').not.toBe('noindex, nofollow');
      } finally {
        runFixture(STORE_FIXTURE, 'cleanup', { token: fixture.token });
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE_CONSENT, id: 'TEST-P1D-04' },
    'Consent Website A/B 隔离、同意/撤回持久化，Browser 同意后隐藏并写 cookie',
    async ({ page }) => {
      runFixture(CONSENT_FIXTURE, 'cleanup');
      try {
        const grant = runFixture(CONSENT_FIXTURE, 'grant');
        expect(grant.records_a).toBe(3);
        expect(grant.audit_a).toBe(3);
        const isolated = runFixture(CONSENT_FIXTURE, 'read');
        expect(Object.values(isolated.granted_a)).not.toContain(false);
        expect(Object.values(isolated.granted_b)).not.toContain(true);
        expect(isolated.banner_a).toBe(false);
        expect(isolated.banner_b).toBe(true);
        const recordingOff = runFixture(CONSENT_FIXTURE, 'recording_off');
        expect(recordingOff.new_grant_rejected).toBe(true);
        expect(recordingOff.withdrawal_allowed).toBe(true);
        expect(recordingOff.last_action).toBe('withdrawn');

        await gotoFrontend(page, `/?consent_p1d04=${Date.now()}`, { timeout: 60000, settleMs: 1000 });
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        const banner = page.locator('#weline-consent-banner');
        const accept = page.locator('[data-consent-accept]');
        await expect(banner, 'Consent 横幅必须真实渲染，禁止 skip').toHaveCount(1);
        await expect(accept).toHaveCount(1);
        await expect(banner).toBeVisible();
        await accept.click();
        await expect(banner).toBeHidden({ timeout: 10000 });
        const cookies = await page.context().cookies();
        expect(cookies.find((item) => item.name === 'weline_consent_vid'), 'weline_consent_vid').toBeTruthy();
      } finally {
        runFixture(CONSENT_FIXTURE, 'cleanup');
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE_MAINT, id: 'TEST-P1D-05' },
    'Store A maintenance、B normal；preview token 只读、Scope 隔离且过期失效',
    async ({ page }) => {
      const origin = getRuntimeInfo().runtime.target_origin;
      const token = `maint${process.pid}${Date.now()}`;
      const fixture = runFixture(STORE_FIXTURE, 'prepare', { token, origin });
      try {
        expect(fixture.maintenance.a_enabled).toBe(true);
        expect(fixture.maintenance.b_enabled).toBe(false);
        expect(fixture.maintenance.token_valid_a).toBe(true);
        expect(fixture.maintenance.token_valid_b).toBe(false);
        expect(fixture.maintenance.token_expired).toBe(true);
        expect(fixture.maintenance.readonly_write_blocked).toBe(true);

        const blocked = await page.request.get(`${origin}/${fixture.store_a.path}/`, { failOnStatusCode: false });
        expect(blocked.status(), 'Store A 无 preview 必须维护阻断').toBe(503);
        const normal = await page.request.get(`${origin}/${fixture.store_b.path}/`, { failOnStatusCode: false });
        expect(normal.status(), 'Store B 不受 Store A 维护影响').toBe(200);
        const preview = await page.request.get(
          `${origin}/${fixture.store_a.path}/?maintenance_preview_token=${encodeURIComponent(fixture.preview_token)}`,
          { failOnStatusCode: false },
        );
        expect(preview.status(), '有效只读 preview token 应允许读取').toBe(200);
        expect(await preview.text()).not.toMatch(FATAL_PATTERN);
      } finally {
        runFixture(STORE_FIXTURE, 'cleanup', { token: fixture.token });
      }
    }
  );
});
