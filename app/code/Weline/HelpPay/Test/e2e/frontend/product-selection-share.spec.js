/**
 * PDP 纯分享：点击「分享给朋友」不得报 Unknown frontend worker param: cart_type。
 *
 * @weline-e2e-spec { module: Weline_HelpPay, type: smoke, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const fs = require('fs');
const path = require('path');
const { test, expect, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_HelpPay';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const PDP = '/product/yue-ya-ni-shang-yue-ya-ni-shang-yuan-chuang-zheng-pin-si-wu-xie-jin-zhi-ji-72642ec3?size=s&style_type=hf-s-qian-lu12-po6-mi-bai-d3733f9';

moduleDescribe(test, MODULE, 'product selection share CTA', () => {
  test.setTimeout(90000);

  moduleCase(
    test,
    { module: MODULE, id: 'HELPPAY-SHARE-CART-TYPE-001' },
    'share with friends does not reject cart_type param',
    async ({ page }) => {
      const provider = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/HelpPay/extends/module/Weline_Framework/Query/HelpPayQueryProvider.php'),
        'utf8'
      );
      expect(provider).toMatch(/'name'\s*=>\s*'createSelectionShare'[\s\S]{0,1200}'cart_type'/);

      const js = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/HelpPay/view/statics/js/helppay-share.js'),
        'utf8'
      );
      expect(js).toContain("cart_type: 'toc'");
      expect(js).toMatch(/Unknown frontend worker param/i);
      expect(js).toContain('flashCopyFeedback');
      expect(js).toContain('已复制，可以分发给朋友');
      expect(js).toContain('已复制，可直接粘贴图片到聊天里发送');
      expect(js).not.toContain('help-pay-copy-feedback');

      try {
        await page.goto(`${BASE}${PDP}`, { waitUntil: 'domcontentloaded', timeout: 45000 });
      } catch (err) {
        test.skip(true, 'storefront unreachable for live share click: ' + String(err && err.message ? err.message : err));
      }

      await page.context().grantPermissions(['clipboard-read', 'clipboard-write']).catch(function () {});
      await page.waitForTimeout(1200);
      // 分享一期仅 toc：尽量切到零售车
      const toc = page.locator('[data-testid="mini-cart-type-toc"]').first();
      if (await toc.count()) {
        await toc.click({ force: true }).catch(function () {});
        await page.waitForTimeout(400);
        await page.keyboard.press('Escape').catch(function () {});
      }

      const share = page.locator('[data-testid="product-selection-share"], [data-helppay-selection-share]').first();
      await expect(share).toBeVisible({ timeout: 20000 });
      await share.click();

      const dialog = page.locator('[data-testid="help-pay-dialog"]');
      await expect(dialog).toBeVisible({ timeout: 10000 });

      const shareUrl = page.locator('[data-testid="help-pay-share-url"]');
      const errorBox = page.locator('[data-testid="help-pay-error"], [data-helppay-error]');
      await expect
        .poll(async () => (await shareUrl.count()) + (await errorBox.count()), { timeout: 20000 })
        .toBeGreaterThan(0);

      const dialogText = await dialog.innerText();
      expect(dialogText).not.toContain('Unknown frontend worker param: cart_type');
      expect(dialogText).not.toContain('Unknown frontend worker param');

      if ((await shareUrl.count()) > 0) {
        await expect(page.locator('.w-helppay-share-result__section-label').first()).toBeVisible();
        await expect(page.locator('.w-helppay-share-result__actions').first()).toBeVisible();
        const tipUrl = page.locator('[data-testid="help-pay-copy-tip-url"]').first();
        const tipQr = page.locator('[data-testid="help-pay-copy-tip-qr"]').first();
        await expect(tipUrl).toContainText('粘贴发给朋友');
        await expect(tipQr).toContainText('粘贴到聊天');
        const copyBtn = page.locator('[data-testid="help-pay-copy-url"]').first();
        await expect(copyBtn).toBeEnabled({ timeout: 5000 });
        await copyBtn.click();
        await expect(tipUrl).toContainText(/已复制，可以分发给朋友|复制没成功/, { timeout: 8000 });
        await page.waitForTimeout(2200);
        await expect(tipUrl).toContainText(/已复制，可以分发给朋友|复制没成功/);
        await expect(page.locator('[data-testid="help-pay-copy-feedback"]')).toHaveCount(0);
      }

      if ((await errorBox.count()) > 0) {
        const errText = await page.locator('[data-testid="help-pay-error"]').innerText();
        expect(errText).not.toMatch(/cart_type/i);
        expect(errText.trim().length).toBeGreaterThan(4);
      }
    }
  );
});
