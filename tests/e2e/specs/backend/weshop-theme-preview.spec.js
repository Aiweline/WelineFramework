// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoBackend, gotoFrontend, loginAsAdmin, getActiveThemeId, buildThemePreviewUrl } = require('../../framework');

/**
 * Theme Preview E2E Tests
 * 主题版本预览功能测试
 */
test.describe('Theme Preview - 主题版本预览测试', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('TP-01: 主题预览 URL 生成', async ({ page }) => {
        const themeId = getActiveThemeId('frontend');

        // 生成预览 URL
        const previewUrl = buildThemePreviewUrl({
            themeId: themeId,
            pageType: 'homepage',
            previewMode: 'live',
            status: 'draft'
        });

        console.log('[TP-01] Generated preview URL:', previewUrl);

        expect(previewUrl).toContain('theme');
        expect(previewUrl).toContain('preview');
    });

    test('TP-02: 主题预览页面加载', async ({ page }) => {
        const themeId = getActiveThemeId('frontend');

        // 使用预览 URL
        const previewUrl = buildThemePreviewUrl({
            themeId: themeId,
            pageType: 'homepage'
        });

        await gotoFrontend(page, previewUrl.replace(/^https?:\/\/[^/]+/, ''));

        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2000);

        // 验证页面正常加载
        await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

        // 验证没有致命错误
        await expect(page.locator('body')).not.toContainText(/ParseError|Fatal error|WLS Runtime Error/i, {
            timeout: 10000
        });

        console.log('[TP-02] Preview page loaded successfully');
    });

    test('TP-03: 不同页面类型预览', async ({ page }) => {
        const themeId = getActiveThemeId('frontend');
        const pageTypes = ['homepage', 'product', 'category', 'cart', 'checkout'];

        for (const pageType of pageTypes) {
            const previewUrl = buildThemePreviewUrl({
                themeId: themeId,
                pageType: pageType
            });

            await gotoFrontend(page, previewUrl.replace(/^https?:\/\/[^/]+/, ''));

            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(1000);

            // 验证页面正常
            const isValid = await page.locator('body').isVisible().catch(() => false);

            console.log(`[TP-03] Page type "${pageType}" preview: ${isValid ? 'OK' : 'Failed'}`);

            expect(isValid).toBe(true);
        }
    });

    test('TP-04: 预览模式切换', async ({ page }) => {
        const themeId = getActiveThemeId('frontend');

        // 测试不同的预览模式
        const previewModes = ['live', 'draft', 'published'];

        for (const mode of previewModes) {
            const previewUrl = buildThemePreviewUrl({
                themeId: themeId,
                pageType: 'homepage',
                previewMode: mode,
                status: mode === 'draft' ? 'draft' : 'published'
            });

            await gotoFrontend(page, previewUrl.replace(/^https?:\/\/[^/]+/, ''));

            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(1000);

            console.log(`[TP-04] Preview mode "${mode}" loaded`);

            // 验证没有致命错误
            const hasError = await page.locator('body').evaluate(el => {
                return el.innerText.includes('Fatal error') ||
                       el.innerText.includes('ParseError');
            });

            expect(hasError).toBe(false);
        }
    });

    test('TP-05: 预览时主题标记', async ({ page }) => {
        const themeId = getActiveThemeId('frontend');

        const previewUrl = buildThemePreviewUrl({
            themeId: themeId,
            pageType: 'homepage'
        });

        await gotoFrontend(page, previewUrl.replace(/^https?:\/\/[^/]+/, ''));

        await page.waitForLoadState('domcontentloaded');

        // 获取主题相关标记
        const themeMarker = await page.evaluate(() => {
            const body = document.body;
            const html = document.documentElement;

            return {
                bodyClass: body.className,
                bodyDataTheme: body.getAttribute('data-theme'),
                bodyDataPreview: body.getAttribute('data-preview'),
                htmlDataTheme: html.getAttribute('data-theme'),
                url: window.location.href
            };
        });

        console.log('[TP-05] Preview theme markers:', JSON.stringify(themeMarker, null, 2));

        // 验证预览模式
        expect(themeMarker.url).toContain('preview');
    });

    test('TP-06: 主题版本信息', async ({ page }) => {
        // 访问主题管理页面，检查版本信息
        await gotoBackend(page, 'theme/backend');

        // 查找版本相关的 UI
        const versionBadge = page.locator('[data-version], .version-badge, [data-theme-version]').first();

        if (await versionBadge.isVisible({ timeout: 3000 }).catch(() => false)) {
            const versionText = await versionBadge.textContent();
            console.log('[TP-06] Theme version:', versionText);
        } else {
            console.log('[TP-06] Version badge not found');
        }
    });
});
