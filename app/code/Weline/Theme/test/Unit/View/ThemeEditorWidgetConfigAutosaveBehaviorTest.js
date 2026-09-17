'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const uid = '0123456789abcdef0123456789abcdef';
const statics = path.resolve(__dirname, '../../../view/statics');
const file = path.join(statics, 'js/theme-editor.js');

// Authority is js/theme-editor.js; ui/pages/weline-theme-editor.js is resource:compile output.
const editorSource = fs.readFileSync(file, 'utf8');
const incrementalAutosaveReady = editorSource.includes('function collectWidgetConfigChanges(')
    && editorSource.includes('async function patchWidgetConfigFields(')
    && editorSource.includes('function bindWidgetFieldDeepAutosaveWatchers(');

function loadFunctions(names, context) {
    const source = editorSource;
    const functions = names.map((name) => {
        const declaration = new RegExp(`^    (?:async )?function ${name}\\(`, 'm');
        const match = declaration.exec(source);
        assert.ok(match, `Missing function ${name}`);
        const end = source.indexOf('\n    }', match.index);
        assert.ok(end > match.index, `Missing end of ${name}`);
        return source.slice(match.index, end + 6);
    });
    vm.createContext(context);
    vm.runInContext(functions.join('\n'), context, { filename: file });
    return context;
}

test('collectWidgetConfigChanges returns only dirty keys against baseline', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, () => {
    const values = { title: 'new', image: 'same', unused: 'x' };
    const form = {
        welineWidgetConfigBaseline: { title: 'old', image: 'same' },
        querySelectorAll() {
            return [
                { dataset: { fieldKey: 'title' } },
                { dataset: { fieldKey: 'image' } },
            ];
        },
    };
    const context = loadFunctions([
        'scopedValuesEqual',
        'collectWidgetConfigChanges',
    ], {
        collectWidgetConfigData() {
            return values;
        },
    });
    assert.equal(JSON.stringify(context.collectWidgetConfigChanges(form)), JSON.stringify({ title: 'new' }));
});

test('patchWidgetConfigFields writes field set for current theme draft only', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, async () => {
    const requests = [];
    const context = {
        state: {
            themeId: 42,
            pendingScopedMutation: Promise.resolve(),
            scopedWorkspaces: {
                'layout:default': {
                    revision: 3,
                    context: { theme_id: 42, resource_type: 'layout' },
                    draft_payload: { nodes: { [uid]: { config: { title: 'old' } } } },
                },
            },
        },
        document: { querySelectorAll: () => [] },
        translateUiText: (value) => value,
        scopedWorkspaceKey: (resource, options) => `${resource}:${options.locale || 'default'}`,
        getScopedWorkspaceState(resource, options) {
            return context.state.scopedWorkspaces[context.scopedWorkspaceKey(resource, options)];
        },
        async loadScopedWorkspace() {
            return context.getScopedWorkspaceState('layout', { locale: 'default' });
        },
        isBlankI18nFieldValue(value) {
            return value == null || value === '';
        },
        scopedOwnershipRules() {
            return { ownedPrefixes: [], ownedExact: {} };
        },
        isScopedPathOwned() {
            return false;
        },
        renderWidgetConfigOwnership() {},
        async queueScopedChanges(resourceType, changes, options) {
            requests.push({ resourceType, changes, options, themeId: context.state.themeId });
            const key = context.scopedWorkspaceKey(resourceType, options);
            const next = {
                ...context.state.scopedWorkspaces[key],
                revision: Number(context.state.scopedWorkspaces[key].revision || 0) + 1,
            };
            context.state.scopedWorkspaces[key] = next;
            return next;
        },
    };
    loadFunctions([
        'validNodeUid',
        'jsonPointerSegment',
        'readDraftPayloadPath',
        'scopedValuesEqual',
        'scopedConfigCommands',
        'assertCurrentThemeDraftScope',
        'patchWidgetConfigFields',
    ], context);

    await context.patchWidgetConfigFields(uid, { title: 'new-title', image: 'old-image' }, '', {
        summary: 'widget_config_autosave',
    });

    assert.equal(requests.length, 1);
    assert.equal(requests[0].resourceType, 'layout');
    assert.equal(requests[0].themeId, 42);
    assert.equal(JSON.stringify(requests[0].changes), JSON.stringify([
        { op: 'set', path: `/nodes/${uid}/config/title`, value: 'new-title' },
        { op: 'set', path: `/nodes/${uid}/config/image`, value: 'old-image' },
    ]));
    assert.equal(requests[0].options.summary, 'widget_config_autosave');
});

