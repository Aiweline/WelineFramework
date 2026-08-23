'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    MESSAGE_TYPE,
    PROTOCOL,
    VERSION,
    createParentBridge,
    createChildBridge,
    normalizeContext,
} = require('../../view/statics/js/cms-preview-bridge.js');

function createHostWindow(origin = 'https://admin.example.test') {
    const listeners = new Map();
    return {
        location: { origin },
        addEventListener(type, listener) {
            const bucket = listeners.get(type) || [];
            bucket.push(listener);
            listeners.set(type, bucket);
        },
        removeEventListener(type, listener) {
            listeners.set(type, (listeners.get(type) || []).filter((item) => item !== listener));
        },
        emit(type, event) {
            (listeners.get(type) || []).forEach((listener) => listener(event));
        },
    };
}

test('normalizes the complete v2 context and preserves website id zero', () => {
    assert.deepEqual(normalizeContext({
        page_id: '12',
        websiteId: 0,
        website_id: 9,
        store_id: '7',
        website_code: 'base',
        store_code: 'default',
        scope: 'base.__store__.default',
        store_mode: 'DEV',
        locale: 'ru-RU',
        layoutOption: 'cms landing',
    }), {
        pageId: 12,
        websiteId: 0,
        storeId: 7,
        websiteCode: 'base',
        storeCode: 'default',
        scope: 'base.__store__.default',
        storeMode: 'dev',
        locale: 'ru_RU',
        layoutOption: 'cms-landing',
    });
    assert.deepEqual(normalizeContext({ locale: '<script>', layoutOption: '../secret' }), {
        pageId: 0,
        websiteId: 0,
        storeId: 0,
        websiteCode: '',
        storeCode: '',
        scope: '',
        storeMode: 'normal',
        locale: '',
        layoutOption: 'secret',
    });
    assert.equal(normalizeContext({ pageId: '12junk' }).pageId, 0);
    assert.equal(normalizeContext({ storeId: 1.5 }).storeId, 0);
});

test('parent waits for same-origin ready, sends context, and resolves matching ack', async () => {
    const hostWindow = createHostWindow();
    const sent = [];
    const targetWindow = { postMessage: (payload, origin) => sent.push([payload, origin]) };
    const bridge = createParentBridge({ hostWindow, targetWindow, timeoutMs: 50 });
    bridge.start();
    assert.equal(sent.length, 1);
    assert.deepEqual(sent[0], [{
        type: MESSAGE_TYPE,
        protocol: PROTOCOL,
        version: VERSION,
        action: 'probe',
    }, hostWindow.location.origin]);

    hostWindow.emit('message', {
        origin: 'https://attacker.example.test',
        source: targetWindow,
        data: { type: MESSAGE_TYPE, protocol: PROTOCOL, version: VERSION, action: 'ready' },
    });
    assert.equal(bridge.isReady(), false);

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: targetWindow,
        data: { type: MESSAGE_TYPE, protocol: PROTOCOL, version: VERSION, action: 'ready' },
    });
    assert.equal(bridge.isReady(), true);

    const pending = bridge.setContext({ locale: 'ru_RU', layoutOption: 'blank' });
    assert.equal(sent.length, 2);
    const request = sent[1][0];
    assert.equal(request.action, 'set-context');
    assert.equal(request.protocol, PROTOCOL);
    assert.deepEqual(request.context, {
        pageId: 0,
        websiteId: 0,
        storeId: 0,
        websiteCode: '',
        storeCode: '',
        scope: '',
        storeMode: 'normal',
        locale: 'ru_RU',
        layoutOption: 'blank',
    });

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: targetWindow,
        data: {
            type: MESSAGE_TYPE,
            protocol: PROTOCOL,
            version: VERSION,
            action: 'ack',
            requestId: request.requestId,
            ok: true,
            context: request.context,
        },
    });
    assert.equal((await pending).ok, true);
    bridge.destroy();
});

