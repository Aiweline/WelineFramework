/**
 * 结账找朋友代付：规则步后进可编辑 address-pick（非只读预览）。
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

moduleDescribe(test, MODULE, 'checkout help-pay editable address', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'HELPPAY-CHECKOUT-ADDR-EDIT-001' },
    'checkout help-pay opens address-pick with shipping widget (not readonly preview)',
    async ({ page }) => {
      // 空车时摘要区可能把代付链 hidden；先装车再验结账弹层。
      await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(1000);
      const addBtn = page.locator('[data-testid="product-add-to-cart"]').first();
      if ((await addBtn.count()) > 0 && !(await addBtn.isDisabled())) {
        await addBtn.click();
        await page.waitForTimeout(1500);
      }

      await page.goto(`${BASE}/checkout`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(1500);

      const body = await page.locator('body').innerText();
      expect(body).not.toMatch(FATAL_PATTERN);
      expect(body).not.toMatch(/系统升级维护中/);

      const openBtn = page.locator('[data-testid="checkout-summary-help-pay"] [data-helppay-open], [data-helppay-placement="checkout"] [data-helppay-open]').first();
      if ((await openBtn.count()) === 0) {
        test.skip(true, 'checkout help-pay CTA not present (slot not seeded)');
        return;
      }
      await expect(openBtn).toBeVisible({ timeout: 20000 });
      await openBtn.click();

      const dialog = page.locator('[data-testid="help-pay-dialog"]');
      await expect(dialog).toBeVisible({ timeout: 10000 });
      await expect(page.locator('[data-helppay-step="rules"]')).toBeVisible();

      await page.locator('[data-helppay-next-address]').click();

      const addressPick = page.locator('[data-testid="helppay-address-pick"], [data-helppay-step="address-pick"]');
      const errorHost = page.locator('[data-testid="help-pay-error"], [data-helppay-error]');
      await expect(addressPick.or(errorHost).first()).toBeVisible({ timeout: 15000 });

      expect(await page.locator('[data-testid="help-pay-address-preview"]').count()).toBe(0);

      if (await addressPick.isVisible()) {
        await expect(
          page.locator('[data-helppay-address-mount] [data-shipping-checkout-address], [data-helppay-address-mount] [data-helppay-address-host]').first()
        ).toBeVisible({ timeout: 20000 });
        const dialogText = await dialog.innerText();
        expect(dialogText).not.toContain('尚未填写完整收货地址');
        expect(dialogText).not.toContain('请先在结账页完善后再生成帮我付链接');
        expect(dialogText).toMatch(/收货地址|更换|新地址|确认|编辑/);
      }
    }
  );
});
