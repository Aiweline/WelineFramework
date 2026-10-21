#!/usr/bin/env node
/**
 * Head 预算门禁（P5）
 *
 * 指标（每页）：
 *   - headExternal  : 服务端直出 <head> 外链脚本数（声明标记不计）
 *   - headJsBytes   : <head> 脚本实际传输字节（transferSize，回退 decodedBodySize）
 *   - lcpMs         : Largest Contentful Paint（buffered PerformanceObserver）
 *   - badResponses / consoleErrors
 *
 * 用法：
 *   node tests/e2e/head-budget.mjs [baseUrl] [--update-baseline] [--json]
 *
 * 基线：tests/e2e/head-budget.baseline.json
 * 超阈值 exit 1；--update-baseline 用本次结果重写基线（用于发布后棘轮收紧）。
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const { chromium } = await import(join(here, 'node_modules/playwright/index.mjs'));

const args = process.argv.slice(2);
const baseUrl = (args.find((a) => a.startsWith('http')) || 'https://p05113ef3.test.weline.com:9555').replace(/\/$/, '');
const updateBaseline = args.includes('--update-baseline');
const jsonOnly = args.includes('--json');
const baselinePath = join(here, 'head-budget.baseline.json');

const PAGES = ['/', '/cart', '/products'];

async function readLcp(page) {
  return page.evaluate(() => new Promise((resolve) => {
    let value = 0;
    try {
      const po = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) value = Math.round(entry.startTime);
      });
      po.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) { /* unsupported */ }
    setTimeout(() => resolve(value), 1200);
  }));
}

function median(values) {
  const nums = values.filter((v) => typeof v === 'number' && v > 0).sort((a, b) => a - b);
  if (nums.length === 0) return 0;
  return nums[Math.floor(nums.length / 2)];
}

const baseline = existsSync(baselinePath) ? JSON.parse(readFileSync(baselinePath, 'utf8')) : { pages: {} };

const browser = await chromium.launch({ headless: true });
const results = [];
const failures = [];

async function measure(path) {
  const url = baseUrl + path;
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const badResponses = [];
  const guard403 = [];
  const consoleErrors = [];
  let docStatus = 0;
  page.on('response', (r) => {
    if (r.url() === url) docStatus = r.status();
    if (r.status() === 403) guard403.push(r.url());
    else if (r.status() >= 400) badResponses.push(`${r.status()} ${r.url()}`);
  });
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 160)));

  let rawHtml = '';
  try {
    const resp = await ctx.request.get(url, { headers: { Accept: 'text/html' } });
    rawHtml = await resp.text();
  } catch (e) {
    rawHtml = '';
  }

  const headHtml = rawHtml.split('</head>')[0] || '';
  const headScripts = [...headHtml.matchAll(/<script\b[^>]*>/gi)].map((m) => m[0]);
  const headExternal = headScripts.filter((t) => /\bsrc\s*=/.test(t) && !/data-weline-theme-js/.test(t)).length;
  const markers = headScripts.filter((t) => /data-weline-theme-js/.test(t)).length;
  const headSrcs = headScripts
    .filter((t) => /\bsrc\s*=/.test(t) && !/data-weline-theme-js/.test(t))
    .map((t) => (t.match(/\bsrc\s*=\s*["']([^"']+)["']/i) || [])[1])
    .filter(Boolean);

  await page.goto(url, { waitUntil: 'load', timeout: 45000 });
  await page.waitForTimeout(2500);
  const lcpSamples = [await readLcp(page)];
  for (let i = 0; i < 2; i++) {
    await page.reload({ waitUntil: 'load', timeout: 45000 });
    await page.waitForTimeout(1500);
    lcpSamples.push(await readLcp(page));
  }

  const measured = await page.evaluate(async (srcs) => {
    const paths = srcs.map((s) => { try { return new URL(s, location.href).pathname; } catch { return s; } });
    const resources = performance.getEntriesByType('resource')
      .filter((r) => paths.some((p) => r.name.includes(p)));
    const bytes = resources.reduce((sum, r) => sum + (r.decodedBodySize || r.transferSize || 0), 0);
    return { headJsBytes: bytes, headScriptsInDom: document.head.querySelectorAll('script[src]').length };
  }, headSrcs);
  const lcpMs = median(lcpSamples);

  await ctx.close();

  return {
    path,
    headExternal,
    markers,
    headJsBytes: measured.headJsBytes,
    lcpMs,
    badResponses: badResponses.length,
    guard403: guard403.length,
    consoleErrors: consoleErrors.length,
    docStatus,
  };
}

for (const path of PAGES) {
  let row = await measure(path);
  // WLS 攻击防护对连续无头访问可能返回 403：冷却后重测一次，避免门禁抖动。
  if (row.docStatus === 403 || (row.badResponses > 0 && row.headExternal === 0)) {
    await new Promise((r) => setTimeout(r, 5000));
    row = await measure(path);
  }
  results.push(row);

  const limit = baseline.pages?.[path];
  if (limit) {
    if (typeof limit.headExternalMax === 'number' && row.headExternal > limit.headExternalMax) {
      failures.push(`${path}: headExternal ${row.headExternal} > ${limit.headExternalMax}`);
    }
    if (typeof limit.headJsBytesMax === 'number' && row.headJsBytes > limit.headJsBytesMax) {
      failures.push(`${path}: headJsBytes ${row.headJsBytes} > ${limit.headJsBytesMax}`);
    }
    if (typeof limit.lcpMsMax === 'number' && row.lcpMs > limit.lcpMsMax) {
      failures.push(`${path}: lcpMs ${row.lcpMs} > ${limit.lcpMsMax}`);
    }
  }
  // 子资源 4xx/403 由 check_404_site.mjs / 站点审计单独把关；这里只在页面本体不可用时失败。
  if (row.docStatus >= 400) failures.push(`${path}: document status ${row.docStatus}`);
}

await browser.close();

for (const row of results) delete row.docStatus;

if (jsonOnly) {
  console.log(JSON.stringify({ baseUrl, results, failures }, null, 1));
} else {
  console.log(`head-budget @ ${baseUrl}`);
  console.table(results);
  if (failures.length) {
    console.log('FAIL:');
    failures.forEach((f) => console.log('  - ' + f));
  } else {
    console.log('OK: 全部指标在基线内');
  }
}

if (updateBaseline) {
  const pages = {};
  for (const row of results) {
    pages[row.path] = {
      headExternalMax: row.headExternal,
      headJsBytesMax: Math.round(row.headJsBytes * 1.15),
      lcpMsMax: Math.max(3000, Math.round(row.lcpMs * 1.5)),
    };
  }
  writeFileSync(baselinePath, JSON.stringify({ baseUrl, updatedAt: new Date().toISOString(), pages }, null, 2) + '\n');
  console.log('baseline updated: ' + baselinePath);
}

process.exit(failures.length ? 1 : 0);
