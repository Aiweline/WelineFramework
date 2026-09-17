'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../view/statics/ui/pages/weline-theme-editor.js'), 'utf8');

// Execute the production functions, with only their DOM, timer and API boundaries supplied.
function productionFunction(name, script = source) {
    const declaration = new RegExp(`\\n    (?:async )?function ${name}\\(`).exec(script);
    assert.ok(declaration, `Production function ${name} exists`);
    const tail = script.slice(declaration.index + 1);
    const next = /\n    (?:async )?function \w+\(/.exec(tail);
    assert.ok(next, `Production function ${name} has a following declaration`);
    return tail.slice(0, next.index);
}

function editor(apiJson) {
    const nodes = new Map();
    class Element {
        constructor() { this.style = {}; this.innerHTML = ''; }
        querySelector(selector) {
            return selector === '#themeEditorLockReload' ? {addEventListener() {}} : null;
        }
        appendChild(node) { node.parentElement = this; nodes.set(node.id, node); }
        remove() { nodes.delete(this.id); }
    }
    const context = vm.createContext({
        HTMLElement: Element,
        elements: {container: new Element()},
        document: {getElementById: (id) => nodes.get(id), createElement: () => new Element()},
        window: {getComputedStyle: () => ({position: 'static'}), location: {reload() {}}},
        state: {themeId: 1, currentUserId: 7, scopeIdentity: 'website:1', lockHeld: false, lockInitGeneration: 0},
        config: {apiCheckLock: '/check-lock', apiUpdateActivity: '/update-activity'},
        apiJson,
        buildLayoutVersionIdentityPayload: () => ({theme_id: 1, page_type: 'homepage'}),
        translateUiText: (text) => text,
        showToast() {},
        startLockHeartbeat() {},
        bindLockLifecycle() {},
        clearInterval() {},
        clearTimeout() {},
        setTimeout(callback) { callback(); },
        console: {warn() {}},
    });
    const functions = [
        'escapeHtml', 'isEditorLockHeldByCurrentUser', 'isEditorLockBlockedByOther',
        'applyEditorLockHeldState', 'renderEditorLockOverlay', 'clearEditorLockOverlay',
        'stopLockHeartbeat', 'refreshEditorLockActivity', 'acquireEditorLockPayload',
        'initializeEditorLock',
    ];
    vm.runInContext(functions.map((name) => productionFunction(name)).join('\n'), context);
    return {context, overlay: () => nodes.get('themeEditorLockOverlay')};
}

function editorThroughBackendSdk(messageForRequest) {
    const fixture = editor();
    const {context} = fixture;
    const backendSource = fs.readFileSync(path.join(__dirname, '../../../Backend/view/statics/js/weline-api.js'), 'utf8');
    const start = backendSource.indexOf('    BackendQueryBinClient.prototype.handleWorkerMessage = function');
    const end = backendSource.indexOf('    BackendQueryBinClient.prototype.handleWorkerError = function', start);
    assert.ok(start >= 0 && end > start, 'Execute the real SDK worker response handler');
    Object.assign(context, {
        URL, URLSearchParams, Headers,
        BackendQueryBinClient: function () {},
        buildQueryBinConfig: () => ({endpoint: '/query-bin'}),
        resolveSameOriginEditorUrl: (url) => new URL(url, 'https://store.test').href,
        buildTypedEditorContext: () => ({scope: {identity: 'website:1'}, locale: 'zh_Hans_CN'}),
        isThemeMutationBlockedByLayoutLock: () => false,
    });
    context.window.clearTimeout = () => {};
    const backendFunctions = ['buildError', 'isObjectPayload', 'hasOwn', 'businessCode',
        'isHttpFailureCode', 'isBusinessFailure', 'readableFailureText', 'failureMessage'];
    vm.runInContext(backendFunctions.map((name) => productionFunction(name, backendSource)).join('\n')
        + '\n' + backendSource.slice(start, end), context);
    vm.runInContext(['normalizeRequestHeaders', 'hasValidTypedEditorContextParam', 'apiRequest',
        'unwrapApiPayload', 'describeNonJsonApiResponse', 'apiJson'].map((name) => productionFunction(name)).join('\n'), context);
    context.resolveThemeEditorResource = async () => ({
        editorRequest: (params, options) => new Promise((resolve, reject) => {
            const client = {
                pending: {fixture: {resolve, reject, payload: {options}, requestUrl: '/query-bin'}},
                finishDevTrace() {}, reportDevError() {},
            };
            context.BackendQueryBinClient.prototype.handleWorkerMessage.call(client, {
                data: {id: 'fixture', status: 200, ok: true, ...messageForRequest(params)},
            });
        }),
    });
    return fixture;
}

test('real SDK business-false heartbeat reaches the existing successful reacquisition branch', async () => {
    const {context, overlay} = editorThroughBackendSdk((params) => ({
        body: {ok: true, data: {success: params.url.endsWith('/check-lock')}},
    }));
    context.state.lockHeld = true;
    assert.equal(await context.refreshEditorLockActivity(), true);
    assert.equal(context.state.lockHeld, true);
    assert.equal(overlay(), undefined);
});

test('real SDK acquisition conflict retains the other owner instead of becoming unavailable', async () => {
    const {context, overlay} = editorThroughBackendSdk(() => ({body: {ok: true, data: {
        success: false,
        data: {is_locked_by_other: true, lock_info: {user_id: 9, user_name: 'Other editor'}},
    }}}));
    assert.equal(await context.initializeEditorLock(), false);
    assert.equal(context.state.lockHeld, false);
    assert.equal(context.state.lockConflictInfo?.user_id, 9);
    assert.match(overlay().innerHTML, /Other editor/);
    assert.doesNotMatch(overlay().innerHTML, /无法确认编辑权限/);
});

test('real SDK protocol rejection still keeps the editor read-only', async () => {
    const {context, overlay} = editorThroughBackendSdk(() => ({
        status: 403, ok: false, body: {ok: false, error: {code: 'forbidden', message: 'Permission denied'}},
    }));
    context.state.lockHeld = true;
    assert.equal(await context.refreshEditorLockActivity(), false);
    assert.equal(context.state.lockHeld, false);
    assert.match(overlay().innerHTML, /Permission denied/);
});

test('non-lock editor requests retain the SDK default business-error rejection', async () => {
    const {context} = editorThroughBackendSdk(() => ({
        body: {ok: true, data: {success: false, message: 'Save rejected'}},
    }));
    await assert.rejects(context.apiJson('/save-layout-config', {method: 'POST', body: '{}'}), /Save rejected/);
});

test('initial lock rejection retains the API reason in the read-only overlay', async () => {
    const {context, overlay} = editor(async () => ({success: false, message: '当前编辑上下文已失效'}));
    assert.equal(await context.initializeEditorLock(), false);
    assert.equal(context.state.lockHeld, false);
    assert.match(overlay().innerHTML, /data-theme-editor-lock-reason[^>]*>当前编辑上下文已失效<\/p>/);
});

test('transport failure is retained as escaped text, not executable markup', async () => {
    const {context, overlay} = editor(async () => { throw new Error('<img src=x onerror="fail()"> & unavailable'); });
    assert.equal(await context.initializeEditorLock(), false);
    assert.equal(context.state.lockHeld, false);
    assert.match(overlay().innerHTML, /&lt;img src=x onerror=&quot;fail\(\)&quot;&gt; &amp; unavailable/);
    assert.doesNotMatch(overlay().innerHTML, /<img/);
});

test('failed heartbeat retains the latest reacquisition reason and becomes read-only', async () => {
    const {context, overlay} = editor(async (url) => ({
        success: false,
        message: url === '/check-lock' ? '重新获取编辑锁失败' : '编辑锁已过期',
    }));
    context.state.lockHeld = true;
    context.state.lockHeartbeatTimer = 123;
    assert.equal(await context.refreshEditorLockActivity(), false);
    assert.equal(context.state.lockHeld, false);
    assert.equal(context.state.lockHeartbeatTimer, null);
    assert.match(overlay().innerHTML, /data-theme-editor-lock-reason[^>]*>重新获取编辑锁失败<\/p>/);
});

test('heartbeat transport failure retains its cause', async () => {
    const {context, overlay} = editor(async () => { throw new Error('Worker connection closed'); });
    context.state.lockHeld = true;
    assert.equal(await context.refreshEditorLockActivity(), false);
    assert.equal(context.state.lockHeld, false);
    assert.match(overlay().innerHTML, /data-theme-editor-lock-reason[^>]*>Worker connection closed<\/p>/);
});

test('another editor conflict still shows the escaped owner without unrelated failure detail', async () => {
    const {context, overlay} = editor(async () => ({
        success: false,
        message: 'Unrelated diagnostic detail',
        data: {is_locked_by_other: true, lock_info: {user_id: 9, user_name: '<Owner>'}},
    }));
    assert.equal(await context.initializeEditorLock(), false);
    assert.equal(context.state.lockHeld, false);
    assert.equal(context.state.lockConflictInfo.user_id, 9);
    assert.match(overlay().innerHTML, /&lt;Owner&gt;/);
    assert.doesNotMatch(overlay().innerHTML, /data-theme-editor-lock-reason|Unrelated diagnostic detail/);
});

test('successful acquisition clears the pending overlay and holds the lock', async () => {
    const {context, overlay} = editor(async () => ({success: true}));
    assert.equal(await context.initializeEditorLock(), true);
    assert.equal(context.state.lockHeld, true);
    assert.equal(overlay(), undefined);
});

test('fast lock success skips pending overlay when delay never fires', async () => {
    const {context, overlay} = editor(async () => ({success: true}));
    const timers = [];
    context.setTimeout = (callback, ms) => {
        const id = timers.push({callback, ms});
        return id;
    };
    context.clearTimeout = (id) => {
        if (id > 0 && id <= timers.length) {
            timers[id - 1] = null;
        }
    };
    assert.equal(await context.initializeEditorLock(), true);
    assert.equal(context.state.lockHeld, true);
    assert.equal(overlay(), undefined);
    assert.equal(timers.every((timer) => timer === null), true);
});

test('pending state never displays a stale failure reason', () => {
    const {context, overlay} = editor(async () => ({success: true}));
    context.renderEditorLockOverlay(null, 'pending', 'Stale failure');
    assert.match(overlay().innerHTML, /正在准备编辑/);
    assert.match(overlay().className, /w-theme-editor-lock--pending/);
    assert.doesNotMatch(overlay().innerHTML, /data-theme-editor-lock-reason|Stale failure/);
});
