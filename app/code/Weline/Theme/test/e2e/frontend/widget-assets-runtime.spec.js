// @weline-e2e-runtime wls
const { observeResourceSdk } = require('../backend/resource-sdk-observer');
const { test, expect, gotoFrontend, gotoBackend, loginAsAdmin, getActiveTheme } = require('../../../../../../../tests/e2e/framework');

test.describe('widget external resources on the configured running site', () => {
    test.setTimeout(180000);
    test('storefront delivers unique resources at declared locations with live initializer anchors', async ({ page }) => {
        const response = await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
        expect(await response.text()).not.toContain('template 标签缺少模板路径');
        await page.waitForFunction(() => Boolean(window.WelineWidgetAssets));
        const result = await page.evaluate(() => {
            const nodes = Array.from(document.querySelectorAll('link[data-weline-widget-asset],script[data-weline-widget-asset]'));
            const seen = new Set();
            const duplicates = [], misplaced = [];
            for (const node of nodes) {
                const url = node.href || node.src;
                if (seen.has(url)) duplicates.push(url);
                seen.add(url);
                const position = node.dataset.welineSourcePosition || 'head';
                const target = position === 'head' ? document.head : position === 'footer' ? (document.querySelector('footer') || document.body) : document.body;
                if (!target.contains(node)) misplaced.push({ url, position });
            }
            const groups = nodes.map(node => node.dataset.welineWidgetAsset);
            const firstSource = groups.indexOf('source');
            return { count: nodes.length, duplicates, misplaced, anchors: document.querySelectorAll('[data-widget-script]').length,
                layoutAfterSource: firstSource < 0 ? false : groups.slice(firstSource).includes('layout'),
                resources: nodes.map(node => node.href || node.src) };
        });
        console.log('LIVE_ASSET_RESULT', JSON.stringify(result));
        expect(result.count).toBeGreaterThan(10);
        expect(result.duplicates).toEqual([]);
        expect(result.misplaced).toEqual([]);
        expect(result.layoutAfterSource).toBe(false);
        expect(result.anchors).toBeGreaterThan(0);
        for (const url of result.resources) {
            const asset = await page.request.get(url);
            expect(asset.status(), url).toBe(200);
        }
        const backToTop = page.locator('#navBackToTop');
        await expect(backToTop).toHaveAttribute('data-weline-back-to-top-bound', '1');
        await backToTop.scrollIntoViewIfNeeded();
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
        await backToTop.click();
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeLessThanOrEqual(1);
    });

    test('editor component preview loads declared external assets after a real preview request', async ({ page }) => {
        const sdk = await observeResourceSdk(page, { themeUrlIncludes: ['widget-preview'] });
        page.on('requestfailed', request => { if (request.isNavigationRequest()) console.log('FAILED_NAVIGATION', request.url().split('?')[0], request.failure()?.errorText); });
        page.on('response', response => { if (response.status() >= 400 || response.url().includes('widget')) console.log('EDITOR_RESPONSE', response.status(), response.url().split('?')[0]); });
        page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') console.log('EDITOR_CONSOLE', message.text()); });
        page.on('requestfailed', request => console.log('EDITOR_FAILED', request.url().split('?')[0], request.failure()?.errorText));
        page.on('pageerror', error => console.log('EDITOR_ERROR', error.message));
        const theme = getActiveTheme('frontend');
        expect(theme).toBeTruthy();
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000, allowPasswordFallback: true });
        await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${theme.id}`, { waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1000 });
        await page.locator('#widgetSearch').fill('deals-of-day');
        const button = page.locator('#widgetList .w-theme-editor-preview-component[data-widget-module="Weline_Theme"][data-widget-code="deals-of-day"]').first();
        await expect(button).toBeVisible({ timeout: 60000 });
        const responsePromise = sdk.waitForResponse(response => {
            if (response.provider !== 'theme' || response.operation !== 'editorRequest' || response.params.method !== 'GET') return false;
            const url = new URL(response.url(), page.url());
            return url.pathname.endsWith('/widget-preview')
                && url.searchParams.get('widget_module') === 'Weline_Theme'
                && url.searchParams.get('widget_code') === 'deals-of-day';
        });
        await button.click();
        const response = await responsePromise;
        expect(response.error, JSON.stringify(response.error)).toBeUndefined();
        const payload = await response.json();
        expect(payload.html).toContain('data-weline-widget-assets');
        await expect(page.locator('#componentPreviewModal .component-preview-inner [data-widget-script]').first()).toBeAttached();
        await page.waitForFunction(() => Array.from(document.querySelectorAll('script[src]')).some(node => node.src.includes('widget-product-deals-of-day-default-0.js')));
        await expect(page.locator('#componentPreviewModal .widget-preview-error')).toHaveCount(0);
    });
});
