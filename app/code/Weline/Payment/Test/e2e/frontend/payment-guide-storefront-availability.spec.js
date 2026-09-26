/**
 * 支付方式指南（店面）「不启用即隐藏」真实浏览器验收。
 *
 * 需求：
 *  1. 未启用的支付方式不得出现在指南 hub / 详情页侧边导航；
 *  2. 当前浏览的详情页即使所属方式已下线，仍须可解析（HTTP 200，非 404）——
 *     供应商登记的协议/政策链接是法律链接，必须保持可达；
 *  3. 导航里出现的每个方法链接都必须可打开（不得是死链）。
 *
 * 可用性唯一门闩在 Provider 层（PaymentMethodManager::getStorefrontAvailableMethodCodes），
 * 本用例只做「店面表现」验收，不重复实现可用性规则。
 *
 * 本机夹具：fake_card / paypal 已启用，stripe 未启用（故以 stripe 作隐藏探针）。
 * 换环境用 WELINE_E2E_GUIDE_DISABLED_METHOD 覆盖。
 *
 * @weline-e2e-spec { module: Weline_Payment, type: smoke, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const fs = require('fs');
const path = require('path');
const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Payment';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const HUB = '/guide/payment';
const DISABLED = (process.env.WELINE_E2E_GUIDE_DISABLED_METHOD || 'stripe').trim();

// 证据目录可用 WELINE_E2E_EVIDENCE_DIR 覆盖（例如对生产跑时写 ch1-prod，避免覆盖本机证据）。
const EVIDENCE_DIR = process.env.WELINE_E2E_EVIDENCE_DIR
  ? path.resolve(process.env.WELINE_E2E_EVIDENCE_DIR)
  : path.join(ROOT, 'app/code/Weline/Payment/doc/evidence/ch1');

/** 从页面里取侧边导航（aside）内的支付方式链接，排除 hreflang/语言备用链接干扰。 */
async function readSidebarMethodCodes(page) {
  return page.evaluate(() => {
    const aside = document.querySelector('aside.amazon-payment-guide-layout__sidebar');
    if (!aside) {
      return null;
    }
    const codes = new Set();
    aside.querySelectorAll('a[href]').forEach((a) => {
      const m = String(a.getAttribute('href') || '').match(/\/guide\/payment\/([a-z0-9_]+)(?:[/?#]|$)/);
      if (m) {
        codes.add(m[1]);
      }
    });
    return Array.from(codes).sort();
  });
}

moduleDescribe(test, MODULE, 'payment guide storefront availability gate', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-GUIDE-HIDE-DISABLED-001' },
    'disabled payment method is hidden from the guide hub sidebar',
    async ({ page }) => {
      // 先钉住门闩归属：可用性必须由 Provider 层判定，指南层不得自建规则。
      const manager = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Payment/Service/PaymentMethodManager.php'),
        'utf8'
      );
      expect(manager).toContain('function getStorefrontAvailableMethodCodes');

      await page.goto(`${BASE}${HUB}`, { waitUntil: 'domcontentloaded', timeout: 60000 });

      const aside = page.locator('aside.amazon-payment-guide-layout__sidebar');
      await expect(aside).toBeVisible({ timeout: 30000 });

      const codes = await readSidebarMethodCodes(page);
      expect(Array.isArray(codes)).toBe(true);
      expect(codes.length).toBeGreaterThan(0);

      // 核心断言：未启用即隐藏
      expect(codes).not.toContain(DISABLED);

      fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(EVIDENCE_DIR, 'guide-hub-sidebar.png'), fullPage: false });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-GUIDE-DISABLED-DETAIL-RESOLVABLE-002' },
    'disabled method detail page stays resolvable and keeps itself in the sidebar',
    async ({ page }) => {
      const response = await page.goto(`${BASE}${HUB}/${DISABLED}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });

      // 不 404：法律链接必须可达
      expect(response, 'detail page should return a response').toBeTruthy();
      expect(response.status()).toBe(200);

      // 「非 404」按页面自身标识判定：整页文本正则不可靠——字体/资源哈希里会偶然出现 404。
      await expect(page.locator('aside.amazon-payment-guide-layout__sidebar')).toBeVisible({ timeout: 30000 });
      await expect(page).toHaveTitle(/guide/i);

      const codes = await readSidebarMethodCodes(page);
      expect(Array.isArray(codes)).toBe(true);
      // 当前浏览项即使已下线也要保留在导航里（否则用户失去上下文）
      expect(codes).toContain(DISABLED);

      fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
      await page.screenshot({ path: path.join(EVIDENCE_DIR, 'guide-disabled-detail.png'), fullPage: false });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-GUIDE-NO-DEAD-LINKS-003' },
    'every method link rendered in the guide sidebar opens successfully',
    async ({ page, request }) => {
      await page.goto(`${BASE}${HUB}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await expect(page.locator('aside.amazon-payment-guide-layout__sidebar')).toBeVisible({ timeout: 30000 });

      const codes = await readSidebarMethodCodes(page);
      expect(codes.length).toBeGreaterThan(0);

      for (const code of codes) {
        const resp = await request.get(`${BASE}${HUB}/${code}`, { ignoreHTTPSErrors: true });
        expect(resp.status(), `guide detail for "${code}" should be openable`).toBe(200);
      }
    }
  );
});
