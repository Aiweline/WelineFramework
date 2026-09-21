/**
 * 店面 SSR 匿名 + 交互才鉴权：静默首屏零账户/membership 请求；顶栏可本地画壳。
 *
 * @weline-e2e-spec { module: Weline_Customer, type: feature, layer: frontend }
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Customer';
const IDENTITY_POST = /account\.(current|menuSignals)|membership\.status/i;

function trackIdentityHits(page, bag) {
  page.on('request', (req) => {
    try {
      const post = req.postData() || '';
      if (IDENTITY_POST.test(post)) {
        bag.push(`post:${post.slice(0, 160)}`);
      }
    } catch (_e) { /* ignore */ }
    const url = req.url();
    if (/account\.current|membership\.status/i.test(url)) {
      bag.push(url);
    }
  });
}

moduleDescribe(test, MODULE, 'SSR匿名与交互才鉴权', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'CUST-ANON-SSR-SILENT-PAINT' },
    '通路：公共首页静默首屏无 account.current / membership.status',
    async ({ page }) => {
      const identityHits = [];
      trackIdentityHits(page, identityHits);

      await gotoFrontend(page, '/');
      await page.waitForTimeout(2500);

      const account = page.locator('[data-w-header-account="1"]').first();
      if (await account.count()) {
        await expect(account).toHaveAttribute('data-header-account-ssr', /guest/);
      }

      expect(
        identityHits,
        `静默首屏不得打账户/membership：${identityHits.join(' | ')}`
      ).toHaveLength(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CUST-ANON-SSR-ENSURE-LOGIN-API' },
    '通路：Account 暴露 ensureLogin 且无页载 keepalive bootstrap',
    async ({ page }) => {
      await gotoFrontend(page, '/');
      const probe = await page.evaluate(async () => {
        const out = { hasEnsure: false, keepaliveBoot: false };
        try {
          if (window.Weline && typeof window.Weline.load === 'function') {
            await window.Weline.load('account');
          }
        } catch (_e) { /* ignore */ }
        const mod = window.WelineAccountModule || (window.Weline && window.Weline.Account);
        out.hasEnsure = !!(mod && typeof mod.ensureLogin === 'function');
        out.keepaliveBoot = /bootstrapOnlineKeepalive/.test(
          Array.from(document.scripts).map((s) => s.src || s.textContent || '').join('\n')
        );
        return out;
      });
      expect(probe.hasEnsure, 'Weline.Account.ensureLogin 必须存在').toBe(true);
      expect(probe.keepaliveBoot, '不得再 bootstrapOnlineKeepalive').toBe(false);
    }
  );
});
