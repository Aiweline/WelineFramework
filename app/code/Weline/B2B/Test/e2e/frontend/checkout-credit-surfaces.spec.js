/**
 * 迷你车 / 购物车页：批发信用仅 tob 展示（站点开启时注入）。
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

async function waitCreditApi(page) {
  await page.waitForFunction(
    () => !!(window.WelineB2BSellingMode || (window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.applyCartType === 'function')),
    undefined,
    { timeout: 30000 },
  );
  await page.waitForFunction(
    () => {
      if (window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.applyCartTypeAll === 'function') {
        return true;
      }
      // Fallback: selling mode loaded first; wait for dependent checkout-tob.
      return !!(window.Weline && typeof window.Weline.load === 'function');
    },
    undefined,
    { timeout: 15000 },
  ).catch(() => {});
  await page.evaluate(async () => {
    if (window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.applyCartTypeAll === 'function') {
      return;
    }
    if (window.Weline && typeof window.Weline.load === 'function') {
      try {
        await window.Weline.load('b2bCheckoutTob');
      } catch (e) {}
    }
  });
  await page.waitForFunction(
    () => !!(window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.applyCartTypeAll === 'function'),
    undefined,
    { timeout: 20000 },
  );
}

function surfaceState(page, rootSelector) {
  return page.evaluate((sel) => {
    const root = document.querySelector(sel);
    if (!root) {
      return { missingRoot: true };
    }
    const credits = Array.from(root.querySelectorAll('[data-b2b-checkout-credit]'));
    const credit = credits[0] || null;
    const tabs = Array.from(root.querySelectorAll('[data-mini-cart-extras-tab]'));
    const creditTab = tabs.find((t) => /批发信用/.test(String(t.textContent || '')));
    const creditHidden = credits.length === 0
      || credits.every((c) => !!(c.hidden || c.hasAttribute('hidden')));
    return {
      missingRoot: false,
      hasCreditNode: credits.length > 0,
      creditHidden,
      creditTabHidden: !creditTab || creditTab.hidden || creditTab.getAttribute('aria-hidden') === 'true',
      visibleTabs: tabs.filter((t) => !t.hidden).map((t) => String(t.textContent || '').trim()),
    };
  }, rootSelector);
}

moduleDescribe(test, MODULE, '迷你车与购物车批发信用表面', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CREDIT-SURFACES' },
    '购物车页与迷你车：tob 显示批发信用，toc 隐藏',
    async ({ page }) => {
      await gotoFrontend(page, '/cart', DIRECT);
      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);
      await waitCreditApi(page);

      await page.evaluate(() => {
        sessionStorage.setItem('weline_cart_type_explicit', 'toc');
        sessionStorage.setItem('weline_selling_mode', 'toc');
        window.WelineB2BCheckoutTob.applyCartTypeAll('toc');
      });

      const cartToc = await surfaceState(page, '[data-weline-cart], .weline-cart-shell');
      if (!cartToc.missingRoot && cartToc.hasCreditNode) {
        expect(cartToc.creditHidden).toBe(true);
        expect(cartToc.visibleTabs.some((l) => /批发信用/.test(l))).toBe(false);
      }

      await page.evaluate(() => {
        sessionStorage.setItem('weline_cart_type_explicit', 'tob');
        sessionStorage.setItem('weline_selling_mode', 'tob');
        window.WelineB2BCheckoutTob.applyCartTypeAll('tob', { ensureQuote: false });
      });

      const cartTob = await surfaceState(page, '[data-weline-cart], .weline-cart-shell');
      expect(cartTob.missingRoot).toBe(false);
      if (cartTob.hasCreditNode) {
        expect(cartTob.creditHidden).toBe(false);
        expect(
          cartTob.creditTabHidden === false
            || cartTob.visibleTabs.some((l) => /批发信用/.test(l)),
        ).toBe(true);
      }

      // 有商品小计时，本期定金 ≈ 小计×30%；文案不得再报「无定金」。
      const payable = await page.evaluate(() => {
        const api = window.WelineB2BCheckoutTob;
        const goodsNode = document.querySelector('[data-weline-cart] [data-cart-goods-subtotal], [data-cart-goods-subtotal]');
        const text = goodsNode ? String(goodsNode.textContent || '') : '';
        const goodsMajor = api && typeof api.readGoodsSubtotalMajor === 'function'
          ? api.readGoodsSubtotalMajor({})
          : null;
        const estimated = api && typeof api.estimateDepositMinor === 'function'
          ? api.estimateDepositMinor({})
          : null;
        const na = document.querySelector('[data-b2b-checkout-credit]')?.getAttribute('data-i18n-reason-na') || '';
        return { text, goodsMajor, estimated, na, hasGoodsNode: !!goodsNode };
      });
      expect(payable.na).not.toMatch(/无定金/);
      expect(payable.na).toMatch(/本期定金/);
      if (payable.hasGoodsNode && /[\d]/.test(payable.text) && Number(payable.goodsMajor) > 0) {
        const expectMinor = Math.floor(Math.round(Number(payable.goodsMajor) * 100) * 3000 / 10000);
        expect(Number(payable.estimated) || 0).toBe(expectMinor);
        expect(Number(payable.estimated) || 0).toBeGreaterThan(0);
      }

      // 迷你车：打开抽屉后同步。
      const openBtn = page.locator('[data-w-mini-cart="1"] [data-mini-cart-toggle], [data-w-mini-cart="1"] .cart-link').first();
      if (await openBtn.count()) {
        await openBtn.click({ force: true }).catch(() => {});
      }
      await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('weshop:mini-cart:open'));
        window.WelineB2BCheckoutTob.applyCartTypeAll('tob', { ensureQuote: false });
      });

      const miniTob = await surfaceState(page, '[data-w-mini-cart="1"]');
      if (!miniTob.missingRoot && miniTob.hasCreditNode) {
        expect(miniTob.creditHidden).toBe(false);
        expect(
          miniTob.creditTabHidden === false
            || miniTob.visibleTabs.some((l) => /批发信用/.test(l)),
        ).toBe(true);
      }

      await page.evaluate(() => {
        sessionStorage.setItem('weline_cart_type_explicit', 'toc');
        sessionStorage.setItem('weline_selling_mode', 'toc');
        window.WelineB2BCheckoutTob.applyCartTypeAll('toc');
      });
      await page.waitForFunction(() => {
        const roots = Array.from(document.querySelectorAll('[data-w-mini-cart="1"]'));
        if (!roots.length) {
          return true;
        }
        return roots.every((root) => {
          const credits = Array.from(root.querySelectorAll('[data-b2b-checkout-credit]'));
          return credits.length === 0
            || credits.every((c) => !!(c.hidden || c.hasAttribute('hidden')));
        });
      }, undefined, { timeout: 5000 }).catch(() => {});
      const miniToc = await surfaceState(page, '[data-w-mini-cart="1"]');
      if (!miniToc.missingRoot && miniToc.hasCreditNode) {
        expect(miniToc.creditHidden).toBe(true);
      }
    },
  );
});
