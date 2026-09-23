const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../view/statics/js/theme-editor.js'), 'utf8');
function build(type, option) {
    const context = { URL, Date, JSON, window: { location: { origin: 'https://shop.test' } },
        config: { apiCompileLayout: '/admin/theme/backend/theme-editor/compile-layout' },
        state: { themeId: 3, previewStatus: 'draft', layoutIdentity: {} },
        getCurrentWindowUrl: () => new URL('https://shop.test/admin/editor?version_id=12'),
        getEffectiveLayoutOption: () => option, getEffectiveLayoutType: () => type,
        getEffectiveEditorArea: () => 'frontend', resolveCanvasStorefrontPath: () => type === 'homepage' ? '' : type,
        getPreviewLocaleForRequest: () => 'de_DE', buildCanvasLocalizedStorefrontPath: route => 'de_DE/' + route.replace(/^\//, ''),
        normalizeInteractionMode: value => value || 'edit', normalizeLayoutOptionValue: value => value,
        appendThemeLayoutRuntimeParams: url => url.searchParams.set('scope', 'shop.__website__.default'),
        stripCanvasVisitorLanguageQuery: url => url.searchParams.delete('locale'),
        splitCanvasStorefrontLocalization: route => ({ business: route }),
        buildTypedEditorContext: (_resource, values) => ({ ...values, scope: { storage_scope: 'shop.__website__.default' } }),
    };
    vm.createContext(context);
    const start = source.indexOf('    function buildCanvasStorefrontPreviewUrl(');
    vm.runInContext(source.slice(start, source.indexOf('\n    /**', start)), context);
    return new URL(context.buildCanvasStorefrontPreviewUrl({ status: 'published', preview_mode: 'version' }));
}
test('blank/full uses the authenticated HTML compiler and keeps selected identity', () => {
    const url = build('blank', 'full');
    assert.equal(url.pathname, '/de_DE/admin/theme/backend/theme-editor/compile-layout');
    for (const [key, value] of Object.entries({ render: 'html', include_html: '1', layout_type: 'blank', layout_option: 'full', locale: 'de_DE', scope: 'shop.__website__.default', status: 'published', version_id: '12', preview_mode: 'version' })) {
        assert.equal(url.searchParams.get(key), value, key);
    }
    const typed = JSON.parse(url.searchParams.get('editor_context'));
    assert.equal(typed.layout_type, 'blank');
    assert.equal(typed.locale, 'de_DE');
});
test('ordinary layouts keep their localized public route', () => {
    const url = build('homepage', 'default');
    assert.equal(url.pathname, '/de_DE/');
    assert.equal(url.searchParams.has('render'), false);
});
