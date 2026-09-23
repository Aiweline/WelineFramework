// @weline-e2e-runtime wls
const { observeResourceSdk } = require('./resource-sdk-observer');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { test, expect, gotoBackend, loginAsAdmin, getActiveTheme } = require('../../../../../../../tests/e2e/framework');
const projectRoot = path.resolve(__dirname, '../../../../../../../');
const helper = path.join(__dirname, '../frontend/resource-config-acceptance.php');
const key = 'resource_files/css_merge_start';
const isCompile = response => {
    if (response.provider !== 'theme' || !response.url()) return false;
    const url = new URL(response.url(), 'https://editor.test');
    return url.pathname.endsWith('/compile-layout') && url.searchParams.get('resource_refresh') === '1';
};
const isSave = response => response.provider === 'system_config' && response.operation === 'setScopedConfig' && response.params.key === key;

function savePayload(value) {
    if (value && typeof value === 'object') {
        if (value.version_id !== undefined && value.success !== undefined) return value;
        for (const child of Object.values(value)) {
            const found = savePayload(child);
            if (found) return found;
        }
    }
    return null;
}

test('resource threshold UI save solidifies layout then refreshes iframe and restores exact overrides', async ({ page }, testInfo) => {
    test.setTimeout(480000);
    const started = Date.now();
    const sdk = await observeResourceSdk(page);
    const stateFile = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'weline-resource-ui-save-')), 'original.json');
    const config = (action, ...args) => JSON.parse(execFileSync('php', [helper, action, stateFile, ...args.map(String)], { encoding: 'utf8', timeout: 90000 }));
    console.log('RESOURCE_UI_RECOVERY_STATE', stateFile);
    const theme = getActiveTheme('frontend');
    await loginAsAdmin(page, { timeout: 60000, settleMs: 1000, allowPasswordFallback: true });
    const editor = await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${theme.id}`, { waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1000 });
    expect(editor.status()).toBe(200);
    await page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first().click();
    const drawer = page.locator('#themeBrandBasicsDrawer');
    const preview = page.locator('#previewFrame');
    const frame = await (await preview.elementHandle()).contentFrame();
    // Finish the tab's own refresh before observing the distinct save-triggered refresh.
    const tabCompile = sdk.waitForResponse(isCompile, { timeout: 90000 });
    const tabNavigation = frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
    await drawer.locator('#themeBrandResourcesTab').click();
    const [tabResponse, tabPreview] = await Promise.all([tabCompile, tabNavigation]);
    expect((await tabResponse.json()).success).toBe(true);
    expect(tabPreview.status()).toBe(200);
    const embed = drawer.locator('#themeResourceFilesConfig [data-w-config-embed]');
    await expect(embed).toHaveAttribute('data-module', 'Weline_Theme');
    await expect(embed).toHaveAttribute('data-area', 'frontend');
    await expect(embed).toHaveAttribute('data-locale', 'default');
    const original = config('snapshot');
    expect(await embed.getAttribute('data-storage-scope')).toBe(original.scope);
    const input = embed.locator(`[data-config-key="${key}"] input[type="number"]`);
    const next = Number(await input.inputValue()) + 1;
    const before = new URL(await preview.getAttribute('src'), page.url());
    const lifecycle = [];
    let saveWait;
    const onResponse = response => {
        if (isSave(response)) lifecycle.push('save-response');
        if (isCompile(response)) lifecycle.push('materialization-response');
    };
    const onRequest = request => {
        if (request.isNavigationRequest() && request.frame() === frame && request.url() !== before.href) lifecycle.push('iframe-request');
    };
    try {
        config('prepare-ui', key, next);
        sdk.on('response', onResponse);
        page.on('request', onRequest);
        saveWait = sdk.waitForResponse(isSave, { timeout: 90000 });
        const compileWait = sdk.waitForResponse(isCompile, { timeout: 90000 });
        const navigationWait = frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
        // Observe every waiter immediately so failures cannot leave unhandled rejections.
        const completed = Promise.all([saveWait, compileWait, navigationWait]);
        completed.catch(() => {});
        await input.fill(String(next));
        await input.press('Tab');
        const savedResponse = await saveWait;
        const wireResult = await savedResponse.json();
        const saved = savePayload(wireResult);
        expect(savedResponse.error, JSON.stringify(savedResponse.error)).toBeUndefined();
        expect(saved?.success, JSON.stringify(wireResult)).toBe(true);
        expect(saved.version_id).toBeGreaterThan(0);
        config('record-ui-version', saved.version_id);
        const [, compiledResponse, navigation] = await completed;
        expect(new URL(compiledResponse.url(), page.url()).searchParams.get('scope')).toBe(original.scope);
        const compiled = await compiledResponse.json();
        expect(compiled.success).toBe(true);
        const output = compiled.resource_materialization;
        expect(output.artifacts.some(artifact => artifact.type === 'page')).toBe(true);
        for (const artifact of output.artifacts) {
            expect(artifact.exists).toBe(true);
            expect(path.isAbsolute(artifact.relative_path)).toBe(false);
            const file = path.resolve(projectRoot, artifact.relative_path);
            expect(file.startsWith(projectRoot + path.sep)).toBe(true);
            expect(fs.statSync(file).isFile()).toBe(true);
            expect(crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')).toBe(artifact.artifact_id);
        }
        expect(lifecycle.indexOf('save-response')).toBeGreaterThanOrEqual(0);
        expect(lifecycle.indexOf('materialization-response')).toBeGreaterThan(lifecycle.indexOf('save-response'));
        expect(lifecycle.indexOf('iframe-request')).toBeGreaterThan(lifecycle.indexOf('materialization-response'));
        expect(navigation.status()).toBe(200);
        const after = new URL(await preview.getAttribute('src'), page.url());
        expect(after.searchParams.get('_t')).not.toBe(before.searchParams.get('_t'));
        const previousContext = JSON.parse(before.searchParams.get('editor_context'));
        expect(JSON.parse(new URL(compiledResponse.url(), page.url()).searchParams.get('editor_context'))).toEqual(previousContext);
        expect(output.version_id).toBe(before.searchParams.has('version_id') ? Number(before.searchParams.get('version_id')) : null);
        expect(output.status).toBe(before.searchParams.get('status') || 'draft');
        before.searchParams.delete('_t'); after.searchParams.delete('_t');
        expect(after.href).toBe(before.href);
        await testInfo.attach('ui-save-materialization.json', { body: JSON.stringify({ version_id: saved.version_id, key, next, lifecycle, output }, null, 2), contentType: 'application/json' });
    } finally {
        testInfo.setTimeout(Math.max(480000, Date.now() - started + 180000));
        sdk.off('response', onResponse); page.off('request', onRequest);
        if (saveWait) await saveWait.catch(() => null);
        const restored = config('restore');
        console.log('RESOURCE_UI_RESTORED', JSON.stringify(restored));
        expect(restored.restored).toBe(true);
        await testInfo.attach('ui-save-restored.json', { path: stateFile, contentType: 'application/json' });
    }
});
