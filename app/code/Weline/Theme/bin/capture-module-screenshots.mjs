#!/usr/bin/env node
/**
 * Capture a storefront page across widths × light/dark themes (contract smoke evidence).
 *
 * Usage:
 *   node capture-module-screenshots.mjs \
 *     --chrome "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
 *     --url "https://p05113ef3.test.weline.com:9555/compare" \
 *     --out-dir tmp/theme-standard-audit/shots/compare \
 *     --widths 390,768,1024,1440 --themes light,dark
 *
 * Notes:
 *  - WLS attack guard blocks bot User-Agents; this sends a browser UA.
 *  - Dark mode is applied via <html data-theme="dark"> before capture.
 *  - Chrome's --screenshot dies on this storefront, so CDP captureScreenshot is used.
 */
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const args = process.argv.slice(2);
const opt = (name, fallback = '') => {
    const i = args.indexOf(`--${name}`);
    return i >= 0 ? args[i + 1] : fallback;
};

const chromePath = opt('chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome');
const pageUrl = opt('url');
const outDir = opt('out-dir', 'tmp/theme-standard-audit/shots');
const widths = opt('widths', '390,768,1024,1440').split(',').map((v) => Number(v.trim())).filter(Boolean);
const themes = opt('themes', 'light,dark').split(',').map((v) => v.trim()).filter(Boolean);
const timeoutMs = Math.max(5000, Number(opt('timeout', '30000')));
const UA = opt(
    'ua',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
);

if (!pageUrl) {
    process.stderr.write('usage: capture-module-screenshots.mjs --url <url> [--out-dir dir] [--widths a,b] [--themes light,dark]\n');
    process.exit(2);
}

mkdirSync(outDir, { recursive: true });
const userDataDir = mkdtempSync(join(tmpdir(), 'weline-shots-'));
const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--hide-scrollbars',
    '--ignore-certificate-errors',
    '--no-first-run',
    '--disable-extensions',
    '--disable-background-networking',
    `--user-agent=${UA}`,
    `--user-data-dir=${userDataDir}`,
    '--remote-debugging-port=0',
    'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });

const consoleErrors = [];
const pageErrors = [];
const failedRequests = [];
const requestUrls = new Map();
let settled = false;
const cleanup = () => {
    for (let i = 0; i < 5; i++) {
        try {
            rmSync(userDataDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 50 });
            return;
        } catch {
            const until = Date.now() + 40;
            while (Date.now() < until) { /* spin */ }
        }
    }
};
const fail = (message, code = 1) => {
    if (settled) return;
    settled = true;
    process.stderr.write(String(message) + '\n');
    try { chrome.kill('SIGKILL'); } catch { /* gone */ }
    cleanup();
    process.exit(code);
};

const port = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('devtools port timeout')), 10000);
    let buffer = '';
    chrome.stderr.on('data', (chunk) => {
        buffer += chunk.toString();
        const m = buffer.match(/DevTools listening on ws:\/\/127\.0\.0\.1:(\d+)\//);
        if (m) {
            clearTimeout(timer);
            resolve(Number(m[1]));
        }
    });
}).catch((e) => fail(e.message));
if (!port) fail('devtools port missing');

const version = await fetch(`http://127.0.0.1:${port}/json/version`).then((r) => r.json());
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', () => reject(new Error('browser socket failed')), { once: true });
});

