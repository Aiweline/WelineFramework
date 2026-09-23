// @weline-e2e-runtime wls
// @weline-e2e-transport direct
const fs = require('fs');
const { test, expect, buildModuleBackendRoute, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test('existing backend dashboard widget loads its external stylesheet once in head', async ({ page }, testInfo) => {
    test.setTimeout(120000);
    await loginAsAdmin(page, { timeout: 60000, allowPasswordFallback: true });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = await gotoBackend(page, buildModuleBackendRoute('Weline_Dashboard', 'dashboard', 'index'), {
        waitUntil: 'load', timeout: 60000,
    });
    expect(response.status()).toBe(200);
    await expect(page.locator('[data-dashboard-root]')).toBeVisible();
    const widget = page.locator('.widget-wrapper[data-widget-module="Weline_Dashboard"][data-widget-type="table"][data-widget-code="system_status"] .dashboard-widget-status');
    await expect(widget).toBeVisible({ timeout: 30000 });
    await expect(widget.locator('table tr')).toHaveCount(3);
    await expect(widget.locator('style, script:not([type="application/json"])')).toHaveCount(0);
    const moduleSource = 'Weline_Dashboard::css/widgets/widget-system-status.css';
    const stylesheet = page.locator(`link[data-weline-module-source="${moduleSource}"]`);
    await expect(stylesheet).toHaveCount(1);
    await expect(stylesheet).toHaveAttribute('data-weline-widget-asset', 'source');
    await expect(stylesheet).toHaveAttribute('data-weline-source-position', 'head');
    const asset = await stylesheet.evaluate(node => ({ url: node.href, parent: node.parentElement.tagName.toLowerCase(),
        loaded: Boolean(node.sheet), rel: node.rel }));
    expect(asset.parent).toBe('head');
    expect(asset.rel).toBe('stylesheet');
    expect(asset.loaded).toBe(true);
    expect(await page.locator('link[rel="stylesheet"]').evaluateAll((nodes, url) => nodes.filter(node => node.href === url).length, asset.url)).toBe(1);
    expect((await page.request.get(asset.url)).status(), asset.url).toBe(200);
    const htmlPath = testInfo.outputPath('backend-dashboard.html');
    fs.writeFileSync(htmlPath, await page.content());
    await testInfo.attach('backend-dashboard', { path: htmlPath, contentType: 'text/html' });
    await testInfo.attach('backend-widget-assets', { body: JSON.stringify({ page: page.url(), moduleSource, asset, errors }, null, 2), contentType: 'application/json' });
    await widget.screenshot({ path: testInfo.outputPath('backend-system-status.png') });
    expect(errors).toEqual([]);
});
