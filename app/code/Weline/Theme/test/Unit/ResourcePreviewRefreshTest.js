const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../view/statics/js/theme-editor.js'), 'utf8');
function refreshHarness(compile, area = 'frontend', locale = 'en_US', identity = { scope_kind: 'website', website_code: 'default' }, canvasPath = '/de_DE/account/') {
    const start = source.indexOf('    function refreshResourceConfigurationPreview()');
    assert.ok(start >= 0, 'the editor exposes the resource refresh workflow');
    const end = source.indexOf('\n    /**', start);
    const calls = [];
    const typed = { theme_id: 3, area, locale, scope: { identity } };
    let frameSrc = 'https://shop.test' + canvasPath + (canvasPath.includes('?') ? '&' : '?') + 'preview_mode=version&version_id=12&status=draft&editor_context=' + encodeURIComponent(JSON.stringify(typed));
    const frame = { get src() { return frameSrc; }, set src(value) { frameSrc = value; calls.push(['iframe', new URL(value)]); } };
    const context = { resourcePreviewRefresh: Promise.resolve(), state: { themeId: 3 }, elements: { previewFrame: frame },
        buildTypedEditorContext: () => typed,
        flushPendingEditorMutations: async () => calls.push('flush'),
        fetchLayoutSlots: async options => { calls.push(['compile', options]); return compile ? compile() : { success: true, resource_materialization: { template_path: '/layout.phtml' } }; },
        setCanvasSource: async options => calls.push(['iframe', options]), Date, JSON, URL, window: { location: { origin: 'https://shop.test' } },
    };
    vm.createContext(context);
    vm.runInContext(source.slice(source.indexOf('    function storageScopeForIdentity('), source.indexOf('    function restoreScopeSelector(')), context);
    vm.runInContext(source.slice(start, end) + '\nthis.refresh = refreshResourceConfigurationPreview;', context);
    return { calls, refresh: context.refresh };
}
test('resource refresh waits for draft saves, durable compilation, then iframe navigation', async () => {
    const h = refreshHarness(); await h.refresh();
    assert.equal(h.calls[0], 'flush');
    assert.equal(h.calls[1][0], 'compile');
    assert.equal(h.calls[1][1].resource_refresh, true);
    assert.equal(h.calls[1][1].editor_context.theme_id, 3);
    assert.equal(h.calls[2][0], 'iframe');
    for (const [key, value] of Object.entries({ preview_mode: 'version', version_id: '12', status: 'draft' })) {
        assert.equal(h.calls[1][1][key], value);
        assert.equal(h.calls[2][1].searchParams.get(key), value);
    }
});
test('resource refresh retains authenticated HTML canvas transport and does not pass it to JSON compilation', async () => {
    const h = refreshHarness(null, 'frontend', 'en_US', undefined, '/admin/compile-layout?render=html&include_html=1');
    await h.refresh();
    const compile = h.calls.find(call => call[0] === 'compile')[1];
    const frame = h.calls.find(call => call[0] === 'iframe')[1];
    assert.equal(compile.render, undefined);
    assert.equal(frame.pathname, '/admin/compile-layout');
    assert.equal(frame.searchParams.get('render'), 'html');
    assert.equal(frame.searchParams.get('include_html'), '1');
});
test('a failed materialization never navigates the iframe and later refresh can retry', async () => {
    let attempt = 0;
    const h = refreshHarness(() => { if (++attempt === 1) throw new Error('compile failed'); return { success: true, resource_materialization: { template_path: '/layout.phtml' } }; });
    await assert.rejects(h.refresh(), /compile failed/);
    assert.equal(h.calls.some(call => Array.isArray(call) && call[0] === 'iframe'), false);
    await h.refresh();
    assert.equal(h.calls.filter(call => Array.isArray(call) && call[0] === 'iframe').length, 1);
});

test('embedded configuration emits a bubbling success event only after the actual save succeeds', async () => {
    const embed = fs.readFileSync(path.join(__dirname, '../../../SystemConfig/view/statics/js/config-embed.js'), 'utf8');
    const fn = embed.slice(embed.indexOf('    function saveField('), embed.indexOf('    function bindRoot('));
    for (const success of [true, false]) {
        const events = [];
        const root = { dataset: { canUpdate: '1', module: 'Weline_Theme', area: 'backend', storageScope: 'default.__website__.default' }, dispatchEvent: e => events.push(e) };
        const context = { window: { Weline: { Api: { resource: () => ({ setScopedConfig: async () => ({ success }) }) } } },
            readControlValue: c => c.value, setControlValue: () => {}, toast: () => {}, console: { error() {} },
            CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
        };
        vm.createContext(context); vm.runInContext(fn, context);
        context.saveField(root, { dataset: { configKey: 'resource_files/css_merge' } }, { value: 'on', dataset: { lastValue: 'auto' } });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(events.length, success ? 1 : 0);
        if (success) {
            assert.equal(events[0].type, 'weline:config-saved');
            assert.equal(events[0].bubbles, true);
            assert.equal(events[0].detail.scope, root.dataset.storageScope);
        }
    }
});

