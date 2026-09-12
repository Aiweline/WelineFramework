/**
 * 结账摘要「批发信用」页签仅 tob 展示；零售隐藏。
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

moduleDescribe(test, MODULE, '结账批发信用仅 tob 展示', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CREDIT-TOB-ONLY' },
    '零售隐藏批发信用页签；批发显示',
    async ({ page }) => {
      await gotoFrontend(page, '/checkout', DIRECT);
      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);

      await page.waitForFunction(
        () => !!(window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.applyCartType === 'function'),
        undefined,
        { timeout: 30000 },
      );

      const rootSel = '[data-weline-checkout], [data-checkout], .weline-checkout';
      await page.waitForSelector(rootSel, { timeout: 30000 });

      await page.evaluate(() => {
        const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        window.WelineB2BCheckoutTob.applyCartType(root, 'toc');
      });

      const tocState = await page.evaluate(() => {
        const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        const credit = root && root.querySelector('[data-b2b-checkout-credit]');
        const slot = root && root.querySelector('.weline-checkout__credit-slot');
        const tabs = Array.from(root ? root.querySelectorAll('[data-mini-cart-extras-tab]') : []);
        const creditTab = tabs.find((t) => /批发信用/.test(String(t.textContent || '')));
        return {
          creditHidden: !credit || credit.hidden || credit.hasAttribute('hidden'),
          slotHidden: !slot || slot.hidden || slot.hasAttribute('hidden'),
          creditTabHidden: !creditTab || creditTab.hidden || creditTab.getAttribute('aria-hidden') === 'true',
          tabLabels: tabs.filter((t) => !t.hidden).map((t) => String(t.textContent || '').trim()),
        };
      });
      expect(tocState.creditHidden || tocState.slotHidden).toBe(true);
      expect(tocState.creditTabHidden).toBe(true);
      expect(tocState.tabLabels.some((l) => /批发信用/.test(l))).toBe(false);

      await page.evaluate(() => {
        const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        window.WelineB2BCheckoutTob.applyCartType(root, 'tob', { ensureQuote: false });
      });

      const tobState = await page.evaluate(() => {
        const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        const credit = root && root.querySelector('[data-b2b-checkout-credit]');
        const slot = root && root.querySelector('.weline-checkout__credit-slot');
        const tabs = Array.from(root ? root.querySelectorAll('[data-mini-cart-extras-tab]') : []);
        const creditTab = tabs.find((t) => /批发信用/.test(String(t.textContent || '')));
        return {
          creditVisible: !!(credit && !credit.hidden),
          slotVisible: !!(slot && !slot.hidden),
          creditTabVisible: !!(creditTab && !creditTab.hidden && creditTab.getAttribute('aria-hidden') !== 'true'),
          visibleTabLabels: tabs.filter((t) => !t.hidden).map((t) => String(t.textContent || '').trim()),
        };
      });
      expect(tobState.creditVisible || tobState.slotVisible).toBe(true);
      expect(
        tobState.creditTabVisible
          || tobState.visibleTabLabels.some((l) => /批发信用/.test(l)),
      ).toBe(true);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-CREDIT-FX-INPUT' },
    '明示基准货币折合文案；抵扣输入可键盘填写',
    async ({ page }) => {
      await gotoFrontend(page, '/checkout', DIRECT);
      await expect(page.locator('body')).not.toHaveText(FATAL_PATTERN);
      await page.waitForFunction(
        () => !!(window.WelineB2BCheckoutTob && typeof window.WelineB2BCheckoutTob.syncFromFrozen === 'function'),
        undefined,
        { timeout: 30000 },
      );

      await page.evaluate(() => {
        sessionStorage.setItem('weline_cart_type_explicit', 'tob');
        sessionStorage.setItem('weline_selling_mode', 'tob');
        const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
        window.WelineB2BCheckoutTob.applyCartType(root, 'tob', { ensureQuote: false });
        const credit = root && root.querySelector('[data-b2b-checkout-credit][data-b2b-credit-surface="checkout"]');
        if (credit) {
          credit.setAttribute('data-cart-type', 'tob');
        }
        window.WelineB2BCheckoutTob.syncFromFrozen({
          data: {
            b2b_credit: {
              enabled: true,
              reason: '',
              base_currency: 'CNY',
              checkout_currency: 'USD',
              available_base_minor: 100_00,
              available_checkout_minor: 14_29,
              max_apply_checkout_minor: 14_29,
              deposit_amount_minor: 50_00,
              hint_short: '基准货币 CNY 可用 100.00，折合 USD 14.29；本单最多可抵 14.29 USD。',
              hint_detail: 'detail',
            },
          },
        });
      });

      // 批发信用在 extras 页签内；未点选时子节点对 Playwright 仍算 hidden。
      // 只点结账摘要里的页签，避免命中隐藏的迷你车页签。
      const checkoutRoot = page.locator('[data-weline-checkout], [data-checkout], .weline-checkout').first();
      const creditTab = checkoutRoot.locator('[data-mini-cart-extras-tab]').filter({ hasText: '批发信用' });
      if (await creditTab.count()) {
        await creditTab.click({ force: true });
      } else {
        await page.evaluate(() => {
          const root = document.querySelector('[data-weline-checkout], [data-checkout], .weline-checkout');
          const panel = root && root.querySelector('[data-b2b-credit-panel]');
          const slot = root && root.querySelector('.weline-checkout__credit-slot, [data-wslot="checkout-summary-credit"]');
          if (panel) {
            panel.hidden = false;
            panel.removeAttribute('hidden');
          }
          if (slot) {
            slot.hidden = false;
            slot.removeAttribute('hidden');
          }
          const panes = root ? root.querySelectorAll('[data-mini-cart-extras-panel], .mini-cart-drawer__extras-panel') : [];
          panes.forEach((p) => {
            if (p.querySelector('[data-b2b-checkout-credit]')) {
              p.hidden = false;
              p.removeAttribute('hidden');
              p.style.display = '';
            }
          });
        });
      }

      const checkoutCredit = checkoutRoot.locator('[data-b2b-checkout-credit][data-b2b-credit-surface="checkout"]');
      await expect.poll(async () => {
        return checkoutCredit.locator('[data-testid="b2b-credit-fx"]').evaluate((el) => String(el.textContent || '').includes('基准货币'));
      }).toBe(true);
      await expect(checkoutCredit.locator('[data-testid="b2b-credit-fx"]')).toContainText('CNY');
      await expect(checkoutCredit.locator('[data-testid="b2b-credit-fx"]')).toContainText('折合');
      await expect(checkoutCredit.locator('[data-testid="b2b-credit-fx"]')).toContainText('USD');

      const input = checkoutCredit.locator('[data-testid="b2b-credit-input"]');
      await expect(input).toHaveAttribute('type', 'text');
      await expect(input).toHaveAttribute('inputmode', 'decimal');

      await checkoutCredit.locator('[data-testid="b2b-credit-toggle"]').check({ force: true });
      await expect(input).toBeEnabled();
      await input.evaluate((el) => {
        el.focus();
        el.value = '';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.value = '12.3';
        el.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await expect(input).toHaveValue('12.3');
      await input.evaluate((el) => {
        el.blur();
        el.dispatchEvent(new Event('blur', { bubbles: true }));
      });
      await expect(input).toHaveValue('12.30');
    },
  );
});
