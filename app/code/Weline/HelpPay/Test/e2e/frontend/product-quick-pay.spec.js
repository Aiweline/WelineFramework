/**
 * PDP 快捷购买：缺货态禁用；可售时打开地址确认步（懒加载结账地址），不得空白弹层 / capability_denied。
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: smoke, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_HelpPay';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const PDP = '/product/yue-ya-ni-shang-yue-ya-ni-shang-yuan-chuang-zheng-pin-si-wu-xie-jin-zhi-ji-72642ec3?size=s&style_type=hf-s-qian-lu12-po6-mi-bai-d3733f9';

moduleDescribe(test, MODULE, 'product quick-pay CTA', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'HELPPAY-QUICKPAY-PDP-001' },
    'quick-pay opens address-pick (lazy checkout address) without blank dialog or worker capability_denied',
    async ({ page }) => {
      await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(1200);

      const body = await page.locator('body').innerText();
      expect(body).not.toMatch(FATAL_PATTERN);
      expect(body).not.toMatch(/系统升级维护中/);

      // PDP must not SSR the heavy checkout address widget.
      expect(await page.locator('[data-shipping-checkout-address]').count()).toBe(0);

      const quick = page.locator('[data-testid="product-quick-pay"], [data-helppay-quick-pay]').first();
      await expect(quick).toBeVisible({ timeout: 20000 });

      if (await quick.isDisabled()) {
        await expect(quick).toBeDisabled();
        return;
      }

      await quick.click();
      const dialog = page.locator('[data-testid="help-pay-dialog"]');
      await expect(dialog).toBeVisible({ timeout: 10000 });

      const addressPick = page.locator('[data-testid="helppay-address-pick"], [data-helppay-step="address-pick"]');
      const addressHost = page.locator('[data-testid="helppay-address-host"], [data-shipping-checkout-address]');
      const confirmBtn = page.locator('[data-testid="helppay-confirm-quick"], [data-helppay-confirm-quick]');
      const errorHost = page.locator('[data-testid="help-pay-error"], [data-helppay-error]');

      await expect(addressPick.or(errorHost).first()).toBeVisible({ timeout: 15000 });

        if (await addressPick.isVisible()) {
        await expect(addressHost.first()).toBeVisible({ timeout: 20000 });
        await expect(confirmBtn.first()).toBeVisible({ timeout: 10000 });
        const dialogText = await dialog.innerText();
        expect(dialogText).not.toContain('请先到结账页完善');
        expect(dialogText).not.toContain('Operation is not exposed to frontend worker API');
        expect(dialogText.trim().length).toBeGreaterThan(8);

        // 金额可读：确认后不得落到「无法读取商品金额」
        const offerMinor = await page.locator('[data-testid="storefront-product-detail"]').first().getAttribute('data-offer-price-minor');
        const catalogMinor = await page.locator('[data-testid="storefront-product-detail"]').first().getAttribute('data-catalog-price-minor');
        expect(Number(offerMinor || catalogMinor || 0)).toBeGreaterThan(0);

        await confirmBtn.first().click();
        await page.waitForTimeout(2500);

        // 主路径：弹窗内物流 → 支付；禁止整页跳 /q/ 与分享结果网格
        expect(page.url()).not.toContain('/q/');

        const afterConfirm = await dialog.innerText();
        expect(afterConfirm).not.toContain('无法读取商品金额');
        expect(afterConfirm).not.toContain('Invalid Weline binary magic');
        expect(afterConfirm).not.toContain('发给朋友');
        expect(afterConfirm).not.toContain('分享当前规格');

        const flowAttr = await dialog.getAttribute('data-helppay-flow');
        expect(flowAttr).toBe('quick');

        const shippingPick = page.locator('[data-testid="helppay-shipping-pick"], [data-helppay-step="shipping-pick"]');
        const paymentStep = page.locator('[data-testid="helppay-payment-step"], [data-helppay-step="payment"]');
        const shareResult = page.locator('[data-testid="help-pay-share-result"]');

        if (await shippingPick.isVisible().catch(() => false)) {
          const shipText = await dialog.innerText();
          expect(shipText).toMatch(/配送|物流|运费|报价/);
          expect(shipText).not.toMatch(/SEED_LANE_[A-Z0-9_]+ —/);
          const nextShip = page.locator('[data-testid="helppay-confirm-shipping"], [data-helppay-confirm-shipping]');
          if (await nextShip.isVisible().catch(() => false)) {
            const option = page.locator('[data-testid="helppay-ship-option"] input[type="radio"]').first();
            if (await option.count()) {
              await option.check({ force: true }).catch(() => {});
            }
            await nextShip.click();
            await page.waitForTimeout(2000);
          }
        }

        if (await paymentStep.isVisible().catch(() => false)) {
          await expect(page.locator('[data-testid="helppay-start-pay"], [data-helppay-start-pay]').first()).toBeVisible({
            timeout: 10000,
          });
          const payText = await dialog.innerText();
          expect(payText).toMatch(/应付|确认支付|运费|商品/);
          expect(await shareResult.count()).toBe(0);
          return;
        }

        // 游客无完整地址时可能留在 address-pick
        expect(afterConfirm).toMatch(/收货地址|完善|物流|配送|付款|支付/);
        expect(await shareResult.count()).toBe(0);

        return;
      }

      const dialogText = await dialog.innerText();
      expect(dialogText.trim().length).toBeGreaterThan(8);
      expect(dialogText).not.toContain('Operation is not exposed to frontend worker API');
    }
  );
});