test('parent fails closed when the child never completes the v2 handshake', async () => {
    const hostWindow = createHostWindow();
    const bridge = createParentBridge({
        hostWindow,
        targetWindow: { postMessage() {} },
        timeoutMs: 5,
    });
    bridge.start();
    const result = await bridge.setContext({ locale: 'en_US', layoutOption: 'default' });
    assert.equal(result.ok, false);
    assert.equal(result.fallback, false);
    assert.equal(result.reason, 'not-ready');
    bridge.destroy();
});

test('parent does not reload the iframe when an acknowledged editor may be dirty', async () => {
    const hostWindow = createHostWindow();
    const targetWindow = { postMessage() {} };
    const bridge = createParentBridge({
        hostWindow,
        targetWindow,
        timeoutMs: 5,
    });
    bridge.start();
    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: targetWindow,
        data: { type: MESSAGE_TYPE, protocol: PROTOCOL, version: VERSION, action: 'ready' },
    });

    const result = await bridge.setContext({ locale: 'en_US', layoutOption: 'default' });
    assert.equal(result.ok, false);
    assert.equal(result.fallback, false);
    assert.equal(result.reason, 'ack-timeout');
    bridge.destroy();
});

test('parent fails closed when the iframe messaging target is unavailable', async () => {
    const hostWindow = createHostWindow();
    const bridge = createParentBridge({
        hostWindow,
        targetWindow: { postMessage() { throw new Error('detached'); } },
        timeoutMs: 5,
    });
    bridge.start();
    const result = await bridge.setContext({ locale: 'en_US', layoutOption: 'default' });
    assert.equal(result.ok, false);
    assert.equal(result.fallback, false);
    assert.equal(result.reason, 'post-failed');
    bridge.destroy();
});

test('child announces ready, ignores invalid source, applies valid context, and acknowledges', async () => {
    const hostWindow = createHostWindow();
    const messages = [];
    const parentWindow = { postMessage: (payload, origin) => messages.push([payload, origin]) };
    const applied = [];
    const bridge = createChildBridge({
        hostWindow,
        parentWindow,
        applyContext: async (context) => applied.push(context),
    });
    bridge.start();
    assert.equal(messages[0][0].action, 'ready');
    assert.equal(messages[0][0].protocol, PROTOCOL);

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: {},
        data: {
            type: MESSAGE_TYPE,
            protocol: PROTOCOL,
            version: VERSION,
            action: 'set-context',
            requestId: 'bad',
            context: {},
        },
    });
    assert.equal(applied.length, 0);

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: parentWindow,
        data: {
            type: MESSAGE_TYPE,
            protocol: PROTOCOL,
            version: VERSION,
            action: 'set-context',
            requestId: 'request-1',
            context: { locale: 'en-us', layoutOption: 'cms blank' },
        },
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(applied, [{
        pageId: 0,
        websiteId: 0,
        storeId: 0,
        websiteCode: '',
        storeCode: '',
        scope: '',
        storeMode: 'normal',
        locale: 'en_US',
        layoutOption: 'cms-blank',
    }]);
    assert.equal(messages.at(-1)[0].action, 'ack');
    assert.equal(messages.at(-1)[0].requestId, 'request-1');
    assert.equal(messages.at(-1)[0].ok, true);
    bridge.destroy();
});

test('child refuses a context switch while the Theme editor is dirty', async () => {
    const hostWindow = createHostWindow();
    const messages = [];
    const parentWindow = { postMessage: (payload, origin) => messages.push([payload, origin]) };
    const applied = [];
    const bridge = createChildBridge({
        hostWindow,
        parentWindow,
        isDirty: () => true,
        applyContext: async (context) => applied.push(context),
    });
    bridge.start();
    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: parentWindow,
        data: {
            type: MESSAGE_TYPE,
            protocol: PROTOCOL,
            version: VERSION,
            action: 'set-context',
            requestId: 'dirty-request',
            context: { locale: 'zh-Hans-CN', layoutOption: 'default' },
        },
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(applied, []);
    assert.equal(messages.at(-1)[0].action, 'ack');
    assert.equal(messages.at(-1)[0].requestId, 'dirty-request');
    assert.equal(messages.at(-1)[0].ok, false);
    assert.equal(messages.at(-1)[0].dirty, true);
    assert.equal(messages.at(-1)[0].reason, 'dirty');
    bridge.destroy();
});
