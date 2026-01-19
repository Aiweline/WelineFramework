// tests/e2e/specs/frontend/theme-override.spec.js
// 使用 Playwright 验证前台主题文件（模板 + JS）是否按“当前激活主题优先、覆盖父级/基础主题”生效。

const { test, expect } = require('@playwright/test');

test.describe('Theme frontend override behavior', () => {
  test('WeShop_Default 主题应覆盖 Weline 基础主题的 header 模板', async ({ page }) => {
    // 访问首页（使用 baseURL 前缀）
    await page.goto('/');

    const header = page.locator('header');

    // 1. 断言使用的是 WeShop 头部结构：带有 sticky + top-0 等类（design/WeShop/default/frontend/partials/header/default.phtml）
    await expect(header.first()).toHaveClass(/sticky/);

    // 2. WeShop 头部特有的文案应可见（基础 Weline 头部是中文文案，不包含该英文文案）
    await expect(page.getByText("Shop by Category")).toBeVisible();

    // 3. 确认基础 Weline 头部类名不存在（app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml）
    await expect(page.locator('header.weline-header')).toHaveCount(0);
  });

  test('WeShop_Default 主题的前端 JS（main.js）应生效并提供 WeShop 命名空间', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // WeShop 主题在 app/design/WeShop/default/frontend/assets/js/main.js 中定义 window.WeShop 及其初始化方法
    const hasWeShopNamespace = await page.evaluate(() => {
      return typeof window.WeShop === 'object'
        && typeof window.WeShop.init === 'function'
        && typeof window.WeShop.initHeader === 'function';
    });

    await expect(hasWeShopNamespace).toBeTruthy();

    // 额外保护：基础 Weline 默认主题脚本使用的是 ThemeManager / Weline，而不是 WeShop.initHeader
    const hasWelineThemeManager = await page.evaluate(() => {
      return typeof window.Weline === 'object' || !!document.querySelector('[data-theme]');
    });

    // 两者可以共存，但关键是 WeShop 的 JS 已经加载并生效
    expect(hasWelineThemeManager).toBeTruthy();
  });
});

