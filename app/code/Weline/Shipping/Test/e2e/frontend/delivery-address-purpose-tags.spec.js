/**
 * 结账地址用途标签：编辑器「同时用作收货地址」默认勾选。
 */
const {test, expect} = require('@playwright/test');

test.describe('checkout delivery purpose tags', () => {
  test('shipping editor exposes dual-purpose checkbox default on', async ({page}) => {
    await page.goto('/checkout', {waitUntil: 'domcontentloaded'});
    const root = page.locator('[data-testid="shipping-checkout-address"], [data-shipping-checkout-address]').first();
    await expect(root).toBeVisible({timeout: 30000});

    const checkbox = root.locator('[data-also-use-receiving]');
    await expect(checkbox).toHaveCount(1);
    await expect(checkbox).toBeChecked();

    const addBtn = root.locator('[data-add-address]');
    if (await addBtn.count()) {
      await addBtn.first().click({force: true, timeout: 15000}).catch(() => {});
    }
    // 打开新地址后勾选仍默认开（产品默认勾选，无取消标签 UI）。
    await expect(checkbox).toBeChecked();
    await expect(root.getByText('同时用作收货地址')).toHaveCount(1);
  });
});
