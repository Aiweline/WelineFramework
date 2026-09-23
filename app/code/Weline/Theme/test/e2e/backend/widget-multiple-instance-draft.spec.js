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
    }, { context, changes, summary: 'E2E isolated multi-instance draft' });
    if (observed.diagnostics) {
        await test.info().attach('scoped-write-failure.json', {
            body: JSON.stringify(observed, null, 2), contentType: 'application/json',
        });
        throw new Error(JSON.stringify(observed));
    }
    return observed.result;
}

test('isolated draft supports two FAQ instances, config update, and deletion without publishing', async ({ page }, testInfo) => {
    test.setTimeout(240000);
    const sdk = await observeResourceSdk(page);
    const token = 'multi_' + crypto.randomBytes(6).toString('hex');
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
        const previewHtmlPath = testInfo.outputPath('isolated-preview-before-write.html');
        fs.writeFileSync(previewHtmlPath, await readOnlyPreview.text());
        await testInfo.attach('isolated-preview-before-write', { path: previewHtmlPath, contentType: 'text/html' });
        expect(readOnlyPreview.status(), source.href).toBe(200);
        expect(await readOnlyPreview.text()).not.toMatch(/WLS Runtime Error|Fatal error|scope.*mismatch/i);
        const canvas = page.frameLocator('#previewFrame');
        // body may belong to about:blank while the real preview is still loading.
        // Hanfu homepage's content slot accepts layout-default-content, which FAQ supports;
        // homepage-bottom does not accept FAQ and is not an interchangeable fallback.
        const slot = canvas.locator('[data-wslot="content"][data-wslot-accept~="layout-default-content"], [data-wslot="content"], [data-wslot="layout-default-content"]').first();
        try {
            await expect(slot).toBeAttached({ timeout: 60000 });
            const accepts = (await slot.getAttribute('data-wslot-accept') || '').split(/[\s,]+/);
            expect(accepts, 'Homepage slot must explicitly accept the FAQ support code').toContain('layout-default-content');
        } finally {
            const actualFrame = await (await iframe.elementHandle()).contentFrame();
            const actualHtmlPath = testInfo.outputPath('isolated-iframe-before-write.html');
            fs.writeFileSync(actualHtmlPath, await actualFrame.content());
            await testInfo.attach('isolated-iframe-before-write', { path: actualHtmlPath, contentType: 'text/html' });
            await testInfo.attach('isolated-iframe-slots', { body: JSON.stringify({ url: actualFrame.url(),
                slots: await actualFrame.locator('[data-wslot]').evaluateAll(elements => elements.map(element => ({
                    id: element.getAttribute('data-wslot'), accept: element.getAttribute('data-wslot-accept'),
                }))) }), contentType: 'application/json' });
        }
        const slotId = await slot.getAttribute('data-wslot');
        const ids = [crypto.randomBytes(16).toString('hex'), crypto.randomBytes(16).toString('hex')];
        const nodes = ids.map((node_uid, index) => ({ node_uid, widget_module: 'Weline_Theme', widget_type: 'faq',
            widget_code: 'faq-accordion', area: 'content', slot_id: slotId, sort_order: 1000 + index, is_active: true,
            config: { title: `E2E FAQ ${index}`, search_enabled: true, expand_first: false,
                faqs: [{ question: 'Alpha question', answer: 'Alpha answer' }, { question: 'Beta question', answer: 'Beta answer' }] } }));
        let refreshIndex = 0;
        const refresh = async () => {
            await page.evaluate(() => (window.Weline?.Theme?.Editor || window.ThemeEditor).loadScopedWorkspace('layout', { locale: 'default', skipReconcile: true }));
            await page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first().click();
            await page.locator('#themeBrandBaseTab').click();
            const result = sdk.waitForResponse(response => {
                if (response.provider !== 'theme' || !response.url()) return false;
                const url = new URL(response.url(), page.url());
                return url.pathname.endsWith('/compile-layout') && url.searchParams.get('resource_refresh') === '1';
            });
            const frame = await (await iframe.elementHandle()).contentFrame();
            const navigation = frame.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 });
            navigation.catch(() => {});
            result.catch(() => {});
            await page.locator('#themeBrandResourcesTab').click();
            const materialization = await (await result).json();
            expect(materialization.success, JSON.stringify(materialization)).toBe(true);
            expect(materialization.resource_materialization.artifacts.some(artifact => artifact.type === 'page' && artifact.exists)).toBe(true);
            const navigationResponse = await navigation;
            expect(navigationResponse.status()).toBe(200);
            const index = ++refreshIndex;
            const responsePath = testInfo.outputPath(`multi-response-${index}.html`);
            fs.writeFileSync(responsePath, await navigationResponse.text());
            await testInfo.attach(`multi-response-${index}`, { path: responsePath, contentType: 'text/html' });
            const htmlPath = testInfo.outputPath(`multi-iframe-${index}.html`);
            fs.writeFileSync(htmlPath, await frame.content());
            await testInfo.attach(`multi-iframe-${index}`, { path: htmlPath, contentType: 'text/html' });
            const statePath = testInfo.outputPath(`multi-workspace-${index}.json`);
            fs.writeFileSync(statePath, JSON.stringify({ url: frame.url(), materialization,
                workspace: await request(page, context), expectedNodeIds: ids,
                renderedNodes: await frame.locator('[data-node-uid], [data-layout-id], [data-site-block="faq"]').evaluateAll(elements => elements.map(element => ({
                    uid: element.getAttribute('data-node-uid'), layoutId: element.getAttribute('data-layout-id'),
                    block: element.getAttribute('data-site-block'), text: element.textContent.slice(0, 160),
                }))),
            }, null, 2));
            await testInfo.attach(`multi-workspace-${index}`, { path: statePath, contentType: 'application/json' });
            await page.locator('#btnThemeBrandBasicsClose').click();
        };
        expect((await request(page, context, nodes.map(node => ({ op: 'add_node', node_uid: node.node_uid, path: '/nodes/' + node.node_uid, value: node })))).success).toBe(true);
        await refresh();
        const faq = index => canvas.locator(`[data-node-uid="${ids[index]}"] [data-site-block="faq"], [data-node-uid="${ids[index]}"][data-site-block="faq"]`).first();
        await expect(faq(0)).toBeVisible();
        await expect(faq(1)).toBeVisible();
        await faq(0).locator('[data-faq-search]').fill('Alpha');
        await expect(faq(0).locator('details:visible')).toHaveCount(1);
        await expect(faq(1).locator('details:visible')).toHaveCount(2);
        expect((await request(page, context, [{ op: 'set', path: `/nodes/${ids[0]}/config/title`, value: 'E2E FAQ Updated' }])).success).toBe(true);
        await refresh();
        await expect(faq(0).locator('.sb-title')).toHaveText('E2E FAQ Updated');
        await expect(faq(1).locator('.sb-title')).toHaveText('E2E FAQ 1');
        expect((await request(page, context, [{ op: 'remove_node', node_uid: ids[0], path: `/nodes/${ids[0]}` }])).success).toBe(true);
        await refresh();
        await expect(faq(0)).toHaveCount(0);
        await expect(faq(1)).toBeVisible();
        await faq(1).locator('[data-faq-search]').fill('Beta');
        await expect(faq(1).locator('details:visible')).toHaveCount(1);
        const scoped = fixture('snapshot', { identity: { ...defaultIdentity, scope: prepared.scopes.website } }).scoped;
        for (const workspace of scoped.workspaces) {
            expect(workspace.release_count).toBe(0);
            expect(workspace.published_release_id).toBe(0);
        }
        await page.screenshot({ path: testInfo.outputPath('surviving-faq-instance.png'), fullPage: true });
    } finally {
        expect(fixture('cleanup_scope_hierarchy', ownership).success).toBe(true);
        expect(fixture('snapshot', { identity: defaultIdentity }).scoped).toEqual(beforeDefault);
    }
});
