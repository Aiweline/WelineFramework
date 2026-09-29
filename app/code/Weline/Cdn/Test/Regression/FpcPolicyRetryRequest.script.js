'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../view/statics/js/backend/fpc-policy-management.js'), 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
const nodes = new Map();
function node() {
    return {
        dataset: {}, value: '', textContent: '', disabled: false, attributes: {},
        querySelector: () => null, querySelectorAll: () => [], addEventListener() {}, replaceChildren() {},
        contains: () => false, hasAttribute(name) { return name in this.attributes; },
        setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; }
    };
}
function get(selector) { if (!nodes.has(selector)) nodes.set(selector, node()); return nodes.get(selector); }
const root = node();
root.dataset = {tab: 'cdn', cdnI18n: JSON.stringify({retryQueued: '已安排重试', failure: '失败', loading: '加载'})};
root.querySelector = get;
root.contains = () => true;
get('#cdn-fpc-scope').value = 'default.__website__.default';
get('#cdn-fpc-mode_value').value = 'normal';
get('#cdn-fpc-keyword').value = 'WebsiteA';
const listeners = {};
const calls = [];
let retryResult = {success: true};
const resource = {
    async retryFpcSync(params) { calls.push({operation: 'retryFpcSync', params: plain(params)}); return retryResult; },
    async listFpcSyncRecords(params) { calls.push({operation: 'listFpcSyncRecords', params: plain(params)}); return {success: true, data: {items: [], total: 0, page: 1, page_size: 20}}; }
};
vm.runInNewContext(source, {
    window: {Weline: {Api: {resource: () => resource}}},
    document: {querySelector: () => root, getElementById: () => null, addEventListener(type, callback) { listeners[type] = callback; }}
});
const control = node();
control.dataset = {cdnAction: 'retry', syncId: '71'};
control.textContent = '重试';
function clickRetry() { listeners.click({target: {closest: () => control}}); }
async function settled() {
    for (let turn = 0; turn < 10 && control.disabled; turn++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(control.disabled, false, 'retry button must leave loading state');
}
(async () => {
    clickRetry();
    await settled();
    assert.deepEqual(calls[0], {operation: 'retryFpcSync', params: {sync_id: 71}}, 'retry may send only the public task locator');
    assert.deepEqual(calls[1], {operation: 'listFpcSyncRecords', params: {target_scope: 'default.__website__.default', store_mode: 'normal', keyword: 'WebsiteA', page: 1, page_size: 20}}, 'refresh must keep the selected Scope/mode and keyword');
    assert.equal(get('[data-cdn-notice]').textContent, '已安排重试');
    calls.length = 0;
    retryResult = {success: false, message: 'source denied', error_code: 'source_permission_denied'};
    clickRetry();
    await settled();
    assert.deepEqual(calls, [{operation: 'retryFpcSync', params: {sync_id: 71}}], 'a failed retry must not refresh as successful');
    assert.equal(get('[data-cdn-notice]').textContent, 'source denied (source_permission_denied)');
    assert.equal(get('[data-cdn-notice]').attributes.role, 'alert');
    console.log('FPC retry request: public sync_id only, filtered refresh and failure preservation passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
