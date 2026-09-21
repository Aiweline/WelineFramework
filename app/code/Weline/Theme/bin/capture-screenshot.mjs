#!/usr/bin/env node
/**
 * Capture one URL to a PNG and always exit.
 * Chrome's --screenshot flag keeps the process alive on this storefront.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.argv[2] || '';
const pageUrl = process.argv[3] || '';
const outPath = process.argv[4] || '';
const timeoutMs = Math.max(3000, Number(process.argv[5] || 22000));

if (!chromePath || !pageUrl || !outPath) {
    process.stderr.write('usage: capture-screenshot.mjs <chrome> <url> <png> [timeoutMs]\n');
    process.exit(2);
}

const userDataDir = mkdtempSync(join(tmpdir(), 'weline-theme-preview-'));
const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--hide-scrollbars',
    '--ignore-certificate-errors',
    '--no-first-run',
    '--disable-extensions',
    '--disable-background-networking',
    `--user-data-dir=${userDataDir}`,
    '--remote-debugging-port=0',
    'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });

let settled = false;
const fail = (message, code = 1) => {
    if (settled) {
        return;
    }
    settled = true;
    process.stderr.write(String(message) + '\n');
    try {
        chrome.kill('SIGKILL');
    } catch {
        // already gone
    }
    rmSync(userDataDir, { recursive: true, force: true });
    process.exit(code);
};

const killer = setTimeout(() => fail('screenshot deadline exceeded'), timeoutMs + 4000);
chrome.on('exit', () => {
    if (!settled) {
        fail('chrome exited before capture');
    }
});

const port = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('devtools port timeout')), 8000);
    let buffer = '';
    chrome.stderr.on('data', (chunk) => {
        buffer += chunk.toString();
        const match = buffer.match(/DevTools listening on ws:\/\/127\.0\.0\.1:(\d+)\//);
        if (match) {
            clearTimeout(timer);
            resolve(Number(match[1]));
        }
    });
}).catch((error) => fail(error.message));

if (!port) {
    fail('devtools port missing');
}

const version = await fetch(`http://127.0.0.1:${port}/json/version`).then((response) => response.json());
const browserWs = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    browserWs.addEventListener('open', resolve, { once: true });
    browserWs.addEventListener('error', () => reject(new Error('browser socket failed')), { once: true });
});

let nextId = 0;
const pending = new Map();
const waiters = new Map();
browserWs.addEventListener('message', (event) => {
    const message = JSON.parse(String(event.data));
    if (message.id && pending.has(message.id)) {
        const slot = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) {
            slot.reject(new Error(message.error.message || 'cdp error'));
            return;
        }
        slot.resolve(message.result || {});
        return;
    }
    if (message.method && waiters.has(message.method)) {
        waiters.get(message.method).forEach((resolve) => resolve(message.params || {}));
        waiters.delete(message.method);
    }
});

const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    const payload = { id, method, params };
    if (sessionId) {
        payload.sessionId = sessionId;
    }
    browserWs.send(JSON.stringify(payload));
});

const waitFor = (method, limitMs) => new Promise((resolve) => {
    const timer = setTimeout(resolve, limitMs);
    const list = waiters.get(method) || [];
    list.push((params) => {
        clearTimeout(timer);
        resolve(params);
    });
    waiters.set(method, list);
});

try {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    await send('Page.enable', {}, sessionId);
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1200,
        height: 800,
        deviceScaleFactor: 1,
        mobile: false,
    }, sessionId);
    await send('Page.navigate', { url: pageUrl }, sessionId);
    await waitFor('Page.loadEventFired', Math.max(1000, timeoutMs - 1500));
    await send('Page.stopLoading', {}, sessionId).catch(() => {});
    await new Promise((resolve) => setTimeout(resolve, 400));
    const shot = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
    if (!shot.data) {
        fail('empty screenshot');
    }
    writeFileSync(outPath, Buffer.from(shot.data, 'base64'));
    settled = true;
    clearTimeout(killer);
    try {
        await send('Browser.close');
    } catch {
        // closing the socket is enough
    }
    chrome.kill('SIGKILL');
    rmSync(userDataDir, { recursive: true, force: true });
    process.exit(0);
} catch (error) {
    fail(error instanceof Error ? error.message : String(error));
}
