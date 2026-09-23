// @weline-e2e-runtime wls
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

test('real scoped resource settings change delivered assets and restore exact overrides', async ({ page }, testInfo) => {
    test.setTimeout(600000);
    const startedAt = Date.now();
    const stateFile = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'weline-resource-config-')), 'original.json');
    const helper = path.join(__dirname, 'resource-config-acceptance.php');
    const config = action => JSON.parse(execFileSync('php', [helper, action, stateFile], { encoding: 'utf8', timeout: 90000 }));
    const snapshot = config('snapshot');
    console.log('RESOURCE_CONFIG_ORIGINAL', JSON.stringify(snapshot));
    const inspect = () => page.evaluate(() => Array.from(document.querySelectorAll('link[data-weline-widget-asset],script[data-weline-widget-asset]')).map(node => ({
        url: node.href || node.src, kind: node.dataset.welineWidgetAsset,
        position: node.dataset.welineSourcePosition || 'head', type: node.tagName === 'LINK' ? 'css' : 'js',
        parent: node.parentElement.tagName.toLowerCase(),
    })));
    const baselineResponse = await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await testInfo.attach('baseline-html', { body: await baselineResponse.body(), contentType: 'text/html' });
    const before = await inspect();
    console.log('RESOURCE_CONFIG_STATE', stateFile);
    try {
        const result = config('apply');
        console.log('RESOURCE_CONFIG_APPLIED', JSON.stringify({ version_id: result.version_id, scope: result.scope }));
        const response = await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
        await testInfo.attach('configured-html', { body: await response.body(), contentType: 'text/html' });
        expect(response.status()).toBe(200);
        const after = await inspect();
        console.log('RESOURCE_CONFIG_DELIVERY', JSON.stringify({ before, after }));
        const bundles = after.filter(item => item.url.includes('/widget-assets/'));
        expect(bundles.some(item => item.type === 'css')).toBe(true);
        expect(bundles.some(item => item.type === 'js')).toBe(true);
        expect(after.filter(item => item.kind === 'layout')).toHaveLength(before.filter(item => item.kind === 'layout').length);
        expect(after.filter(item => item.kind === 'source').length).toBeLessThan(before.filter(item => item.kind === 'source').length);
        expect(new Set(after.map(item => item.url)).size).toBe(after.length);
        for (const resource of after) {
            const asset = await page.request.get(resource.url);
            expect(asset.status(), resource.url).toBe(200);
            expect(asset.headers()['content-type']).toMatch(resource.type === 'css' ? /text\/css/ : /javascript/);
            expect(resource.parent).toBe(resource.position === 'head' ? 'head' : resource.position === 'footer' ? 'footer' : 'body');
        }
        await page.waitForFunction(() => Boolean(window.WelineWidgetAssets));
        const backToTop = page.locator('#navBackToTop');
        await expect(backToTop).toHaveAttribute('data-weline-back-to-top-bound', '1');
        await backToTop.scrollIntoViewIfNeeded();
        await backToTop.click();
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeLessThanOrEqual(1);
    } finally {
        testInfo.setTimeout(Math.max(600000, Date.now() - startedAt + 180000));
        // The model's version rollback rejects concurrent changes instead of overwriting another editor.
        {
            const restored = config('restore');
            console.log('RESOURCE_CONFIG_RESTORED', JSON.stringify(restored));
            expect(restored.restored).toBe(true);
        }
    }
});
