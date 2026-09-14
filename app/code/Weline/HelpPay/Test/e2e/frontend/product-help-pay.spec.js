/**
 * PDP 找朋友代付：quiet 文字链可见；点击进规则步；可售时下一步进 address-pick。
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

moduleDescribe(test, MODULE, 'product help-pay quiet CTA', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'HELPPAY-PDP-HELP-PAY-001' },
    'quiet help-pay link opens rules then address-pick without secondary button chrome',
    async ({ page }) => {
      await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(1200);

      const body = await page.locator('body').innerText();
      expect(body).not.toMatch(FATAL_PATTERN);
      expect(body).not.toMatch(/系统升级维护中/);

      expect(await page.locator('[data-shipping-checkout-address]').count()).toBe(0);

      const wrap = page.locator('[data-testid="product-help-pay"]').first();
      await expect(wrap).toBeVisible({ timeout: 20000 });
      await expect(wrap).toHaveClass(/w-helppay-cta--quiet/);

      const link = wrap.locator('[data-helppay-open], [data-testid="help-pay-open"]').first();
      await expect(link).toBeVisible();
      const className = (await link.getAttribute('class')) || '';
      expect(className).toContain('w-helppay-cta__link');
      expect(className).not.toMatch(/w-button/);

      if (await link.isDisabled()) {
        await expect(link).toBeDisabled();
        return;
      }

      await link.click();
      const dialog = page.locator('[data-testid="help-pay-dialog"]');
      await expect(dialog).toBeVisible({ timeout: 10000 });

      const rulesStep = page.locator('[data-helppay-step="rules"]');
      await expect(rulesStep).toBeVisible({ timeout: 5000 });
      await expect(page.locator('[data-testid="help-pay-rules-link"]')).toBeVisible();
      await expect(page.locator('[data-testid="help-pay-rules-accepted"]')).toBeChecked();

      await page.locator('[data-helppay-next-address]').click();

      const addressPick = page.locator('[data-testid="helppay-address-pick"], [data-helppay-step="address-pick"]');
      const errorHost = page.locator('[data-testid="help-pay-error"], [data-helppay-error]');
      await expect(addressPick.or(errorHost).first()).toBeVisible({ timeout: 15000 });

      if (await addressPick.isVisible()) {
        await expect(page.locator('[data-testid="helppay-address-host"], [data-shipping-checkout-address]').first()).toBeVisible({
          timeout: 20000,
        });
        const dialogText = await dialog.innerText();
        expect(dialogText).not.toContain('请先到结账页完善');
        expect(dialogText).not.toContain('Operation is not exposed to frontend worker API');
        expect(dialogText).not.toContain('Weline_Framework');
        expect(dialogText).toMatch(/代付|收货地址/);
        const changeBtn = page.locator('[data-helppay-address-mount] [data-change-address]');
        const hasCard = (await page.locator('[data-helppay-address-mount] [data-address-card]').count()) > 0;
        if (hasCard) {
          await expect(changeBtn).toBeVisible({ timeout: 5000 });
          await changeBtn.click();
          await page.waitForTimeout(800);
          await expect(page.locator('[data-helppay-address-mount] [data-shipping-checkout-address]')).toHaveAttribute(
            'data-mode',
            'picking',
          );
        }
      }
    }
  );
});
