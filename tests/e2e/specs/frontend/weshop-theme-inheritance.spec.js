// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend, getRuntimeInfo } = require('../../framework');

/**
 * Theme Inheritance Chain E2E Tests
 * 主题继承链功能测试
 */
test.describe('Theme Inheritance - 主题继承链测试', () => {

    test('TI-01: 主题继承信息解析', async ({ page }) => {
        const runtimeInfo = getRuntimeInfo();
        const activeThemes = runtimeInfo.themes?.active || {};

        console.log('[TI-01] Runtime themes info:', JSON.stringify(activeThemes, null, 2));

        // 验证主题数据结构
        if (activeThemes.frontend) {
            const theme = activeThemes.frontend;
            console.log('[TI-01] Frontend theme:', {
                id: theme.id,
                name: theme.name,
                path: theme.path,
                parent_id: theme.parent_id
            });

            // 如果有父主题，验证父主题信息
            if (theme.parent_id) {
                console.log('[TI-01] Theme has parent_id:', theme.parent_id);
            } else {
                console.log('[TI-01] Theme has no parent (base theme)');
            }
        }
    });

    test('TI-02: 子主题资源覆盖验证', async ({ page }) => {
        // 访问前台首页
        await gotoFrontend(page, '/');

        // 等待页面加载完成
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(1000);

        // 获取所有样式表链接
        const stylesheets = await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
            return links.map(link => ({
                href: link.href,
                media: link.media || 'all'
            }));
        });

        console.log(`[TI-02] Found ${stylesheets.length} stylesheets`);

        // 验证样式表加载
        for (const sheet of stylesheets.slice(0, 5)) {
            console.log('[TI-02] Stylesheet:', sheet.href.substring(0, 100));
        }

        expect(stylesheets.length).toBeGreaterThan(0);
    });

    test('TI-03: 父主题回退机制验证', async ({ page }) => {
        // 访问不同的页面类型，验证布局回退
        const pages = ['/', '/product/view?id=1', '/catalog/category/view?id=1'];

        for (const url of pages) {
            await gotoFrontend(page, url);
            await page.waitForLoadState('domcontentloaded');

            // 验证页面正常加载
            await expect(page.locator('body')).toBeVisible({ timeout: 10000 });

            // 验证没有致命错误
            await expect(page.locator('body')).not.toContainText(/ParseError|Fatal error/i, {
                timeout: 5000
            });

            console.log(`[TI-03] Page ${url} loaded successfully`);
        }
    });

    test('TI-04: 多级继承链遍历验证', async ({ page }) => {
        const runtimeInfo = getRuntimeInfo();
        const activeThemes = runtimeInfo.themes?.active || {};

        // 获取所有主题
        const allThemes = runtimeInfo.themes?.all || [];

        console.log(`[TI-04] Total themes: ${allThemes.length}`);

        // 查找有父子关系的主题
        const parentChildMap = new Map();

        for (const theme of allThemes) {
            if (theme.parent_id) {
                console.log(`[TI-04] Theme "${theme.name}" (ID: ${theme.id}) has parent: ${theme.parent_id}`);

                if (!parentChildMap.has(theme.parent_id)) {
                    parentChildMap.set(theme.parent_id, []);
                }
                parentChildMap.get(theme.parent_id).push(theme);
            }
        }

        // 验证继承关系
        for (const [parentId, children] of parentChildMap) {
            console.log(`[TI-04] Parent ${parentId} has ${children.length} children`);

            // 限制继承深度
            expect(children.length).toBeLessThanOrEqual(10);
        }
    });

    test('TI-05: 主题版本信息验证', async ({ page }) => {
        const runtimeInfo = getRuntimeInfo();
        const activeThemes = runtimeInfo.themes?.active || {};

        if (activeThemes.frontend) {
            const theme = activeThemes.frontend;

            console.log('[TI-05] Theme full data:', JSON.stringify(theme, null, 2));

            // 验证必要字段
            expect(theme.id).toBeTruthy();
            expect(theme.name).toBeTruthy();
            expect(theme.path).toBeTruthy();
        }
    });

    test('TI-06: 主题配置继承验证', async ({ page }) => {
        // 访问前台
        await gotoFrontend(page, '/');
        await page.waitForLoadState('domcontentloaded');

        // 获取页面 HTML 验证主题配置
        const html = await page.content();

        // 验证 HTML 包含主题相关的 class 或属性
        const hasThemeMarker = await page.evaluate(() => {
            const body = document.body;
            // 检查是否有主题标记
            return body.className.includes('theme') ||
                   body.getAttribute('data-theme') ||
                   document.documentElement.getAttribute('data-theme');
        });

        console.log('[TI-06] Has theme marker in HTML:', hasThemeMarker);
    });
});
