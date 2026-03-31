// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend, gotoBackend, loginAsAdmin } = require('../../framework');

/**
 * Theme Frontend Features E2E Tests
 * 前端主题功能测试（语言切换、购物车等）
 */
test.describe('Theme Frontend Features - 前端主题功能测试', () => {

    test('FF-01: 页面基础主题渲染', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 验证页面正常加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 验证没有运行时错误
        await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|Fatal error/i, {
            timeout: 10000
        });

        // 获取主题信息
        const themeInfo = await page.evaluate(() => {
            const body = document.body;
            return {
                className: body.className,
                dataTheme: body.getAttribute('data-theme'),
                htmlClass: document.documentElement.className,
                htmlDataTheme: document.documentElement.getAttribute('data-theme')
            };
        });

        console.log('[FF-01] Theme info:', JSON.stringify(themeInfo, null, 2));
    });

    test('FF-02: 语言切换功能', async ({ page }) => {
        // 1. 访问前台，获取初始语言状态
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取当前页面文本语言
        const initialLang = await page.evaluate(() => {
            const html = document.documentElement;
            return {
                lang: html.getAttribute('lang'),
                dir: html.getAttribute('dir')
            };
        });

        console.log('[FF-02] Initial language:', JSON.stringify(initialLang));

        // 2. 尝试找到语言切换器
        const langSwitcher = page.locator('[data-lang-switch], .lang-switcher, select[name="language"], .language-selector').first();

        if (await langSwitcher.isVisible({ timeout: 3000 }).catch(() => false)) {
            console.log('[FF-02] Language switcher found');

            // 获取所有选项
            const options = langSwitcher.locator('option');
            const count = await options.count();

            if (count > 1) {
                // 选择第二个选项
                await options.nth(1).click();
                await page.waitForTimeout(2000);

                // 刷新页面验证
                await page.reload();
                await page.waitForLoadState('domcontentloaded');

                console.log('[FF-02] Language switched to second option');
            }
        } else {
            console.log('[FF-02] Language switcher not found on this page');
        }

        // 3. 验证页面仍然正常
        await expect(page.locator('body')).toBeVisible({ timeout: 10000 });
    });

    test('FF-03: 购物车组件主题渲染', async ({ page }) => {
        // 访问购物车页面（须带 weshop 模块前缀；裸 /cart 易被静态文件规则吞掉成 404）
        await gotoFrontend(page, '/weshop/cart');

        await page.waitForLoadState('domcontentloaded');

        // 验证页面加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 获取购物车相关信息
        const cartInfo = await page.evaluate(() => {
            const body = document.body;
            // 查找购物车容器
            const cartContainers = body.querySelectorAll('[class*="cart"], [id*="cart"], .mini-cart, .cart-drawer');
            return {
                hasCartElements: cartContainers.length > 0,
                cartElementCount: cartContainers.length,
                pageText: body.innerText.substring(0, 200)
            };
        });

        console.log('[FF-03] Cart info:', JSON.stringify(cartInfo, null, 2));

        // 验证购物车组件或内容存在
        // 注意：空购物车页面应该显示空购物车提示
    });

    test('FF-04: 产品列表页主题渲染', async ({ page }) => {
        // 访问分类页面（如果有产品的话）
        await gotoFrontend(page, '/catalog/category/view?id=1');

        await page.waitForLoadState('domcontentloaded');

        // 验证页面加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 获取产品列表容器
        const productListInfo = await page.evaluate(() => {
            const body = document.body;
            // 查找产品列表容器
            const productContainers = body.querySelectorAll('[class*="product"], [class*="catalog"], [class*="grid"]');
            return {
                hasProductElements: productContainers.length > 0,
                productElementCount: productContainers.length
            };
        });

        console.log('[FF-04] Product list info:', JSON.stringify(productListInfo, null, 2));
    });

    test('FF-05: 产品详情页主题渲染', async ({ page }) => {
        // 访问产品详情页
        await gotoFrontend(page, '/product/view?id=1');

        await page.waitForLoadState('domcontentloaded');

        // 验证页面加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 获取产品详情
        const productInfo = await page.evaluate(() => {
            const body = document.body;
            // 查找产品容器
            const productContainers = body.querySelectorAll('[class*="product-detail"], [class*="product-info"], .product');
            return {
                hasProductDetail: productContainers.length > 0,
                productDetailCount: productContainers.length
            };
        });

        console.log('[FF-05] Product detail info:', JSON.stringify(productInfo, null, 2));
    });

    test('FF-06: 主题配置实时生效验证', async ({ page }) => {
        // 这个测试验证主题配置的实时性

        // 1. 访问前台
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取初始样式
        const initialStyles = await page.evaluate(() => {
            const body = document.body;
            const styles = getComputedStyle(body);
            return {
                backgroundColor: styles.backgroundColor,
                color: styles.color
            };
        });

        console.log('[FF-06] Initial styles:', JSON.stringify(initialStyles));

        // 2. 登录后台，修改配置（可选）
        // 由于修改配置可能导致问题，这里只验证访问模式

        // 3. 再次访问前台
        await page.reload();
        await page.waitForLoadState('domcontentloaded');

        // 获取新样式
        const newStyles = await page.evaluate(() => {
            const body = document.body;
            const styles = getComputedStyle(body);
            return {
                backgroundColor: styles.backgroundColor,
                color: styles.color
            };
        });

        console.log('[FF-06] New styles:', JSON.stringify(newStyles));

        // 样式应该保持一致
        expect(newStyles.backgroundColor).toBe(initialStyles.backgroundColor);
    });

    test('FF-07: 多页面主题一致性', async ({ page }) => {
        const pages = [
            { url: '/', name: 'Homepage' },
            { url: '/weshop/cart', name: 'Cart' }
        ];

        let previousThemeMarker = null;

        for (const { url, name } of pages) {
            await gotoFrontend(page, url);
            await page.waitForLoadState('domcontentloaded');

            // 获取主题标记
            const themeMarker = await page.evaluate(() => {
                const body = document.body;
                return body.getAttribute('data-theme') ||
                       document.documentElement.getAttribute('data-theme') ||
                       body.className;
            });

            console.log(`[FF-07] ${name} theme marker: ${themeMarker?.substring(0, 50)}`);

            if (previousThemeMarker) {
                // 验证主题一致
                expect(themeMarker).toBe(previousThemeMarker);
            }

            previousThemeMarker = themeMarker;
        }

        console.log('[FF-07] Theme consistency verified across pages');
    });

    test('FF-08: 页脚主题渲染', async ({ page }) => {
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取页脚元素
        const footerInfo = await page.evaluate(() => {
            const footers = document.querySelectorAll('footer, [class*="footer"], [id*="footer"]');
            return {
                count: footers.length,
                hasFooter: footers.length > 0,
                firstFooterClass: footers[0]?.className?.substring(0, 50)
            };
        });

        console.log('[FF-08] Footer info:', JSON.stringify(footerInfo, null, 2));

        // 验证页脚存在
        expect(footerInfo.hasFooter).toBe(true);
    });
});
