// 原型响应式回归：node app/code/Weline/Product/doc/product-ai-optimization/prototype/verify-responsive.mjs
// 三档视口量 scrollWidth/apply-bar 位置，截图到仓库根 .screenshots/（gitignored）。
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
const PORT = 9227;
const PAGE_URL = pathToFileURL(path.join(HERE, 'ai-review-center.html')).href;
const OUT = path.join(ROOT, '.screenshots');
fs.mkdirSync(OUT, { recursive: true });

const chrome = spawn(CHROME, [
  '--headless=new', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${path.join(OUT, '.chrome-profile-responsive')}`, '--ignore-certificate-errors',
  '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars',
  '--window-size=1280,900', 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function getJson(url) { for (let i = 0; i < 60; i++) { try { const r = await fetch(url); if (r.ok) return await r.json(); } catch {} await sleep(200); } throw new Error('cdp not ready'); }
const version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
let seq = 0; const pending = new Map();
ws.onmessage = (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result); } };
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); ws.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params })); });
const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const S = (m, p) => send(m, p, sessionId);
await S('Page.enable'); await S('Runtime.enable');
const evalJs = async (expression) => (await S('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.value;

for (const [w, h, name] of [[1280, 900, 'proto-ai-1280'], [1024, 900, 'proto-ai-1024'], [1440, 1200, 'proto-ai-tall']]) {
  await S('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false });
  await S('Page.navigate', { url: PAGE_URL });
  for (let i = 0; i < 60; i++) { const r = await S('Runtime.evaluate', { expression: 'document.readyState', returnByValue: true }); if (r.result?.value === 'complete') break; await sleep(200); }
  await sleep(500);
  const m = await evalJs(`(()=>{const bar=document.querySelector('.apply-bar');const r=bar.getBoundingClientRect();return {sw:document.documentElement.scrollWidth,vw:window.innerWidth,sh:document.documentElement.scrollHeight,vh:innerHeight,barTop:Math.round(r.top),barBottom:Math.round(r.bottom),queueVisible:document.querySelector('.queue-item')!==null,detailTitle:document.querySelector('.review-title h2')?.textContent.slice(0,12)}})()`);
  console.log(name, JSON.stringify(m));
  const res = await S('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(`${OUT}/${name}.png`, Buffer.from(res.data, 'base64'));
}

await S('Page.close').catch(() => {});
ws.close(); chrome.kill('SIGTERM'); process.exit(0);
