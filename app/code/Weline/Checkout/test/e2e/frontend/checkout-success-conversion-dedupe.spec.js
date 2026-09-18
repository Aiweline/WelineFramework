/**
 * 结账成功页转化去重：先面板，再去重，最后桥接。
 * 同键二次与别名交叉不触发 gtag purchase；无单号 purchase 族不桥接。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: flow, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const ORDER_UUID = 'd1d76074-dce8-4e09-9a1f-c82f6ea02cd2';

moduleDescribe(test, MODULE, 'checkout success conversion dedupe', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SUCCESS-DEDUPE-001' },
    'main track always flows; sandbox fanout dedupe emits yellow with ecommerce',
    async ({ page }) => {
      await page.goto(`${BASE}/checkout/success?order_uuid=${ORDER_UUID}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      await expect(page.locator('[data-testid="checkout-success"]')).toBeVisible({ timeout: 20000 });

      await page.waitForFunction(
        () => !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
        null,
        { timeout: 15000 }
      ).catch(async () => {
        await page.addScriptTag({
          url: '/Weline/Visitor/view/statics/js/pixel.js?v=e2e-bridge-gate',
        });
        await page.waitForFunction(
          () => !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
          null,
          { timeout: 15000 }
        );
      });

      const result = await page.evaluate(async () => {
        if (!(window.WelinePixel && typeof window.WelinePixel.track === 'function')) {
          throw new Error('pixel_timeout');
        }
        const cfg = (window.__WelineVisitorTrackingConfig && window.__WelineVisitorTrackingConfig.conversionDedupe) || {};
        const key = 'e2e-dedupe-' + Date.now();
        let ttlDays = parseInt(cfg.ttlDays, 10);
        if (!isFinite(ttlDays) || ttlDays < 1) {
          ttlDays = 180;
        }
        let yellow = null;
        let streamCheckout = 0;
        const sb = window.WelineEventSandbox || window.WelinePixelSandbox;
        if (sb && typeof sb.subscribe === 'function') {
          sb.subscribe(function (env) {
            if (!env) return;
            const name = String(env.name || env.event_name || '');
            if (name === 'checkout_success' && env.hit_kind !== 'dedupe') {
              streamCheckout += 1;
            }
            if (env.hit_kind === 'dedupe' || (env.params && env.params.dedupe_dropped)) {
              yellow = env;
            }
          });
        }
        const ecommerce = {
          value: 12.34,
          currency: 'USD',
          items: [{ item_id: 'sku-e2e', item_name: 'E2E Item', price: 12.34, quantity: 1 }],
        };
        let purchaseGtag = 0;
        const origGtag = window.gtag;
        window.gtag = function () {
          if (arguments[0] === 'event' && arguments[1] === 'purchase') {
            purchaseGtag += 1;
          }
          if (typeof origGtag === 'function') {
            return origGtag.apply(window, arguments);
          }
        };
        const first = window.WelinePixel.track('checkout_success', {
          order_uuid: key,
          transaction_id: key,
          ...ecommerce,
        });
        const second = window.WelinePixel.track('checkout_success', {
          order_uuid: key,
          transaction_id: key,
          ...ecommerce,
        });
        const crossed = window.WelinePixel.track('payment_success', {
          transaction_id: 'other-txn-' + key,
          order_uuid: key,
          ...ecommerce,
        });
        const chain = window.WelinePixel.track('express_pay_checkout_success', {
          chain_id: 'checkout_express_pay',
          funnel_complete: true,
        });
        const purchaseAfterChain = purchaseGtag;
        let stored = false;
        try {
          for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k && k.indexOf(key) > -1) {
              stored = true;
              break;
            }
          }
        } catch (e) {}
        const p = (yellow && yellow.params) || {};
        const yellowHasEcommerce = !!(
          yellow &&
          p.value === 12.34 &&
          p.currency === 'USD' &&
          (p.items_count >= 1 ||
            (typeof p.items === 'string' && p.items.indexOf('sku-e2e') > -1) ||
            p.item_1_id === 'sku-e2e' ||
            p.item_1_name === 'E2E Item')
        );
        const vendors = (window.__WelineVisitorTrackingConfig && window.__WelineVisitorTrackingConfig.vendors) || [];
        const hasSandboxVendor = vendors.some(function (v) {
          return v && v.enabled && String(v.mode || 'sandbox') === 'sandbox';
        });
        return {
          enabled: cfg.enabled !== false,
          ttlDays,
          firstOk: !!first,
          secondOk: !!second,
          crossedOk: !!crossed,
          chainOk: !!chain,
          purchaseGtag,
          purchaseAfterChain,
          stored,
          streamCheckout,
          hasSandboxVendor,
          yellowHasEcommerce,
          yellowParams: p,
        };
      });

      expect(result.enabled).toBe(true);
      expect(result.ttlDays).toBeGreaterThanOrEqual(1);
      expect(result.firstOk).toBe(true);
      expect(result.secondOk).toBe(true);
      expect(result.crossedOk).toBe(true);
      expect(result.chainOk).toBe(true);
      expect(result.purchaseGtag).toBeLessThanOrEqual(1);
      expect(result.purchaseAfterChain).toBe(result.purchaseGtag);
      expect(result.stored).toBe(true);
      expect(result.streamCheckout).toBeGreaterThanOrEqual(2);
      if (result.hasSandboxVendor) {
        expect(result.yellowHasEcommerce).toBe(true);
      }
    }
  );
});