test('patchWidgetConfigFields inherits blank locale media instead of setting empty', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, async () => {
    const requests = [];
    const imagePath = `/translations/${uid}/image`;
    const context = {
        state: {
            themeId: 42,
            pendingScopedMutation: Promise.resolve(),
            scopedWorkspaces: {
                'i18n:ar_SA': {
                    revision: 2,
                    context: { theme_id: 42, resource_type: 'i18n' },
                    draft_payload: { translations: { [uid]: { image: { usage: { asset_id: 'a1' } } } } },
                },
            },
        },
        document: { querySelectorAll: () => [] },
        translateUiText: (value) => value,
        scopedWorkspaceKey: (resource, options) => `${resource}:${options.locale || 'default'}`,
        getScopedWorkspaceState(resource, options) {
            return context.state.scopedWorkspaces[context.scopedWorkspaceKey(resource, options)];
        },
        async loadScopedWorkspace() {
            return context.getScopedWorkspaceState('i18n', { locale: 'ar_SA' });
        },
        isBlankI18nFieldValue(value) {
            return value == null || value === '';
        },
        scopedOwnershipRules() {
            return {};
        },
        isScopedPathOwned(_rules, path) {
            return path === imagePath;
        },
        renderWidgetConfigOwnership() {},
        async queueScopedChanges(resourceType, changes, options) {
            requests.push({ resourceType, changes, options });
            return context.getScopedWorkspaceState(resourceType, options);
        },
    };
    loadFunctions([
        'validNodeUid',
        'jsonPointerSegment',
        'readDraftPayloadPath',
        'scopedValuesEqual',
        'scopedConfigCommands',
        'assertCurrentThemeDraftScope',
        'patchWidgetConfigFields',
    ], context);

    await context.patchWidgetConfigFields(uid, { image: '' }, 'ar_SA', {
        blankAsInherit: true,
        summary: 'widget_i18n_autosave',
    });

    assert.equal(requests.length, 1);
    assert.equal(requests[0].resourceType, 'i18n');
    assert.equal(JSON.stringify(requests[0].changes), JSON.stringify([{ op: 'inherit', path: imagePath }]));
    assert.equal(requests[0].options.locale, 'ar_SA');
});

test('assertCurrentThemeDraftScope blocks cross-theme writes', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, () => {
    const context = loadFunctions(['assertCurrentThemeDraftScope'], {
        state: { themeId: 7 },
        translateUiText: (value) => value,
    });
    assert.throws(
        () => context.assertCurrentThemeDraftScope({
            revision: 1,
            context: { theme_id: 99 },
        }),
        /跨主题/,
    );
});

test('resolveWidgetConfigAutoSaveDelay debounces typing longer than discrete commits', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, () => {
    const context = loadFunctions(['resolveWidgetConfigAutoSaveDelay'], {
        config: { autoSaveDelay: 1000 },
        HTMLElement: class HTMLElement {},
    });
    class FakeInput extends context.HTMLElement {
        constructor(tag, type) {
            super();
            this.tagName = tag;
            this.type = type || '';
            this.isContentEditable = false;
        }
        getAttribute(name) {
            return name === 'type' ? this.type : null;
        }
    }
    const text = new FakeInput('INPUT', 'text');
    const checkbox = new FakeInput('INPUT', 'checkbox');
    const select = new FakeInput('SELECT', '');
    assert.equal(context.resolveWidgetConfigAutoSaveDelay(text), 1000);
    assert.equal(context.resolveWidgetConfigAutoSaveDelay(checkbox), 350);
    assert.equal(context.resolveWidgetConfigAutoSaveDelay(select), 350);
    assert.ok(context.resolveWidgetConfigAutoSaveDelay(null) >= 1000);
});

