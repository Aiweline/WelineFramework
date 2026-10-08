// @weline-e2e-runtime wls
/**
 * Resource-files tab: drifted canvas must realign (no 预览加载失败),
 * then css_minify=on must change delivered storefront widget CSS bundles.
 */
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const {
    test, expect, gotoBackend, gotoFrontend, loginAsAdmin, getActiveTheme,
} = require('../../../../../../../tests/e2e/framework');

const projectRoot = path.resolve(__dirname, '../../../../../../../');
const helper = path.join(__dirname, '../frontend/resource-config-acceptance.php');

test.describe('theme resource files config effectiveness', () => {
    test.setTimeout(480000);

    test('resource tab survives canvas drift and css_minify on takes effect on storefront', async ({ page }, testInfo) => {
        const started = Date.now();
        const stateFile = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'weline-resource-effect-')), 'state.json');
        const config = (action, ...args) => JSON.parse(execFileSync(
            'php',
            [helper, action, stateFile, ...args.map(String)],
            { encoding: 'utf8', timeout: 90000, cwd: projectRoot },
        ));

        page.on('pageerror', (error) => console.log('RESOURCE_EFFECT_PAGEERROR', error.message));

        const theme = getActiveTheme('frontend') || { id: 3, name: 'hanfu' };
        const themeId = Number(theme.id || 3) || 3;
        expect(themeId).toBeGreaterThan(0);
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000, allowPasswordFallback: true });
        const editor = await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${themeId}&page_type=homepage&editor_area=frontend&website_id=0&website_code=default&selected_scope=default.__website__.default`, {
            waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1000,
        });
        expect(editor.status()).toBe(200);

        const preview = page.locator('#previewFrame');
        await expect(preview).toBeAttached({ timeout: 30000 });
        await expect.poll(async () => {
            const src = await preview.getAttribute('src');
            return Boolean(src && src.includes('editor_context'));
        }, { timeout: 90000 }).toBe(true);

        // Runtime API must expose the drift-realign path (compiled/published bundle).
        // Prefer function source over script fetch: modulepreload/CDN query versions can lag.
        await expect.poll(() => page.evaluate(() => {
            const refresh = window.Weline?.Theme?.Editor?.refreshResourceConfigurationPreview;
            const source = typeof refresh === 'function' ? String(refresh) : '';
            return {
                refresh: typeof refresh,
                hasRealign: source.includes('realignCanvas'),
                hasAlignHelper: source.includes('resourcePreviewContextsAlign'),
            };
        }), { timeout: 60000 }).toMatchObject({
            refresh: 'function',
            hasRealign: true,
            hasAlignHelper: true,
        });

        // Poison canvas identity the same way real drift does.
        await page.evaluate(() => {
            const frame = document.getElementById('previewFrame');
            const url = new URL(frame.src, window.location.origin);
            const ctx = JSON.parse(url.searchParams.get('editor_context') || '{}');
            ctx.layout_option = 'e2e-resource-drift-option';
            url.searchParams.set('editor_context', JSON.stringify(ctx));
            frame.src = url.toString();
        });

        await page.locator('#btnThemeBrandBasics:visible, #btnThemeBrandBasicsStrip:visible').first().click();
        const drawer = page.locator('#themeBrandBasicsDrawer');
        await expect(drawer).toBeVisible();
        await drawer.locator('#themeBrandResourcesTab').click();

        // Directly await the public API — proves drift no longer dead-ends as 预览加载失败.
        const refreshOutcome = await page.evaluate(async () => {
            try {
                const materialization = await window.Weline.Theme.Editor.refreshResourceConfigurationPreview();
                return {
                    ok: true,
                    artifacts: Array.isArray(materialization?.artifacts) ? materialization.artifacts.length : 0,
                    entity_key: materialization?.entity_key || null,
                };
            } catch (error) {
                return { ok: false, message: String(error?.message || error) };
            }
        });
        console.log('RESOURCE_EFFECT_REFRESH', JSON.stringify(refreshOutcome));
        expect(refreshOutcome.ok, JSON.stringify(refreshOutcome)).toBe(true);
        expect(refreshOutcome.artifacts).toBeGreaterThan(0);
        expect(refreshOutcome.message || '').not.toContain('预览加载失败');

        const bodyText = await page.locator('body').innerText();
        expect(bodyText).not.toContain('预览加载失败');

        const embed = drawer.locator('#themeResourceFilesConfig [data-w-config-embed]');
        await expect(embed).toBeVisible();
        await expect(embed.locator('[data-config-key="resource_files/css_minify"]')).toBeVisible();

        const snapshot = config('snapshot');
        console.log('RESOURCE_EFFECT_SNAPSHOT', JSON.stringify({ scope: snapshot.scope, stateFile }));
        try {
            const applied = config('apply');
            expect(applied.success !== false).toBeTruthy();

            const storefront = await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
            expect(storefront.status()).toBe(200);
            await testInfo.attach('configured-storefront.html', {
                body: await storefront.body(),
                contentType: 'text/html',
            });

            const delivery = await page.evaluate(() => Array.from(
                document.querySelectorAll('link[data-weline-widget-asset],script[data-weline-widget-asset]'),
            ).map((node) => ({
                url: node.href || node.src,
                kind: node.dataset.welineWidgetAsset,
                type: node.tagName === 'LINK' ? 'css' : 'js',
            })));
            console.log('RESOURCE_EFFECT_DELIVERY', JSON.stringify(delivery.slice(0, 20)));
            const bundles = delivery.filter((item) => String(item.url || '').includes('/widget-assets/'));
            expect(bundles.some((item) => item.type === 'css'), JSON.stringify(delivery)).toBe(true);
            expect(bundles.some((item) => item.type === 'js'), JSON.stringify(delivery)).toBe(true);

            for (const resource of bundles.slice(0, 4)) {
                const asset = await page.request.get(resource.url);
                expect(asset.status(), resource.url).toBe(200);
                const body = await asset.text();
                if (resource.type === 'css') {
                    expect(body.includes('\n\n'), resource.url).toBe(false);
                }
            }
        } finally {
            testInfo.setTimeout(Math.max(480000, Date.now() - started + 180000));
            const restored = config('restore');
            expect(restored.restored).toBe(true);
        }
    });
});
