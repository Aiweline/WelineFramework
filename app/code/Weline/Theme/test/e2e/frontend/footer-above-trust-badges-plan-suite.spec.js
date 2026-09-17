/**
 * 全站 footer-above 默认 trust-badges + 博客底部空槽通路。
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
const BLOG_ARTICLE = process.env.WELINE_E2E_BLOG_PATH || '/blog/ethnic-achang-dress-overview';

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite footer-above trust-badges', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-layout' }, '完整通路：首页与博客 footer-above 默认 trust-badges', async ({ page }) => {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-wslot="footer-above"]').first()).toBeAttached({ timeout: 20000 });
    await expect(page.locator('[data-wslot="footer-above"] [data-widget-code="trust-badges"]').first()).toBeAttached();
    await expect(page.locator('[data-wslot="homepage-bottom"]').first()).toBeAttached();
    await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);

    await page.goto(`${BASE}${BLOG_ARTICLE}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(page.locator('[data-wslot="blog-bottom"]').first()).toBeAttached({ timeout: 20000 });
    await expect(page.locator('[data-wslot="footer-above"] [data-widget-code="trust-badges"]').first()).toBeAttached();
    await expect(page.getByText('安全支付').first()).toBeVisible({ timeout: 15000 });
  });

  moduleCase(test, { module: MODULE, id: 'B-source-contract' }, '源码契约：footer partial 与博客底槽', async () => {
    const footer = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml'),
      'utf8'
    );
    const blog = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Blog/view/theme/frontend/layouts/blog/default.phtml'),
      'utf8'
    );
    expect(footer).toContain('id="footer-above"');
    expect(footer).toContain('name="trust-badges"');
    expect(footer.indexOf('id="footer-above"')).toBeLessThan(footer.indexOf('id="footer"'));
    expect(blog).toContain('id="blog-bottom"');
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：FooterAbove 契约', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/phpunit/phpunit/phpunit'),
        '--configuration',
        path.join(ROOT, 'tests/phpunit/config.xml'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/FooterAboveTrustBadgesContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(out).toMatch(/OK/);
  });
});
