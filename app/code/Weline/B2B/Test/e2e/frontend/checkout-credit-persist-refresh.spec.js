/**
 * 批发信用抵扣写入 PHP 会话后，刷新仍应带回 saved_apply（对齐优惠券会话）。
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const DIRECT = { useProxy: false };

moduleDescribe(test, MODULE, '批发信用抵扣刷新持久化', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CREDIT-PERSIST-REFRESH' },
    'credit.apply 写入会话后 credit.quote 带回 saved_apply；刷新后仍在',
    async ({ page }) => {
      await gotoFrontend(page, '/cart', DIRECT);
      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);

      await page.waitForFunction(
        () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
        undefined,
        { timeout: 45000 },
      );

      const applyRes = await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('b2b');
        if (!api || typeof api['credit.apply'] !== 'function') {
          return { ok: false, error: 'credit.apply_missing' };
        }
        const res = await api['credit.apply'](
          {
            enabled: true,
            apply_minor: 2000,
            currency: 'USD',
            cart_type: 'tob',
          },
          { silent: true },
        );
        return {
          ok: !!(res && (res.ok === true || res.success === true)),
          saved: res && res.saved_apply ? res.saved_apply : null,
          error: res && (res.error || res.message) ? String(res.error || res.message) : '',
        };
      });
      expect(applyRes.ok, JSON.stringify(applyRes)).toBe(true);
      expect(applyRes.saved && applyRes.saved.enabled).toBe(true);
      expect(Number(applyRes.saved.apply_minor)).toBe(2000);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);
      await page.waitForFunction(
        () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
        undefined,
        { timeout: 45000 },
      );

      const quoteRes = await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('b2b');
        if (!api || typeof api['credit.quote'] !== 'function') {
          return { ok: false, error: 'credit.quote_missing' };
        }
        const res = await api['credit.quote'](
          {
            deposit_amount_minor: 44700,
            currency: 'USD',
            website_id: 0,
            cart_type: 'tob',
          },
          { silent: true },
        );
        return {
          ok: !!(res && (res.ok === true || res.success === true)),
          saved: res && res.saved_apply ? res.saved_apply : null,
          hasCredit: !!(res && res.b2b_credit),
        };
      });
      expect(quoteRes.ok, JSON.stringify(quoteRes)).toBe(true);
      expect(quoteRes.saved && quoteRes.saved.enabled).toBe(true);
      expect(Number(quoteRes.saved.apply_minor)).toBe(2000);

      // Cleanup so later suites are not polluted.
      await page.evaluate(async () => {
        const api = await window.Weline.Api.resource('b2b');
        if (api && typeof api['credit.apply'] === 'function') {
          await api['credit.apply'](
            { enabled: false, apply_minor: 0, currency: 'USD', cart_type: 'tob' },
            { silent: true },
          );
        }
      });
    },
  );
});
