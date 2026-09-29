'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

for (const relative of ['js/theme-editor.js', 'ui/pages/weline-theme-editor.js']) {
    test(`${relative}: reading a layout workspace does not queue a default-install mutation`, async () => {
        const file = path.join(__dirname, '../../view/statics', relative);
        const source = fs.readFileSync(file, 'utf8');
        const start = source.indexOf('    async function loadScopedWorkspace(');
        assert.ok(start >= 0);
        const end = source.indexOf('\n    }', start);
        const requests = [];
        let installs = 0;
        const snapshot = { revision: 6, content_revision: 8, draft_payload: { nodes: { clear: { widget_code: '__no_widget_placements__' } } } };
        const context = {
            URL, window: { location: { origin: 'https://fixture.test' } },
            config: { apiScopedWorkspace: '/scoped-workspace' },
            state: { scopeIdentity: {}, scopedWorkspaces: {}, pendingScopedMutation: Promise.resolve() },
            buildTypedEditorContext: (resourceType, options) => ({ resource_type: resourceType, ...options }),
            scopedWorkspaceKey: (resourceType) => resourceType,
            renderThemeBindingOwnership() {}, renderScopedConflictPanel() {},
            apiJson: async (url, options) => { requests.push({ url, options }); return { success: true, data: snapshot }; },
            reconcileRequiredDefaultsAfterDraftReady: async () => { installs += 1; },
        };
        vm.runInNewContext(source.slice(start, end + 6), context, { filename: file });

        assert.equal(await context.loadScopedWorkspace('layout'), snapshot);
        await context.state.pendingScopedMutation;
        assert.equal(await context.loadScopedWorkspace('layout', { locale: 'zh_Hans_CN' }), snapshot);
        await context.state.pendingScopedMutation;

        assert.equal(installs, 0, 'Opening or switching a preview must not install defaults into the saved draft');
        assert.equal(requests.length, 2);
        assert.ok(requests.every(request => !request.options?.method || request.options.method === 'GET'));
        assert.equal(context.state.scopedWorkspaces.layout.content_revision, 8);
        assert.equal(context.state.scopedWorkspaces.layout.draft_payload.nodes.clear.widget_code, '__no_widget_placements__');
    });
}
