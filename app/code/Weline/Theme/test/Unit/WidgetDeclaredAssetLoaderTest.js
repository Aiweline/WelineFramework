const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');

test('preview loads external resources sequentially, once per document and at their declared positions', async () => {
    const source = fs.readFileSync(path.join(__dirname, '../../view/statics/js/theme-editor.js'), 'utf8');
    const start = source.indexOf('    const widgetAssetLoads = new WeakMap();');
    const end = source.indexOf('    function mountWidgetPreviewHtml(', start);
    const context = vm.createContext({ URL, console });
    vm.runInContext(source.slice(start, end), context);
    const loaded = [];
    const nodes = [];
    const resolvedUrl = execFileSync('php', ['-r', 'require "app/bootstrap.php"; echo Weline\\Framework\\Manager\\ObjectManager::getInstance(Weline\\Framework\\View\\Template::class)->fetchTagSource(Weline\\Framework\\View\\Data\\DataInterface::dir_type_STATICS, "Weline_Theme::js/widgets/widget-assets-runtime.js");'], { cwd: path.resolve(__dirname, '../../../../../..'), encoding: 'utf8' }).trim();
    assert.match(resolvedUrl, /widget-assets-runtime\.js/);
    const descriptors = [
        { url: '/static/theme/styles.css', type: 'css', position: 'head' },
        { url: resolvedUrl, type: 'js', position: 'head' },
        { url: '/static/theme/widget.js', type: 'js', position: 'footer' },
        { url: '/static/theme/tail.js', type: 'js', position: 'body' },
        { url: 'https://configured-cdn.test/module/runtime.js', type: 'js' },
    ];
    const target = position => ({ appendChild(node) {
        const file = node.href || node.src;
        nodes.push(node);
        loaded.push(['start', position, file]);
        setImmediate(() => { loaded.push(['ready', position, file]); node.onload(); });
    }});
    const doc = {
        baseURI: 'https://shop.test/page',
        head: target('head'), body: target('body'), footer: target('footer'),
        createElement(tag) {
            if (tag === 'template') return { content: { querySelectorAll() { return [{ getAttribute() { return JSON.stringify(descriptors); } }]; } } };
            return { setAttribute() {} };
        },
        querySelector() { return this.footer; },
        querySelectorAll() { return nodes; },
    };
    await context.loadWidgetDeclaredAssets('fragment', doc);
    assert.deepEqual(loaded.map(item => item[0]), ['start', 'ready', 'start', 'ready', 'start', 'ready', 'start', 'ready', 'start', 'ready']);
    assert.deepEqual(loaded.filter(item => item[0] === 'start').map(item => item[1]), ['head', 'head', 'footer', 'body', 'head']);
    assert.equal(nodes.length, 5);
    await context.loadWidgetDeclaredAssets('same fragment again', doc);
    assert.equal(nodes.length, 5);
});

test('the actual iframe update entry loads resources for existing and newly inserted widgets', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../view/statics/js/theme-editor.js'), 'utf8');
    const start = source.indexOf('    function updateWidgetPreviewInIframe(');
    const end = source.indexOf('    function switchPreviewView(', start);
    for (const isNew of [false, true]) {
        const loads = [];
        const inserted = [];
        const content = {};
        const wrapper = { setAttribute() {}, classList: { add() {}, remove() {} } };
        const slot = { getAttribute() { return 'false'; }, querySelector() { return null; } };
        const widget = { querySelector() { return content; }, classList: { add() {}, remove() {} } };
        const doc = { body: {}, createElement() { return wrapper; }, querySelector(selector) {
            return selector === 'widget' ? (isNew ? null : widget) : slot;
        } };
        const context = vm.createContext({
            console: { log() {}, warn(message) { throw new Error(message); } },
            elements: { previewFrame: { contentDocument: doc } },
            state: { previewArrayItemIndexByLayout: {} },
            sanitizeHtmlForEditorPreview: value => value,
            loadWidgetDeclaredAssets: (html, document) => { loads.push([html, document]); return Promise.resolve(); },
            dataLayoutIdSelector: () => 'widget', dataAttributeSelector: () => 'slot', cssIdentifier: value => value,
            activateWidgetPreviewArrayItem() {}, setTimeout() {}, injectStylesIntoIframe() {},
            applyWidgetIdentityToElement() {}, isExclusiveSlot: () => false, generateWidgetHoverActionsHtml: () => '',
            insertWidgetWrapperAtSortOrder: (container, node) => inserted.push(node),
            bindWidgetActionEvents() {}, setDraggableOnSlotWidgets() {}, loadCanvas() { throw new Error('Unexpected canvas reload'); },
        });
        vm.runInContext(source.slice(start, end), context);
        context.updateWidgetPreviewInIframe('abc', '<section>preview</section>', isNew, 'content');
        assert.equal(loads.length, 1);
        assert.equal(loads[0][1], doc);
        if (isNew) assert.match(inserted[0].innerHTML, /<section>preview<\/section>/);
        else assert.equal(content.innerHTML, '<section>preview</section>');
    }
});
