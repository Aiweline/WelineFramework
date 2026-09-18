'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../view/statics/ui/pages/weline-theme-editor.js'), 'utf8');
function productionFunction(name) {
    const declaration = new RegExp(`\\n    (?:async )?function ${name}\\(`).exec(source);
    assert.ok(declaration, `Production function ${name} exists`);
    const tail = source.slice(declaration.index + 1);
    const next = /\n    (?:async )?function \w+\(/.exec(tail);
    assert.ok(next);
    return tail.slice(0, next.index);
}
const plain = (value) => JSON.parse(JSON.stringify(value));

function layoutEditor(initial = {}, validate = async () => ({success: true})) {
    const requests = [];
    const patches = [];
    const values = {title: 'Original title', content: '', showHeader: '1', showFooter: '1', class: '', ...initial};
    const declaredKeys = Object.keys(values);
    const form = {
        values: {...values, csrf: 'form-only-token'},
        addEventListener() {},
        querySelectorAll(selector) {
            if (selector === '.w-param-field[data-field-key]') {
                return declaredKeys.map((key) => ({dataset: {fieldKey: key}}));
            }
            if (selector === 'input[type="checkbox"]') {
                return Object.keys(this.values).filter((key) => typeof this.values[key] === 'boolean')
                    .map((key) => ({name: key, closest: () => null}));
            }
            if (selector.includes('.array-editor-wrapper')) {
                return Object.keys(this.values).filter((key) => Array.isArray(this.values[key]))
                    .map((key) => ({name: key, value: JSON.stringify(this.values[key])}));
            }
            return [];
        },
    };
    class BrowserFormData {
        constructor(target) {
            this.entries = [];
            for (const [key, value] of Object.entries(target.values)) {
                if (value === false) continue;
                if (Array.isArray(value)) value.forEach((item) => this.entries.push([key + '[]', item]));
                else this.entries.push([key, value === true ? 'on' : value]);
            }
        }
        forEach(callback) { this.entries.forEach(([key, value]) => callback(value, key)); }
        has(key) { return this.entries.some(([name]) => name === key); }
    }
    const context = vm.createContext({
        FormData: BrowserFormData,
        state: {themeId: 1}, config: {apiSaveLayoutConfig: '/save-layout-config'},
        getEffectiveEditorArea: () => 'frontend', getActiveConfigLocale: () => 'zh_Hans_CN',
        getEffectiveLayoutType: () => 'homepage', getEffectiveLayoutOption: () => 'default',
        getCurrentWindowParam: () => 'default', getLayoutLockVirtualPayload: () => ({}),
        apiJson: async (_url, options) => { requests.push(JSON.parse(options.body)); return validate(); },
        queueLayoutConfigOwnership: async (config, locale) => { patches.push({config: plain(config), locale}); },
        showToast() {}, fetchLayoutSlots() {}, loadCanvas() {}, console,
    });
    const functions = ['collectWidgetConfigData', 'scopedValuesEqual', 'bindLayoutConfigEvents', 'saveLayoutConfig'];
    for (const name of ['rememberLayoutConfigValues', 'collectLayoutConfigChanges']) {
        if (source.includes(`    function ${name}(`)) functions.push(name);
    }
    vm.runInContext(functions.map(productionFunction).join('\n'), context);
    context.bindLayoutConfigEvents({querySelector: () => form});
    return {context, form, requests, patches};
}

test('editing only title submits only title, without inherited fields or the hidden CSRF control', async () => {
    const {context, form, requests, patches} = layoutEditor();
    form.values.title = '长安汉服 · 水墨衣冠';
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.deepEqual(requests[0].config, {title: '长安汉服 · 水墨衣冠'});
    assert.deepEqual(patches, [{config: {title: '长安汉服 · 水墨衣冠'}, locale: 'zh_Hans_CN'}]);
});

test('saving an unchanged form does not create any scope override', async () => {
    const {context, form, requests, patches} = layoutEditor();
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.equal(requests.length, 0);
    assert.equal(patches.length, 0);
});

test('explicit empty, false and array edits survive while unchanged values remain inherited', async () => {
    const {context, form, requests} = layoutEditor({content: 'Old content', enabled: true, gallery: ['first']});
    form.values.content = '';
    form.values.enabled = false;
    form.values.gallery = [];
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.deepEqual(requests[0].config, {content: '', enabled: false, gallery: []});
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.equal(requests.length, 1, 'A successful save advances only the submitted baseline');
});

test('failed validation leaves the title edit available for retry', async () => {
    let attempts = 0;
    const {context, form, requests, patches} = layoutEditor({}, async () => {
        attempts += 1;
        return attempts === 1 ? {success: false, message: 'Save rejected'} : {success: true};
    });
    form.values.title = 'New title';
    await assert.rejects(context.saveLayoutConfig(form, 'zh_Hans_CN'), /Save rejected/);
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.deepEqual(requests.map((request) => request.config), [{title: 'New title'}, {title: 'New title'}]);
    assert.equal(patches.length, 1);
});

test('a newer edit made during the save remains unsaved until the next request', async () => {
    let release;
    const response = new Promise((resolve) => { release = resolve; });
    const {context, form, requests} = layoutEditor({}, () => response);
    form.values.title = 'First edit';
    const saving = context.saveLayoutConfig(form, 'zh_Hans_CN');
    form.values.title = 'Second edit';
    release({success: true});
    await saving;
    await context.saveLayoutConfig(form, 'zh_Hans_CN');
    assert.deepEqual(requests.map((request) => request.config), [{title: 'First edit'}, {title: 'Second edit'}]);
});
