'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    MESSAGE_TYPE,
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

test('normalizes only safe locale and layout context', () => {
    assert.deepEqual(normalizeContext({ locale: 'ru-RU', layoutOption: 'cms landing' }), {
        locale: 'ru_RU',
        layoutOption: 'cms-landing',
    });
    assert.deepEqual(normalizeContext({ locale: '<script>', layoutOption: '../secret' }), {
        locale: '',
        layoutOption: 'secret',
    });
});

test('parent waits for same-origin ready, sends context, and resolves matching ack', async () => {
    const hostWindow = createHostWindow();
    const sent = [];
    const targetWindow = { postMessage: (payload, origin) => sent.push([payload, origin]) };
    const bridge = createParentBridge({ hostWindow, targetWindow, timeoutMs: 50 });
    bridge.start();

    hostWindow.emit('message', {
        origin: 'https://attacker.example.test',
        source: targetWindow,
        data: { type: MESSAGE_TYPE, version: VERSION, action: 'ready' },
    });
    assert.equal(bridge.isReady(), false);

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: targetWindow,
        data: { type: MESSAGE_TYPE, version: VERSION, action: 'ready' },
    });
    assert.equal(bridge.isReady(), true);

    const pending = bridge.setContext({ locale: 'ru_RU', layoutOption: 'blank' });
    assert.equal(sent.length, 1);
    const request = sent[0][0];
    assert.equal(request.action, 'set-context');
    assert.deepEqual(request.context, { locale: 'ru_RU', layoutOption: 'blank' });

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: targetWindow,
        data: {
            type: MESSAGE_TYPE,
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

test('parent invokes fallback when ready or ack times out', async () => {
    const hostWindow = createHostWindow();
    const fallbacks = [];
    const bridge = createParentBridge({
        hostWindow,
        targetWindow: { postMessage() {} },
        timeoutMs: 5,
        onFallback: (context, reason) => fallbacks.push([context, reason]),
    });
    bridge.start();
    await bridge.setContext({ locale: 'en_US', layoutOption: 'default' });
    assert.equal(fallbacks.length, 1);
    assert.equal(fallbacks[0][1], 'not-ready');
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

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: {},
        data: { type: MESSAGE_TYPE, version: VERSION, action: 'set-context', requestId: 'bad', context: {} },
    });
    assert.equal(applied.length, 0);

    hostWindow.emit('message', {
        origin: hostWindow.location.origin,
        source: parentWindow,
        data: {
            type: MESSAGE_TYPE,
            version: VERSION,
            action: 'set-context',
            requestId: 'request-1',
            context: { locale: 'en-us', layoutOption: 'cms blank' },
        },
    });
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(applied, [{ locale: 'en_US', layoutOption: 'cms-blank' }]);
    assert.equal(messages.at(-1)[0].action, 'ack');
    assert.equal(messages.at(-1)[0].requestId, 'request-1');
    assert.equal(messages.at(-1)[0].ok, true);
    bridge.destroy();
});
