// 原型交互回归：node app/code/Weline/Product/doc/product-ai-optimization/prototype/verify.mjs
// 截图输出到仓库根 .screenshots/（gitignored）。
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
function repoRoot(start) {
  let dir = start;
  for (let i = 0; i < 12; i++) {
    if (fs.existsSync(path.join(dir, 'app', 'etc', 'env.php'))) return dir;
    const up = path.dirname(dir);
    if (up === dir) break;
    dir = up;
  }
  throw new Error('repo root not found from ' + start);
}
const ROOT = repoRoot(HERE);
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const PORT = 9226;
const PAGE_URL = pathToFileURL(path.join(HERE, 'ai-review-center.html')).href;
const OUT = path.join(ROOT, '.screenshots');
fs.mkdirSync(OUT, { recursive: true });

const chrome = spawn(CHROME, [
  '--headless=new', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${path.join(OUT, '.chrome-profile-verify')}`, '--ignore-certificate-errors',
  '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars',
  '--window-size=1440,900', 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function getJson(url) {
  for (let i = 0; i < 60; i++) { try { const r = await fetch(url); if (r.ok) return await r.json(); } catch {} await sleep(200); }
  throw new Error('devtools not ready');
}
const version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
let seq = 0; const pending = new Map();
ws.onmessage = (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result); } };
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); ws.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params })); });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const S = (m, p) => send(m, p, sessionId);
await S('Page.enable'); await S('Runtime.enable'); await S('Log.enable');
const consoleErrors = [];
ws.addEventListener('message', (ev) => {
  const m = JSON.parse(ev.data);
  if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params.exceptionDetails?.text || 'exception');
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') consoleErrors.push(m.params.entry.text);
});
await S('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

const evalJs = async (expression) => (await S('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.value;
async function nav(url) {
  await S('Page.navigate', { url });
  for (let i = 0; i < 60; i++) { const r = await S('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true }); if (r.result?.value === 'complete') break; await sleep(200); }
  await sleep(500);
}
async function shot(name) {
  const res = await S('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(`${OUT}/${name}.png`, Buffer.from(res.data, 'base64'));
}

await nav(PAGE_URL);
const base = await evalJs(`(()=>{
  const applyBtn=document.getElementById('applyBtn');
  return {
    title: document.title,
    queue: document.querySelectorAll('.queue-item').length,
    selected: document.querySelector('.queue-item[aria-current="true"]')?.getAttribute('data-job'),
    fields: document.querySelectorAll('.field-card').length,
    applyDisabled: applyBtn ? applyBtn.disabled : null,
    accepted: document.getElementById('acceptedCount')?.textContent,
    docScrollWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
    panelH: Math.round(document.querySelector('.workspace').getBoundingClientRect().height)
  };
})()`);
console.log('BASE', JSON.stringify(base));

// accept all fields
await evalJs(`(async()=>{const boxes=[...document.querySelectorAll('[data-accept]')];for(const b of boxes){if(!b.checked){b.click();await new Promise(r=>setTimeout(r,40));}}return boxes.length})()`);
await sleep(300);
const afterAccept = await evalJs(`(()=>({accepted:document.getElementById('acceptedCount').textContent, applyDisabled:document.getElementById('applyBtn').disabled, cards:document.querySelectorAll('.field-card.accepted').length}))()`);
console.log('AFTER_ACCEPT', JSON.stringify(afterAccept));
await shot('proto-ai-review');

// apply
await evalJs(`document.getElementById('applyBtn').click()`);
await sleep(1100);
const afterApply = await evalJs(`(()=>({toast:document.getElementById('toastText').textContent, toastShown:document.getElementById('toast').classList.contains('show'), appliedBanner:!!document.querySelector('.banner.info'), appliedJobs:document.querySelectorAll('.queue-item.applied').length}))()`);
console.log('AFTER_APPLY', JSON.stringify(afterApply));
await shot('proto-ai-applied');

// image job
await nav(PAGE_URL);
await evalJs(`document.querySelector('[data-job="j2"]').click()`);
await sleep(400);
const imageState = await evalJs(`(()=>({candidates:document.querySelectorAll('[data-candidate]').length, original:!!document.querySelector('.candidate.orig'), accepted:document.getElementById('acceptedCount').textContent, applyDisabled:document.getElementById('applyBtn').disabled}))()`);
console.log('IMAGE_JOB', JSON.stringify(imageState));
await evalJs(`document.querySelector('[data-candidate="1"]').click()`);
await sleep(300);
const imagePicked = await evalJs(`(()=>({checked:document.querySelector('[data-candidate="1"]').getAttribute('aria-checked'), accepted:document.getElementById('acceptedCount').textContent, applyDisabled:document.getElementById('applyBtn').disabled}))()`);
console.log('IMAGE_PICKED', JSON.stringify(imagePicked));
await shot('proto-ai-image');

// conflict job
await nav(PAGE_URL);
await evalJs(`document.querySelector('[data-job="j3"]').click()`);
await sleep(400);
const conflict = await evalJs(`(()=>({banner:!!document.getElementById('conflictBanner'), bannerText:document.getElementById('conflictBanner')?.innerText.replace(/\\s+/g,' ').slice(0,90), conflictBadge:!!document.querySelector('.field-card .badge.warn')}))()`);
console.log('CONFLICT', JSON.stringify(conflict));
await shot('proto-ai-conflict');

// batch modal
await evalJs(`document.querySelector('#batchBtn').click()`);
await sleep(400);
const modal = await evalJs(`(()=>({open:document.getElementById('batchModal').classList.contains('open'), models:document.querySelectorAll('#batchModal select').length, rect:document.querySelector('#batchModal .modal').getBoundingClientRect().toJSON()}))()`);
console.log('MODAL', JSON.stringify(modal));
await shot('proto-ai-batch');
await evalJs(`document.querySelector('#batchSubmit').click()`);
await sleep(300);
console.log('BATCH_TOAST', JSON.stringify(await evalJs(`(()=>({text:document.getElementById('toastText').textContent, open:document.getElementById('batchModal').classList.contains('open')}))()`)));

// filters
await nav(PAGE_URL);
await evalJs(`document.querySelector('[data-filter="image"]').click()`);
await sleep(250);
console.log('FILTER_IMAGE', JSON.stringify(await evalJs(`document.querySelectorAll('.queue-item').length`)));
await evalJs(`document.querySelector('[data-filter="all"]').click()`);
await evalJs(`(()=>{const s=document.getElementById('modelFilter');s.value='qwen-mt';s.dispatchEvent(new Event('change'));})()`);
await sleep(250);
console.log('FILTER_MODEL', JSON.stringify(await evalJs(`document.querySelectorAll('.queue-item').length`)));

console.log('CONSOLE_ERRORS', JSON.stringify(consoleErrors));
console.log('DOC_WIDTH', JSON.stringify(await evalJs(`(()=>({sw:document.documentElement.scrollWidth,vw:window.innerWidth}))()`)));

await S('Page.close').catch(() => {});
ws.close(); chrome.kill('SIGTERM'); process.exit(0);
