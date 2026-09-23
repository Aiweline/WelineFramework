// @weline-e2e-runtime wls
// @weline-e2e-transport direct
const path = require('path');
const crypto = require('crypto');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { observeResourceSdk } = require('./resource-sdk-observer');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');
const root = path.resolve(__dirname, '../../../../../../..');
const fixtureScript = path.join(__dirname, 'theme-editor-fixture.php');

function fixture(action, payload) {
    return JSON.parse(execFileSync('php', [fixtureScript], {
        cwd: root, input: JSON.stringify({ action, theme_id: 3, page_type: 'homepage', ...payload }),
        encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
    }));
}

async function request(page, context, changes) {
    const observed = await page.evaluate(async ({ context, changes, summary }) => {
        const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
        // Join the real editor queue: initialization installs required widgets here.
        // Reading before that task completes produces a stale expected_revision.
        const run = async () => {
        const url = document.querySelector('#themeEditor').dataset.apiBase + '/scoped-workspace';
        const read = () => editor.apiJson(url + '?' + new URLSearchParams({
            editor_context: JSON.stringify(context), _t: String(Date.now()),
        }), { keepBusinessResult: true });
        const current = await read();
        if (!current.success || !changes) return { result: current };
        const payload = { editor_context: context, expected_revision: Number(current.data.revision),
            expected_parent_release_id: current.data.expected_parent_release_id ?? null,
            changes, summary };
        let result;
        try {
            result = await editor.apiJson(url, { method: 'POST', keepBusinessResult: true,
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        } catch (error) {
            result = { success: false, message: error.message };
        }
        if (result.success) return { result };
        // A fresh read diagnoses an actual conflict; never retry the write.
        let after;
        try { after = await read(); } catch (error) { after = { error: error.message }; }
        return { result, diagnostics: { url, current, payload, after } };
        };
        const pending = Promise.resolve(editor.state.pendingScopedMutation).then(run);
        editor.state.pendingScopedMutation = pending.catch(() => undefined);
        return pending;
    }, { context, changes, summary: 'E2E isolated resource placement draft' });
    if (observed.diagnostics) {
        await test.info().attach('scoped-write-failure.json', {
            body: JSON.stringify(observed, null, 2), contentType: 'application/json',
        });
        throw new Error(JSON.stringify(observed));
    }
    return observed.result;
}

test('isolated draft places real resources in footer and body and promotes layout', async ({ page }, testInfo) => {
    test.setTimeout(240000);
    const sdk = await observeResourceSdk(page);
    const token = 'positions_' + crypto.randomBytes(6).toString('hex');
    const ownership = { token };
    const defaultIdentity = { scope: 'default.default.default', layout_option: 'default', target_type: 'global', target_id: 0 };
    const beforeDefault = fixture('snapshot', { identity: defaultIdentity }).scoped;
    try {
    const prepared = fixture('prepare_scope_hierarchy', ownership);
    expect(prepared.success).toBe(true);
    expect(prepared.identities.website.website_code).toMatch(/^e2e-theme-scope-/);
    const context = { scope: { identity: prepared.identities.website }, area: 'frontend', resource_type: 'layout',
        theme_id: 3, layout_type: 'homepage', layout_option: 'default', locale: 'default', target_type: 'global', target_id: 0 };
        await loginAsAdmin(page, { timeout: 60000, allowPasswordFallback: true });
        const response = await gotoBackend(page, 'theme/backend/theme-editor/index?' + new URLSearchParams({
            theme_id: '3', editor_area: 'frontend', page_type: 'homepage', scope: prepared.scopes.website,
        }), { waitUntil: 'domcontentloaded', timeout: 60000 });
        expect(response.status()).toBe(200);
        await page.waitForFunction(() => Boolean((window.Weline?.Theme?.Editor || window.ThemeEditor)?.apiJson));
        expect(await page.evaluate(() => (window.Weline?.Theme?.Editor || window.ThemeEditor).getScopeIdentity()))
            .toEqual(prepared.identities.website);
        const iframe = page.locator('#previewFrame');
        const source = new URL(await iframe.getAttribute('src'), page.url());
        const previewContext = JSON.parse(source.searchParams.get('editor_context'));
        expect(previewContext.scope.identity).toEqual(prepared.identities.website);
        // Prove this isolated identity can render the controlled real storefront before any draft writes.
        const readOnlyPreview = await page.request.get(source.href);
        await testInfo.attach('isolated-preview-before-write.txt', { body: await readOnlyPreview.text(), contentType: 'text/plain' });
        expect(readOnlyPreview.status(), source.href).toBe(200);
        expect(await readOnlyPreview.text()).not.toMatch(/WLS Runtime Error|Fatal error|scope.*mismatch/i);
        const canvas = page.frameLocator('#previewFrame');
        await expect(canvas.locator('body')).toBeVisible();
        const contentSlot = canvas.locator('[data-wslot="homepage-bottom"], [data-wslot="content"], [data-wslot-position="content"][data-wslot]').first();
        const headerSlot = canvas.locator('[data-wslot][data-wslot-accept*="layout-header-actions"], [data-wslot][data-wslot-accept*="layout-global-header-actions"]').first();
        await expect(contentSlot).toBeAttached();
        await expect(headerSlot).toBeAttached();
        const contentSlotId = await contentSlot.getAttribute('data-wslot');
        const headerSlotId = await headerSlot.getAttribute('data-wslot');
        const assets = {
            head: 'Weline_Theme::css/widgets/widget-data-form-default.css',
            headJs: 'Weline_Theme::js/widgets/widget-data-form-default-0.js',
            footerJs: 'Weline_Theme::js/widgets/widget-data-form-default-1.js',
            bodyJs: 'Weline_Theme::js/widgets/widget-data-list-default-0.js',
            endBodyJs: 'Weline_Theme::js/widgets/widget-social-social-share-default-0.js',
            promotedJs: 'Weline_Theme::js/widgets/widget-product-cross-sell-default-0.js',
            footer: 'Weline_Theme::css/widgets/widget-sidebar-sidebar-menu-default.css',
            body: 'Weline_Theme::css/widgets/widget-banner-ad-banner-default.css',
            endBody: 'Weline_Theme::css/widgets/widget-content-image-text-default.css',
            promoted: 'Weline_Theme::css/widgets/widget-product-cross-sell-default.css',
        };
        // These existing JS files only register anchor initializers. No corresponding
        // anchors are added, so the placement probe does not start business actions.
        const positionFor = name => name.startsWith('head') ? 'head' : name.startsWith('footer') || name.startsWith('promoted') ? 'footer' : name.startsWith('endBody') ? 'end-body' : 'body';
        const sourceFor = (kind, asset) => kind === 'headJs' ? 'Weline_Theme::js/widgets/widget-assets-runtime.js,' + asset : asset;
        const nodes = Object.entries(assets).map(([kind, asset], index) => ({
            node_uid: crypto.randomBytes(16).toString('hex'), widget_module: 'Weline_Theme',
            widget_code: 'text-block', widget_type: 'content', area: 'content', slot_id: contentSlotId,
            sort_order: 1100 + index, is_active: true,
            source: sourceFor(kind, asset), ...(kind.startsWith('head') ? {} : { source_position: positionFor(kind) }),
            config: { content: `Resource placement ${kind}`, _source: sourceFor(kind, asset),
                ...(kind.startsWith('head') ? {} : { _source_position: positionFor(kind) }) },
        }));
        const layoutSources = ['Weline_Theme::js/widgets/widget-assets-runtime.js', assets.promoted, assets.promotedJs].join(',');
        nodes.push({ node_uid: crypto.randomBytes(16).toString('hex'), widget_module: 'Weline_Theme',
            widget_code: 'account', widget_type: 'header', area: 'header', slot_id: headerSlotId,
            sort_order: 1200, is_active: true, layout_source: layoutSources,
            config: { _layout_source: layoutSources } });
        let refreshIndex = 0;
        const refresh = async () => {
            await page.evaluate(() => (window.Weline?.Theme?.Editor || window.ThemeEditor).loadScopedWorkspace('layout', { locale: 'default', skipReconcile: true }));
            await page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first().click();
            await page.locator('#themeBrandBaseTab').click();
            const result = sdk.waitForResponse(response => response.provider === 'theme' && response.url()
                && new URL(response.url(), page.url()).searchParams.get('resource_refresh') === '1');
            const frame = await (await iframe.elementHandle()).contentFrame();
            const navigation = frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
            // Attach handlers immediately; keep the original promises rejecting when awaited.
            navigation.catch(() => {});
            result.catch(() => {});
            await page.locator('#themeBrandResourcesTab').click();
            const materialization = await (await result).json();
            const index = ++refreshIndex;
            await testInfo.attach(`materialization-${index}.json`, { body: JSON.stringify(materialization, null, 2), contentType: 'application/json' });
            expect(materialization.success, JSON.stringify(materialization)).toBe(true);
            const response = await navigation;
            expect(response.status()).toBe(200);
            const htmlPath = testInfo.outputPath(`positions-iframe-${index}.html`);
            fs.writeFileSync(htmlPath, await frame.content());
            await testInfo.attach(`positions-iframe-${index}`, { path: htmlPath, contentType: 'text/html' });
            await testInfo.attach(`positions-workspace-${index}.json`, { body: JSON.stringify(await request(page, context), null, 2), contentType: 'application/json' });
            await page.locator('#btnThemeBrandBasicsClose').click();
            return response;
        };
        const layoutNode = nodes.pop();
        expect((await request(page, context, nodes.map(node => ({ op: 'add_node', node_uid: node.node_uid, path: '/nodes/' + node.node_uid, value: node })))).success).toBe(true);
        await refresh();
        for (const asset of [assets.promoted, assets.promotedJs]) {
            const initiallyNormal = canvas.locator(`[data-weline-module-source="${asset}"]`);
            await expect(initiallyNormal).toHaveCount(1);
            await expect(initiallyNormal).toHaveAttribute('data-weline-widget-asset', 'source');
            expect(await initiallyNormal.evaluate(node => node.parentElement.tagName.toLowerCase())).toBe('footer');
        }
        expect((await request(page, context, [{ op: 'add_node', node_uid: layoutNode.node_uid, path: '/nodes/' + layoutNode.node_uid, value: layoutNode }])).success).toBe(true);
        const withFooterResponse = await refresh();
        await expect(canvas.locator('footer').last()).toBeAttached();
        const assertPositions = async (hasFooter) => {
            const frame = await (await iframe.elementHandle()).contentFrame();
            const observed = await frame.evaluate(assets => Object.fromEntries(Object.entries(assets).map(([name, moduleSource]) => {
                const found = Array.from(document.querySelectorAll('[data-weline-widget-asset]'))
                    .filter(node => node.getAttribute('data-weline-module-source') === moduleSource);
                return [name, found.map(node => ({ kind: node.dataset.welineWidgetAsset,
                    position: node.dataset.welineSourcePosition, inHead: document.head.contains(node),
                    tag: node.tagName.toLowerCase(), defer: node.hasAttribute('defer'),
                    parent: node.parentElement.tagName.toLowerCase(), inLastFooter: document.querySelector('footer:last-of-type')?.contains(node) || false,
                    url: node.href || node.src }))];
            })), assets);
            for (const [name, entries] of Object.entries(observed)) {
                expect(entries, name).toHaveLength(1);
                const isLayout = name.startsWith('promoted');
                const isHead = isLayout || name.startsWith('head');
                expect(entries[0].inHead, name).toBe(isHead);
                expect(entries[0].kind, name).toBe(isLayout ? 'layout' : 'source');
                expect(entries[0].parent, name).toBe(isHead ? 'head' : name.startsWith('footer') && hasFooter ? 'footer' : 'body');
                expect(entries[0].position, name).toBe(isLayout ? 'head' : positionFor(name).replace('end-body', 'body'));
                if (name.endsWith('Js')) {
                    expect(entries[0].tag).toBe('script');
                    expect(entries[0].defer).toBe(true);
                }
                const assetResponse = await page.request.get(entries[0].url);
                expect(assetResponse.status(), entries[0].url).toBe(200);
            }
            await testInfo.attach(`positions-${hasFooter ? 'footer' : 'fallback'}.json`, { body: JSON.stringify(observed, null, 2), contentType: 'application/json' });
        };
        await assertPositions(true);
        await testInfo.attach('with-footer.html', { body: await withFooterResponse.text(), contentType: 'text/html' });
        // showFooter=false does not establish an HTML document without footer tags:
        // homepage components contain their own footer elements. Use blank/full below.
    } finally {
        expect(fixture('cleanup_scope_hierarchy', ownership).success).toBe(true);
        expect(fixture('snapshot', { identity: defaultIdentity }).scoped).toEqual(beforeDefault);
    }
});


test('blank full isolated draft falls back from footer to body for CSS and JS', async ({ page }, testInfo) => {
    test.setTimeout(240000);
    const sdk = await observeResourceSdk(page);
    const ownership = { token: 'nofooter_' + crypto.randomBytes(6).toString('hex') };
    const defaultIdentity = { scope: 'default.default.default', layout_option: 'full', target_type: 'global', target_id: 0 };
    const beforeDefault = fixture('snapshot', { page_type: 'blank', identity: defaultIdentity }).scoped;
    try {
        const prepared = fixture('prepare_scope_hierarchy', ownership);
        expect(prepared.success).toBe(true);
        const context = { scope: { identity: prepared.identities.website }, area: 'frontend', resource_type: 'layout',
            theme_id: 3, layout_type: 'blank', layout_option: 'full', locale: 'default', target_type: 'global', target_id: 0 };
        await loginAsAdmin(page, { timeout: 60000, allowPasswordFallback: true });
        const response = await gotoBackend(page, 'theme/backend/theme-editor/index?' + new URLSearchParams({
            theme_id: '3', editor_area: 'frontend', page_type: 'blank', layout_option: 'full', scope: prepared.scopes.website,
        }), { waitUntil: 'domcontentloaded', timeout: 60000 });
        expect(response.status()).toBe(200);
        const iframe = page.locator('#previewFrame');
        await expect(iframe).toHaveAttribute('src', /editor_context=/);
        const source = new URL(await iframe.getAttribute('src'), page.url());
        const previewContext = JSON.parse(source.searchParams.get('editor_context'));
        expect(previewContext).toMatchObject(context);
        const canvas = page.frameLocator('#previewFrame');
        const beforeResponse = await page.request.get(source.href);
        const beforeResponsePath = testInfo.outputPath('blank-full-before-response.html');
        fs.writeFileSync(beforeResponsePath, await beforeResponse.text());
        await testInfo.attach('blank-full-before-response', { path: beforeResponsePath, contentType: 'text/html' });
        try {
            expect(beforeResponse.status(), source.href).toBe(200);
            await expect(canvas.locator('#blank-full-content')).toBeVisible({ timeout: 60000 });
        } finally {
            const beforeFrame = await (await iframe.elementHandle()).contentFrame();
            const beforePath = testInfo.outputPath('blank-full-before-iframe.html');
            fs.writeFileSync(beforePath, await beforeFrame.content());
            await testInfo.attach('blank-full-before-iframe', { path: beforePath, contentType: 'text/html' });
            const contextPath = testInfo.outputPath('blank-full-before-context.json');
            fs.writeFileSync(contextPath, JSON.stringify({ source: source.href, responseUrl: beforeResponse.url(),
                status: beforeResponse.status(), actualFrameUrl: beforeFrame.url(), previewContext }, null, 2));
            await testInfo.attach('blank-full-before-context', { path: contextPath, contentType: 'application/json' });
        }
        await expect(canvas.locator('footer')).toHaveCount(0);
        const assets = ['Weline_Theme::css/widgets/widget-sidebar-sidebar-menu-default.css',
            'Weline_Theme::js/widgets/widget-data-form-default-1.js'];
        const nodes = assets.map((asset, index) => ({ node_uid: crypto.randomBytes(16).toString('hex'),
            widget_module: 'Weline_Theme', widget_type: 'content', widget_code: 'text-block', area: 'content',
            slot_id: 'content', sort_order: 100 + index, is_active: true, source: asset, source_position: 'footer',
            config: { content: `Footer fallback ${index}`, _source: asset, _source_position: 'footer' } }));
        expect((await request(page, context, nodes.map(node => ({ op: 'add_node', node_uid: node.node_uid,
            path: '/nodes/' + node.node_uid, value: node })))).success).toBe(true);
        await page.evaluate(() => (window.Weline?.Theme?.Editor || window.ThemeEditor).loadScopedWorkspace('layout', { locale: 'default', skipReconcile: true }));
        await page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first().click();
        await page.locator('#themeBrandBaseTab').click();
        const result = sdk.waitForResponse(response => response.provider === 'theme' && response.url()
            && new URL(response.url(), page.url()).searchParams.get('resource_refresh') === '1');
        const frame = await (await iframe.elementHandle()).contentFrame();
        const navigation = frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
        result.catch(() => {});
        navigation.catch(() => {});
        await page.locator('#themeBrandResourcesTab').click();
        const materialization = await (await result).json();
        expect(materialization.success, JSON.stringify(materialization)).toBe(true);
        expect(materialization.resource_materialization.artifacts.some(artifact => artifact.type === 'page' && artifact.exists)).toBe(true);
        expect((await navigation).status()).toBe(200);
        const actualContext = JSON.parse(new URL(frame.url()).searchParams.get('editor_context'));
        expect(actualContext).toMatchObject(context);
        const htmlPath = testInfo.outputPath('blank-full-footer-fallback.html');
        fs.writeFileSync(htmlPath, await frame.content());
        await testInfo.attach('blank-full-footer-fallback', { path: htmlPath, contentType: 'text/html' });
        await testInfo.attach('blank-full-state', { body: JSON.stringify({ url: frame.url(), materialization,
            workspace: await request(page, context) }, null, 2), contentType: 'application/json' });
        await expect(canvas.locator('footer')).toHaveCount(0);
        for (const asset of assets) {
            const element = canvas.locator(`[data-weline-module-source="${asset}"]`);
            await expect(element).toHaveCount(1);
            await expect(element).toHaveAttribute('data-weline-widget-asset', 'source');
            await expect(element).toHaveAttribute('data-weline-source-position', 'footer');
            const observed = await element.evaluate(node => ({ parent: node.parentElement.tagName.toLowerCase(),
                url: node.href || node.src, tag: node.tagName.toLowerCase(), defer: node.hasAttribute('defer') }));
            expect(observed.parent).toBe('body');
            if (asset.endsWith('.js')) { expect(observed.tag).toBe('script'); expect(observed.defer).toBe(true); }
            expect((await page.request.get(observed.url)).status(), observed.url).toBe(200);
        }
    } finally {
        expect(fixture('cleanup_scope_hierarchy', ownership).success).toBe(true);
        expect(fixture('snapshot', { page_type: 'blank', identity: defaultIdentity }).scoped).toEqual(beforeDefault);
    }
});