let nextId = 0;
const pending = new Map();
const waiters = new Map();
ws.addEventListener('message', (event) => {
    const msg = JSON.parse(String(event.data));
    if (msg.id && pending.has(msg.id)) {
        const slot = pending.get(msg.id);
        pending.delete(msg.id);
        if (msg.error) slot.reject(new Error(msg.error.message || 'cdp error'));
        else slot.resolve(msg.result || {});
        return;
    }
    if (msg.method) {
        if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
            consoleErrors.push((msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ').slice(0, 300));
        } else if (msg.method === 'Runtime.exceptionThrown') {
            pageErrors.push(String(msg.params?.exceptionDetails?.exception?.description
                ?? msg.params?.exceptionDetails?.text ?? '').slice(0, 300));
        } else if (msg.method === 'Network.requestWillBeSent') {
            requestUrls.set(msg.params?.requestId, msg.params?.request?.url || '');
        } else if (msg.method === 'Network.loadingFailed') {
            const url = requestUrls.get(msg.params?.requestId) || '?';
            failedRequests.push(`${msg.params?.errorText} ${url}`);
        } else if (msg.method === 'Log.entryAdded' && msg.params?.entry?.level === 'error') {
            consoleErrors.push(String(msg.params.entry.text).slice(0, 300));
        }
    }
    if (msg.method && waiters.has(msg.method)) {
        waiters.get(msg.method).forEach((resolve) => resolve(msg.params || {}));
        waiters.delete(msg.method);
    }
});
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    const payload = { id, method, params };
    if (sessionId) payload.sessionId = sessionId;
    ws.send(JSON.stringify(payload));
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

const results = [];
try {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    await send('Page.enable', {}, sessionId);
    await send('Runtime.enable', {}, sessionId);
    await send('Log.enable', {}, sessionId);
    await send('Network.enable', {}, sessionId);
    await send('Page.navigate', { url: pageUrl }, sessionId);
    await waitFor('Page.loadEventFired', timeoutMs);
    await send('Page.stopLoading', {}, sessionId).catch(() => {});
    await new Promise((r) => setTimeout(r, 600));

    for (const width of widths) {
        for (const theme of themes) {
            await send('Emulation.setDeviceMetricsOverride', {
                width, height: Math.round(width * 0.75), deviceScaleFactor: 1, mobile: width < 768,
            }, sessionId);
            await send('Runtime.evaluate', {
                expression: `(function(){document.documentElement.setAttribute('data-theme', ${JSON.stringify(theme)});`
                    + `document.documentElement.style.colorScheme=${JSON.stringify(theme)};`
                    + `document.body && (document.body.offsetHeight);return document.documentElement.getAttribute('data-theme');})()`,
            }, sessionId);
            await new Promise((r) => setTimeout(r, 350));
            const metrics = await send('Page.getLayoutMetrics', {}, sessionId).catch(() => null);
            const contentHeight = Math.min(4200, Math.max(600, Math.round(metrics?.cssContentSize?.height || width * 1.6)));
            const probe = await send('Runtime.evaluate', {
                expression: `(function(){var cs=getComputedStyle(document.documentElement);`
                    + `return JSON.stringify({theme:document.documentElement.getAttribute('data-theme'),`
                    + `bg:cs.getPropertyValue('--color-bg-canvas').trim(),`
                    + `text:cs.getPropertyValue('--color-text').trim(),`
                    + `bodyBg:getComputedStyle(document.body).backgroundColor,`
                    + `bodyColor:getComputedStyle(document.body).color,`
                    + `welineGlobals:Object.keys(window).filter(function(k){return /^Weline[A-Z]/.test(k);}).slice(0,40),`
                    + `loadAttrs:Array.from(document.querySelectorAll('[data-weline-load]')).map(function(e){return e.getAttribute('data-weline-load');}).slice(0,20),`
                    + `consentEntry:(function(){try{var c=(window.WelineModulesConfig||{}).modules||{};return JSON.stringify(c.consentBanner||null);}catch(e){return 'ERR'}})(),`
                    + `blogEntry:(function(){try{var c=(window.WelineModulesConfig||{}).modules||{};return JSON.stringify(c.blogCategoryFilter||null);}catch(e){return 'ERR'}})(),`
                    + `apiConfigProbe:(function(){var c=window.WelineApiConfig||{};return JSON.stringify({worker:!!c.workerUrl,endpoint:c.endpoint||null,queryBin:c.queryBinUrl||null});})()});})()`,
                returnByValue: true,
            }, sessionId);
            const tokens = probe?.result?.value ? JSON.parse(probe.result.value) : null;
            const shot = await send('Page.captureScreenshot', {
                format: 'png',
                captureBeyondViewport: true,
                clip: { x: 0, y: 0, width, height: contentHeight, scale: 1 },
            }, sessionId);
            if (!shot.data) throw new Error('empty screenshot');
            const file = join(outDir, `${width}-${theme}.png`);
            writeFileSync(file, Buffer.from(shot.data, 'base64'));
            results.push({ file, width, theme, bytes: Buffer.from(shot.data, 'base64').length, tokens });
        }
    }
    settled = true;
    try { await send('Browser.close'); } catch { /* socket close is enough */ }
    chrome.kill('SIGKILL');
    cleanup();
    process.stdout.write(JSON.stringify({
        ok: true, url: pageUrl, shots: results,
        console_errors: [...new Set(consoleErrors)].slice(0, 20),
        page_errors: [...new Set(pageErrors)].slice(0, 20),
        failed_requests: [...new Set(failedRequests)].slice(0, 20),
    }, null, 1) + '\n');
    process.exit(0);
} catch (error) {
    fail((error instanceof Error ? error.message : String(error)) + ': ' + pageUrl);
}