test('backend refresh retains its iframe route and non-default locale', async () => {
    const h = refreshHarness(null, 'backend', 'de_DE');
    await h.refresh();
    assert.equal(h.calls[1][1].editor_area, 'backend');
    assert.equal(h.calls[1][1].locale, 'de_DE');
    assert.equal(h.calls[2][1].pathname, '/de_DE/account/');
    assert.equal(JSON.parse(h.calls[2][1].searchParams.get('editor_context')).area, 'backend');
    assert.equal(JSON.parse(h.calls[2][1].searchParams.get('editor_context')).locale, 'de_DE');
});

test('the real EditorApi export connects both drawer triggers to resource materialization', async () => {
    const h = refreshHarness();
    const EditorApi = {};
    const bindings = new Proxy({ EditorApi, Object, refreshResourceConfigurationPreview: h.refresh }, {
        has: () => true,
        get: (target, key) => key === Symbol.unscopables ? undefined : (target[key] || (() => {})),
    });
    const exportStart = source.indexOf('    Object.assign(EditorApi, {');
    const exportEnd = source.indexOf('\n    });', exportStart) + '\n    });'.length;
    vm.runInNewContext('with (bindings) { ' + source.slice(exportStart, exportEnd) + ' }', { bindings });
    assert.equal(EditorApi.refreshResourceConfigurationPreview, h.refresh, 'public EditorApi must export the workflow');
    const brand = fs.readFileSync(path.join(__dirname, '../../view/statics/js/theme-brand-basics.js'), 'utf8');
    const apiStart = brand.indexOf('    function editorApi()');
    const apiEnd = brand.indexOf('\n    function ', apiStart + 1);
    const eventStart = brand.indexOf('        const refreshResources = () =>');
    const eventEnd = brand.indexOf("\n        document.getElementById('btnThemeBrandBasics')", eventStart);
    const listeners = new Map();
    vm.runInNewContext(brand.slice(apiStart, apiEnd) + brand.slice(eventStart, eventEnd), {
        window: { Weline: { Theme: { Editor: EditorApi } } },
        document: { getElementById: id => ({ addEventListener: (event, fn) => listeners.set(id + ':' + event, fn) }) },
        toast: () => assert.fail('resource refresh should not fail'),
    });
    listeners.get('themeBrandResourcesTab:click')();
    await new Promise(resolve => setImmediate(resolve));
    listeners.get('themeResourceFilesConfig:weline:config-saved')();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.calls.filter(call => call[0] === 'compile').length, 2);
    assert.equal(h.calls.filter(call => call[0] === 'iframe').length, 2);
});

test('resource compilation sends canonical website, store and channel scopes through the real URL builder', async () => {
    const cases = [
        [{ scope_kind: 'website', website_code: 'default' }, 'default.__website__.default'],
        [{ scope_kind: 'store', website_code: 'default', store_code: 'default' }, 'default.__store__.default'],
        [{ scope_kind: 'channel', website_code: 'default', store_code: 'default', channel_code: 'default' }, 'default.__store__.__channel__'],
        [{ scope_kind: 'store', website_code: 'default', store_code: 'main', store_mode: 'b2b' }, 'default.main.default~b2b'],
    ];
    for (const [identity, expected] of cases) {
        const h = refreshHarness(null, 'frontend', 'en_US', identity);
        await h.refresh();
        const overrides = h.calls[1][1];
        assert.equal(overrides.scope, expected);
        let requestUrl;
        const context = { URL, window: { location: { origin: 'https://shop.test' } }, state: { themeId: 3 }, config: { apiCompileLayout: '/compile-layout' },
            getEffectivePageType: () => 'homepage', getEffectiveLayoutType: () => 'homepage', getEffectiveLayoutOption: () => 'default',
            getEffectiveEditorArea: () => 'frontend', getPreviewLocaleForRequest: () => 'en_US', appendLayoutLockRuntimeParams: () => {},
            getCurrentWindowUrl: () => new URL('https://shop.test/editor'), apiJson: async url => { requestUrl = url; return { success: true }; },
        };
        vm.createContext(context);
        vm.runInContext(source.slice(source.indexOf('    function appendThemeLayoutRuntimeParams('), source.indexOf('    function buildLayoutLockVirtualIdentityPayload(')), context);
        vm.runInContext(source.slice(source.indexOf('    async function fetchLayoutSlots('), source.indexOf('    function normalizeSlotInfoMap(')), context);
        await context.fetchLayoutSlots(overrides);
        assert.equal(new URL(requestUrl).searchParams.get('scope'), expected);
        assert.deepEqual(JSON.parse(new URL(requestUrl).searchParams.get('editor_context')).scope.identity, identity);
    }
});
