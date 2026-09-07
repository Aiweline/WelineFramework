'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const uid = '0123456789abcdef0123456789abcdef';
const statics = path.resolve(__dirname, '../../../view/statics');

// Execute the shipped functions with only network and browser boundaries replaced.
function loadFunctions(file, names, context) {
    const source = fs.readFileSync(file, 'utf8');
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

function element(dataset = {}) {
    return { dataset, getAttribute() { return null; }, setAttribute() {}, removeAttribute() {} };
}

async function completes(promise) {
    let timer;
    try {
        return await Promise.race([
            promise,
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error('Widget save queue did not complete')), 500);
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}

function saveContext(file, { rejectTitle = '', holdOwnership = null } = {}) {
    const requests = [];
    const context = {
        state: { pendingScopedMutation: Promise.resolve(), scopedWorkspaces: {} },
        config: { apiScopedWorkspace: '/scope' },
        document: { querySelectorAll: () => [] },
        translateUiText: (value) => value,
        getWidgetConfigSaveUrl: () => '/save-widget-config',
        scopedWorkspaceKey: (resource, options) => `${resource}:${options.locale || 'default'}`,
        buildTypedEditorContext: (resource, options) => ({ resource_type: resource, locale: options.locale || 'default' }),
        renderWidgetConfigOwnership() {},
        renderThemeBindingOwnership() {},
        renderScopedConflictPanel() {},
        async loadScopedWorkspace(resource, options) {
            const workspace = { revision: 0, draft_payload: {} };
            context.state.scopedWorkspaces[context.scopedWorkspaceKey(resource, options)] = workspace;
            return workspace;
        },
        getScopedWorkspaceState(resource, options) {
            return context.state.scopedWorkspaces[context.scopedWorkspaceKey(resource, options)];
        },
        async syncLayoutWorkspaceAfterServerMutation(result) {
            return result.scoped_workspace;
        },
        async apiJson(url, options) {
            const body = JSON.parse(options.body);
            requests.push({ url, body });
            if (url === '/save-widget-config') {
                if (body.config.title === rejectTitle) return { success: false, message: 'Save rejected' };
                return { success: true, node_uid: body.node_uid, config: body.config };
            }
            if (holdOwnership) await holdOwnership;
            return { success: true, data: { revision: body.expected_revision + 1 } };
        },
    };
    loadFunctions(file, [
        'validNodeUid', 'jsonPointerSegment', 'readDraftPayloadPath', 'scopedValuesEqual',
        'scopedConfigCommands', 'queueScopedChanges', 'queueWidgetConfigOwnership',
        'finalizeWidgetConfigSave', 'requestSaveWidgetConfig',
    ], context);
    return { context, requests, save: (title, locale = '') => context.requestSaveWidgetConfig({ node_uid: uid, config: { title }, locale }, locale) };
}

for (const relative of ['js/theme-editor.js', 'ui/pages/weline-theme-editor.js']) {
    const file = path.join(statics, relative);

    test(`${relative}: selection retains UID and legacy identities for parameter autosave`, async () => {
        for (const identity of ['123', uid]) {
            const form = element();
            let renderIdentity;
            const context = loadFunctions(file, [
                'validNodeUid', 'resolveDomWidgetIdentity', 'applyWidgetIdentityToElement',
                'loadWidgetConfig', 'renderConfigFormWithBackend',
            ], {
                console,
                elements: { configContent: { querySelector: () => form } },
                loadSavedWidgetConfig: async () => ({ params: { title: {} }, config: { title: 'Example' } }),
                getWidgetElementMeta: () => ({ name: 'Example' }),
                widgetTypeIconName: () => '', iconSvg: () => '', escapeHtml: (value) => value,
                generateWidgetConfigForm: async (value) => { renderIdentity = value; return '<form></form>'; },
                bindAccordionFormEvents() {}, bindParamSearch() {}, bindConfigLangSwitcher() {},
            });
            await context.loadWidgetConfig(element(identity === uid ? { nodeUid: identity } : { layoutId: identity }));
            assert.equal(renderIdentity, identity, 'Backend form rendering must receive the selected identity');
            assert.equal(context.resolveDomWidgetIdentity(form).layoutId, identity, 'Autosave must find the selected identity on the rendered form');
        }
    });

    test(`${relative}: default and localized saves finish in order including ownership persistence`, async () => {
        let releaseOwnership;
        const holdOwnership = new Promise((resolve) => { releaseOwnership = resolve; });
        const { context, requests, save } = saveContext(file, { holdOwnership });
        await completes(save('default'));
        const localized = save('translated', 'zh_CN');
        const following = save('following');
        await new Promise((resolve) => setImmediate(resolve));
        assert.deepEqual(requests.map((request) => request.url), ['/save-widget-config', '/save-widget-config', '/scope']);
        assert.deepEqual(requests[2].body.changes, [{ op: 'set', path: `/translations/${uid}/title`, value: 'translated' }]);
        releaseOwnership();
        await completes(Promise.all([localized, following]));
        await completes(save('translated again', 'zh_CN'));
        await completes(context.state.pendingScopedMutation);
        assert.deepEqual(requests.filter((request) => request.url === '/save-widget-config').map((request) => request.body.config.title), ['default', 'translated', 'following', 'translated again']);
        assert.equal(context.state.scopedWorkspaces['i18n:zh_CN'].revision, 2);
    });

    test(`${relative}: rejected saves do not prevent subsequent localized saves`, async () => {
        const { save, context } = saveContext(file, { rejectTitle: 'rejected' });
        const first = save('rejected', 'zh_CN');
        const second = save('recovered', 'zh_CN');
        await assert.rejects(first, /Save rejected/);
        await completes(second);
        assert.equal(context.state.scopedWorkspaces['i18n:zh_CN'].revision, 1);
    });
}