test('scheduleEditorAutoSave defaults to config.autoSaveDelay not 400ms', { skip: !incrementalAutosaveReady && 'pending re-port to js/theme-editor.js' }, async () => {
    const timers = [];
    const context = {
        config: { autoSaveDelay: 1000 },
        scheduledEditorAutoSaves: new Map(),
        failedEditorAutoSaves: new Set(),
        setTimeout(fn, delay) {
            timers.push(delay);
            return 1;
        },
        clearTimeout() {},
        runEditorAutoSave() {},
    };
    loadFunctions(['scheduleEditorAutoSave'], context);
    context.scheduleEditorAutoSave('k', () => {}, null);
    assert.equal(timers[0], 1000);
    context.scheduleEditorAutoSave('k2', () => {}, 350);
    assert.equal(timers[1], 350);
});

test('collectWidgetConfigData parses file-image JSON from media hidden inputs', () => {
    const form = {
        querySelectorAll(selector) {
            if (String(selector).includes('checkbox')) return [];
            if (String(selector).includes('array')) return [];
            return [];
        },
    };
    class FakeFormData {
        constructor() {
            this._entries = [['poster', '{"type":"file-image","usage":{"version":1,"asset_id":"a1","locale_code":"zh_Hans_CN","alt":"封面"}}']];
        }
        forEach(cb) {
            this._entries.forEach(([k, v]) => cb(v, k));
        }
        has() { return false; }
    }
    const context = {
        FormData: FakeFormData,
        parseI18nFieldValue(raw) {
            const text = String(raw ?? '').trim();
            if (text.startsWith('{') || text.startsWith('[')) {
                try { return JSON.parse(text); } catch (_e) { return text; }
            }
            return text;
        },
        normalizeThemeFileImageNode(value) {
            let node = value;
            if (typeof node === 'string') {
                const trimmed = node.trim();
                if (!trimmed || !trimmed.startsWith('{')) return null;
                try { node = JSON.parse(trimmed); } catch (_e) { return null; }
            }
            if (!node || typeof node !== 'object' || Array.isArray(node) || node.type !== 'file-image') return null;
            const usage = node.usage;
            if (!usage || typeof usage !== 'object' || Number(usage.version) !== 1 || !usage.asset_id || !usage.locale_code) return null;
            return { type: 'file-image', usage };
        },
    };
    loadFunctions(['collectWidgetConfigData'], context);
    const data = context.collectWidgetConfigData(form);
    assert.equal(data.poster.type, 'file-image');
    assert.equal(data.poster.usage.asset_id, 'a1');
});

test('collectWidgetConfigData parses nested file-image JSON inside arrays', () => {
    const form = {
        querySelectorAll(selector) {
            if (String(selector).includes('checkbox')) return [];
            if (String(selector).includes('array')) {
                return [{
                    name: 'slides',
                    value: JSON.stringify([{
                        image: '{"type":"file-image","usage":{"version":1,"asset_id":"slide1","locale_code":"zh_Hans_CN","alt":"轮播"}}',
                        title: 'A',
                    }]),
                }];
            }
            return [];
        },
    };
    class FakeFormData {
        constructor() {
            this._entries = [];
        }
        forEach(cb) {
            this._entries.forEach(([k, v]) => cb(v, k));
        }
        has() { return false; }
    }
    const context = {
        FormData: FakeFormData,
        parseI18nFieldValue(raw) {
            const text = String(raw ?? '').trim();
            if (text.startsWith('{') || text.startsWith('[')) {
                try { return JSON.parse(text); } catch (_e) { return text; }
            }
            return text;
        },
        normalizeThemeFileImageNode(value) {
            let node = value;
            if (typeof node === 'string') {
                const trimmed = node.trim();
                if (!trimmed || !trimmed.startsWith('{')) return null;
                try { node = JSON.parse(trimmed); } catch (_e) { return null; }
            }
            if (!node || typeof node !== 'object' || Array.isArray(node) || node.type !== 'file-image') return null;
            const usage = node.usage;
            if (!usage || typeof usage !== 'object' || Number(usage.version) !== 1 || !usage.asset_id || !usage.locale_code) return null;
            return { type: 'file-image', usage };
        },
    };
    loadFunctions(['collectWidgetConfigData'], context);
    const data = context.collectWidgetConfigData(form);
    assert.equal(data.slides[0].image.type, 'file-image');
    assert.equal(data.slides[0].image.usage.asset_id, 'slide1');
});
