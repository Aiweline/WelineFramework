// @weline-e2e-runtime wls
const { observeResourceSdk } = require('./resource-sdk-observer');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const projectRoot = path.resolve(__dirname, '../../../../../../../');
const { test, expect, gotoBackend, loginAsAdmin, getActiveTheme } = require('../../../../../../../tests/e2e/framework');

test.describe('theme basic information resource configuration', () => {
    test.setTimeout(180000);

    test('resource tab materializes current layout before refreshing preview and preserves Scope', async ({ page }, testInfo) => {
        const sdk = await observeResourceSdk(page);
        page.on('console', message => { if (message.type() === 'error') console.log('RESOURCE_EDITOR_ERROR', message.text()); });
        page.on('pageerror', error => console.log('RESOURCE_EDITOR_EXCEPTION', error.message));
        page.on('request', request => { if (request.url().includes('resource_refresh')) console.log('RESOURCE_COMPILE_REQUEST', request.url()); });
        page.on('response', response => { if (response.url().includes('resource_refresh')) console.log('RESOURCE_COMPILE_RESPONSE', response.status(), response.url()); });
        const theme = getActiveTheme('frontend');
        expect(theme).toBeTruthy();
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000, allowPasswordFallback: true });
        const response = await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${theme.id}`, {
            waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1000,
        });
        expect(response.status(), `Editor response at ${page.url()}`).toBe(200);
        const scope = page.locator('#scopeSelect');
        await expect(scope).toBeAttached({ timeout: 15000 });
        const initialScope = await scope.inputValue();
        const initialUrl = page.url();
        const opener = page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first();
        await opener.click();
        const drawer = page.locator('#themeBrandBasicsDrawer');
        await expect(drawer).toBeVisible();
        const badge = drawer.locator('#themeBrandScopeBadge');
        const save = drawer.locator('[data-w-brand-action="save"]');
        const inherit = drawer.locator('[data-w-brand-action="inherit"]');
        await expect(drawer.locator('#themeBrandBaseTab')).toHaveAttribute('aria-selected', 'true');
        await expect(save).toBeVisible();
        await expect(inherit).toBeVisible();
        const preview = page.locator('#previewFrame');
        const wiring = await page.evaluate(() => ({
            method: typeof window.Weline?.Theme?.Editor?.refreshResourceConfigurationPreview,
            drawerBound: document.getElementById('themeEditor')?.dataset.brandBasicsBound,
            scripts: Array.from(document.scripts).map(script => script.src).filter(src => /theme-editor|brand-basics/.test(src)),
        }));
        console.log('RESOURCE_EDITOR_WIRING', JSON.stringify(wiring));
        expect(wiring.method).toBe('function');
        expect(wiring.drawerBound).toBe('1');
        const previousPreviewUrl = new URL(await preview.getAttribute('src'), page.url());
        const previousContext = JSON.parse(previousPreviewUrl.searchParams.get('editor_context'));
        expect(previousContext).toBeTruthy();
        // openDrawer starts refreshFromWorkspace asynchronously. Its scope label is
        // populated only after the appearance workspace resolves; do not baseline
        // the server-rendered placeholder while that request is still in flight.
        const typedIdentity = previousContext.scope.identity;
        await expect.poll(() => page.evaluate(() => window.Weline.Theme.Editor.getScopeIdentity()))
            .toEqual(typedIdentity);
        const expectedScopeParts = ['作用范围'];
        if (typedIdentity.website_code) expectedScopeParts.push(`网站:${typedIdentity.website_code}`);
        if (typedIdentity.store_code) expectedScopeParts.push(`店铺:${typedIdentity.store_code}`);
        if (typedIdentity.channel_code) expectedScopeParts.push(`渠道:${typedIdentity.channel_code}`);
        await expect(badge.locator('[data-w-brand-scope-label]'))
            .toHaveText(expectedScopeParts.join(' · '), { timeout: 60000 });
        const badgeText = await badge.innerText();
        const previewFrame = await (await preview.elementHandle()).contentFrame();
        await expect.poll(() => previewFrame.url(), { timeout: 60000 }).toBe(previousPreviewUrl.href);
        const lifecycle = [];
        const isResourceCompile = response => {
            if (response.provider !== 'theme' || !response.url()) return false;
            const url = new URL(response.url(), page.url());
            return url.pathname.endsWith('/compile-layout') && url.searchParams.get('resource_refresh') === '1';
        };
        const recordResponse = response => { if (isResourceCompile(response)) lifecycle.push('materialization-response'); };
        const recordNavigation = request => {
            if (request.isNavigationRequest() && request.frame() === previewFrame && request.url() !== previousPreviewUrl.href) {
                lifecycle.push('preview-navigation');
            }
        };
        sdk.on('response', recordResponse);
        page.on('request', recordNavigation);
        const materializationResponsePromise = sdk.waitForResponse(isResourceCompile, { timeout: 90000 });
        const previewNavigationPromise = previewFrame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await drawer.locator('#themeBrandResourcesTab').click();
        const [materializationResponse, previewResponse] = await Promise.all([
            materializationResponsePromise, previewNavigationPromise,
        ]);
        sdk.off('response', recordResponse);
        page.off('request', recordNavigation);
        expect(materializationResponse.error, JSON.stringify(materializationResponse.error)).toBeUndefined();
        const requestUrl = new URL(materializationResponse.url(), page.url());
        const requestedContext = JSON.parse(requestUrl.searchParams.get('editor_context'));
        const canonicalScope = previousContext.scope.storage_scope || await page.evaluate(() => window.Weline.Theme.Editor.getLegacyScope());
        expect(requestUrl.searchParams.get('scope')).toBe(canonicalScope);
        for (const key of ['scope', 'area', 'theme_id', 'layout_type', 'layout_option', 'locale', 'target_type', 'target_id']) {
            expect(requestedContext[key], `materialization context ${key}`).toEqual(previousContext[key]);
        }
        const materialization = await materializationResponse.json();
        expect(materialization.success).toBe(true);
        const output = materialization.resource_materialization;
        const selectedVersion = previousPreviewUrl.searchParams.get('version_id');
        expect(output.version_id).toBe(selectedVersion ? Number(selectedVersion) : null);
        expect(output.status).toBe(previousPreviewUrl.searchParams.get('status') || 'draft');
        expect(output.entity_key).toBeTruthy();
        expect(output.artifacts.length).toBeGreaterThan(0);
        expect(output.artifacts.some(artifact => artifact.type === 'page')).toBe(true);
        for (const artifact of output.artifacts) {
            expect(artifact.exists, `materialized ${artifact.type} ${artifact.artifact_id}`).toBe(true);
            expect(artifact.artifact_id).toMatch(/^[a-f0-9]{64}$/);
            expect(path.isAbsolute(artifact.relative_path)).toBe(false);
            const artifactPath = path.resolve(projectRoot, artifact.relative_path);
            expect(artifactPath.startsWith(projectRoot + path.sep)).toBe(true);
            expect(fs.statSync(artifactPath).isFile()).toBe(true);
            expect(crypto.createHash('sha256').update(fs.readFileSync(artifactPath)).digest('hex')).toBe(artifact.artifact_id);
        }
        expect(lifecycle.indexOf('materialization-response')).toBeGreaterThanOrEqual(0);
        expect(lifecycle.indexOf('preview-navigation')).toBeGreaterThan(lifecycle.indexOf('materialization-response'));
        expect(previewResponse.status()).toBe(200);
        const refreshedPreviewUrl = new URL(await preview.getAttribute('src'), page.url());
        expect(refreshedPreviewUrl.searchParams.get('_t')).not.toBe(previousPreviewUrl.searchParams.get('_t'));
        for (const key of ['preview_mode', 'status', 'version_id']) {
            expect(refreshedPreviewUrl.searchParams.get(key), `preview ${key}`).toBe(previousPreviewUrl.searchParams.get(key));
        }
        expect(JSON.parse(refreshedPreviewUrl.searchParams.get('editor_context'))).toEqual(previousContext);
        await testInfo.attach('resource-materialization.json', {
            body: JSON.stringify({ requestContext: requestedContext, output, lifecycle, previewUrl: refreshedPreviewUrl.href }, null, 2),
            contentType: 'application/json',
        });
        await expect(drawer.locator('#themeBrandResourcesPanel')).toBeVisible();
        await expect(drawer.locator('#themeBrandBasePanel')).toBeHidden();
        await expect(save).toBeHidden();
        await expect(inherit).toBeHidden();
        await expect(badge).toBeVisible();
        const embed = drawer.locator('#themeResourceFilesConfig [data-w-config-embed]');
        await expect(embed).toBeVisible();
        await expect(embed).toHaveAttribute('data-module', 'Weline_Theme');
        await expect(embed).toHaveAttribute('data-area', 'frontend');
        await expect(embed).not.toHaveClass(/is-error/);
        const embedScope = await embed.getAttribute('data-storage-scope');
        expect(embedScope).toBeTruthy();
        await expect(embed.locator('[data-config-key]')).toHaveCount(6);
        for (const key of ['css_minify', 'js_minify', 'css_merge', 'js_merge']) {
            const field = embed.locator(`[data-config-key="resource_files/${key}"]`);
            await expect(field).toBeVisible();
            await expect(field).toHaveAttribute('data-field-type', 'select');
            const search = field.locator('.w-search-select-input');
            const dropdownId = await field.locator('.w-search-select-dropdown').getAttribute('id');
            await search.click();
            const dropdown = page.locator(`[id="${dropdownId}"]`);
            await expect(dropdown).toBeVisible();
            expect(await dropdown.locator('.w-search-select-item').evaluateAll(options => options.map(option => option.dataset.value)))
                .toEqual(['auto', 'on', 'off']);
            await drawer.locator('#themeResourceFilesTitle').click();
        }
        for (const key of ['css_merge_start', 'js_merge_start']) {
            const field = embed.locator(`[data-config-key="resource_files/${key}"]`);
            await expect(field).toBeVisible();
            expect(Number(await field.locator('input[type="number"]').inputValue())).toBeGreaterThanOrEqual(1);
        }
        await expect(scope).toHaveValue(initialScope);
        await expect(badge).toHaveText(badgeText);
        await page.screenshot({ path: testInfo.outputPath('resource-config-tab.png'), fullPage: true });
        await drawer.locator('#themeBrandBaseTab').click();
        await expect(save).toBeVisible();
        await expect(inherit).toBeVisible();
        await expect(drawer.locator('#themeBrandResourcesPanel')).toBeHidden();
        await expect(scope).toHaveValue(initialScope);
        await expect(embed).toHaveAttribute('data-storage-scope', embedScope);
        expect(page.url()).toBe(initialUrl);
        await page.screenshot({ path: testInfo.outputPath('basic-config-tab.png'), fullPage: true });
    });
});
