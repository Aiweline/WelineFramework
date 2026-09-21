/**
 * Extra / FPC / Changed 店面通路：真实浏览器 HIT-MISS + 硬切契约。
 *
 * @weline-e2e-spec { module: Weline_Framework, type: e2e, layer: frontend }
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

const MODULE = 'Weline_Framework';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const FATAL = /Fatal error|ParseError|WLS Runtime Error|Uncaught|Class .* not found|500 Internal Server Error/i;
const PDP = '/product/hua-chao-ji-2023xin-kuan-nu-han-fu-tao-xi-zhong-gong-xiu-hua-da-xiu-sh-6dbce2f7';

function headerGet(headers, name) {
  const key = Object.keys(headers || {}).find((k) => k.toLowerCase() === name.toLowerCase());
  return key ? String(headers[key] || '') : '';
}

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite controller-extra-fpc storefront', () => {
  test.setTimeout(240000);
  test.describe.configure({ retries: 0 });

  moduleCase(test, { module: MODULE, id: 'A-hardcut-source' }, '硬切：缓存向 Observer 已删且 Seo 仍在', async () => {
    const gone = [
      'app/code/Weline/Framework/Event/ResourceChange/Observer/CacheNamespaceObserver.php',
      'app/code/Weline/Framework/Event/ResourceChange/Observer/CacheImpactObserver.php',
      'app/code/Weline/Cdn/Observer/ResourceChanged.php',
      'app/code/Weline/Theme/Observer/ResourceChanged.php',
    ];
    for (const rel of gone) {
      expect(fs.existsSync(path.join(ROOT, rel)), rel).toBe(false);
    }
    const seo = fs.readFileSync(path.join(ROOT, 'app/code/Weline/Seo/Observer/ResourceChanged.php'), 'utf8');
    expect(seo).toContain('class ResourceChanged');
    const pipeline = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Framework/Event/Changed/ChangedPipeline.php'),
      'utf8',
    );
    expect(pipeline).toContain('PHASE_AFTER_COMMIT');
    expect(pipeline).toContain('CODE_PURGE_FPC_URLS');
    const productCoord = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Product/Service/ProductSearchProjectionMutationCoordinator.php'),
      'utf8',
    );
    expect(productCoord).toContain('\\w_changed($change)');
    expect(productCoord).not.toContain('ProductStorefrontCacheInvalidator');
  });

  moduleCase(test, { module: MODULE, id: 'B-homepage-fpc' }, '首页真实浏览器：无 Fatal 且 FPC 头可读', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const statuses = [];
    for (let i = 0; i < 2; i += 1) {
      let resp = null;
      for (let attempt = 0; attempt < 4; attempt += 1) {
        resp = await page.goto(`${BASE}/?extra_fpc_e2e=${Date.now()}_${i}_${attempt}`, {
          waitUntil: 'domcontentloaded',
          timeout: 120000,
        });
        if (resp && resp.status() < 500) {
          break;
        }
        await page.waitForTimeout(2500);
      }
      expect(resp, 'homepage response').toBeTruthy();
      expect(resp.status(), `homepage status round=${i}`).toBeLessThan(500);
      const headers = resp.headers();
      statuses.push({
        status: resp.status(),
        fpc: headerGet(headers, 'x-weline-fpc') || headerGet(headers, 'x-wls-fpc-status'),
        total: headerGet(headers, 'x-wls-performance-total'),
      });
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('header, .weline-header, [weline-code*="header"], body').first()).toBeAttached({
        timeout: 30000,
      });
    }
    expect(statuses[0].fpc || statuses[1].fpc, JSON.stringify(statuses)).not.toEqual('');
  });

  moduleCase(test, { module: MODULE, id: 'C-pdp-or-home-fallback' }, 'PDP（@Extra fpc）或首页回退：200 级且无 Fatal', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    let resp = await page.goto(`${BASE}${PDP}?extra_fpc_e2e=${Date.now()}`, {
      waitUntil: 'domcontentloaded',
      timeout: 120000,
    }).catch(() => null);
    if (!resp || resp.status() >= 500 || resp.status() === 504) {
      resp = await page.goto(`${BASE}/?extra_fpc_e2e_fallback=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 120000,
      });
    }
    expect(resp).toBeTruthy();
    expect(resp.status()).toBeLessThan(500);
    const fpc = headerGet(resp.headers(), 'x-weline-fpc') || headerGet(resp.headers(), 'x-wls-fpc-status');
    expect(fpc, 'fpc header present').not.toEqual('');
    await expect(page.locator('body')).not.toContainText(FATAL);
  });

  moduleCase(test, { module: MODULE, id: 'D-changed-pipeline-ut' }, 'Changed/Extra 契约 UT 必须 passed', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/bin/phpunit'),
        path.join(ROOT, 'app/code/Weline/Framework/Test/Unit/Event/Changed/ChangedPipelineContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Framework/Test/Unit/Controller/Extra/ExtraTypeRegistryContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Framework/Test/Unit/Controller/Extra/TierAStorefrontExtraFpcContractTest.php'),
        path.join(ROOT, 'app/code/Weline/Cdn/Test/Unit/Changed/CdnCapabilityZeroSiteTest.php'),
        path.join(ROOT, 'app/code/Weline/Product/Test/Unit/Service/ProductPublicUrlChangeTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8', timeout: 120000 },
    );
    expect(out).toMatch(/OK \(/);
    expect(out).not.toMatch(/\nFAILURES!|\nERRORS!/);
  });

  moduleCase(test, { module: MODULE, id: 'E-tier-a-content-pages' }, 'A 档内容页：政策/FAQ/指南无 Fatal', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    const paths = [
      '/policy/privacy',
      '/about',
      '/currency',
      '/faq',
      '/guide/shipping',
      '/guide/payment/stripe/policy',
      '/blog',
    ];
    for (const p of paths) {
      let resp = null;
      for (let attempt = 0; attempt < 3; attempt += 1) {
        resp = await page.goto(`${BASE}${p}?extra_fpc_tier_a=${Date.now()}_${attempt}`, {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
        }).catch(() => null);
        if (resp && resp.status() < 500) {
          break;
        }
        await page.waitForTimeout(1500);
      }
      expect(resp, p).toBeTruthy();
      expect(resp.status(), p).toBeLessThan(500);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  });

  moduleCase(test, { module: MODULE, id: 'F-invalidation-closed-loop' }, '改资料写路径 bump 与 Extra 读 ns 闭环', async () => {
    const probe = path.join(
      ROOT,
      'app/code/Weline/Framework/Test/Unit/Controller/Extra/fpc_invalidation_closed_loop_probe.php',
    );
    expect(fs.existsSync(probe)).toBe(true);
    const out = execFileSync('php', [probe], {
      cwd: ROOT,
      encoding: 'utf8',
      timeout: 120000,
    });
    expect(out).toMatch(/LOOP_OK/);
    expect(out).toMatch(/BUMP_OK faq\.item/);
    expect(out).toMatch(/BUMP_OK product_search_projection/);
    expect(out).toMatch(/BUMP_OK theme_layout/);
    expect(out).toMatch(/BUMP_OK blog\.post/);
    expect(out).toMatch(/BUMP_OK cms_page/);
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：Extra/FPC/Changed', async () => {
    expect(fs.existsSync(path.join(ROOT, 'app/code/Weline/Framework/Event/Changed/ChangedPipeline.php'))).toBe(true);
    expect(fs.existsSync(path.join(
      ROOT,
      'app/code/Weline/Framework/Extends/module/Weline_Framework/Changed/Capability/FpcCapability.php',
    ))).toBe(true);
    expect(fs.existsSync(path.join(
      ROOT,
      'app/code/Weline/Framework/Extends/module/Weline_Framework/Extra/Type/FpcExtraType.php',
    ))).toBe(true);
    const sidecar = path.join(ROOT, 'generated/framework/controller_extra.php');
    expect(fs.existsSync(sidecar)).toBe(true);
    const sidecarSrc = fs.readFileSync(sidecar, 'utf8');
    expect(sidecarSrc).toContain('/policy/*');
    expect(sidecarSrc).toContain('/about');
    expect(sidecarSrc).toContain('/currency');
    expect(sidecarSrc).toContain('global/storefront/price');
    expect(sidecarSrc).toContain('/faq');
    expect(sidecarSrc).toContain('/blog');
    expect(sidecarSrc).toContain('/guide/shipping');
    expect(sidecarSrc).toContain('/guide/payment');
    expect(fs.existsSync(path.join(
      ROOT,
      'app/code/Weline/Faq/extends/module/Weline_Framework/Changed/Type/FaqItemChangedType.php',
    ))).toBe(true);
  });
});
