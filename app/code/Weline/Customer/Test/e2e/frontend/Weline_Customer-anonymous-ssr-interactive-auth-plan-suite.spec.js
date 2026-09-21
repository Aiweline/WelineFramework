/**
 * Plan-suite: storefront anonymous SSR + interactive auth.
 *
 * @weline-e2e-spec { module: Weline_Customer, type: e2e-plan-suite, layer: frontend }
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

moduleDescribe(test, MODULE, 'SSR匿名交互鉴权计划组', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'CUST-ANON-SSR-PLAN-SUITE' },
    '计划组：静默无 account.current/membership + ensureLogin 可用',
    async ({ page }) => {
      const identityHits = [];
      page.on('request', (req) => {
        try {
          const post = req.postData() || '';
          if (IDENTITY_POST.test(post)) {
            identityHits.push(`post:${post.slice(0, 120)}`);
          }
        } catch (_e) { /* ignore */ }
      });

      await gotoFrontend(page, '/');
      await page.waitForTimeout(2000);
      expect(
        identityHits,
        `plan-suite 静默不得打账户/membership：${identityHits.join(' | ')}`
      ).toHaveLength(0);

      const hasEnsure = await page.evaluate(async () => {
        try {
          if (window.Weline && typeof window.Weline.load === 'function') {
            await window.Weline.load('account');
          }
        } catch (_e) { /* ignore */ }
        const mod = window.WelineAccountModule
          || (window.Weline && window.Weline.Account);
        return !!(mod && typeof mod.ensureLogin === 'function');
      });
      expect(hasEnsure).toBe(true);
    }
  );
});
