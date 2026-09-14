#!/usr/bin/env node
/**
 * Batch-extract 1688 unitWeight / pieceWeightScale → kg for product weight backfill.
 * Rate-limited; outputs JSON lines + summary file for remediate --weights-json.
 *
 * Usage:
 *   node app/code/Weline/Product/scripts/batch-extract-1688-weights.mjs \
 *     --plan=/tmp/weline-weight-batch-plan.json \
 *     --out=/tmp/weline-weights-extracted.json \
 *     --delay-ms=7000 --jitter-ms=2000 --limit=40
 */

import { createRequire } from 'node:module';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(resolve(process.cwd(), 'tests/e2e/node_modules/playwright/package.json'));
const { chromium } = require('.');

function arg(name, fallback = '') {
  const prefix = `--${name}=`;
  const hit = process.argv.find((a) => a.startsWith(prefix));
  return hit ? hit.slice(prefix.length) : fallback;
}
function flag(name) {
  return process.argv.includes(`--${name}`);
}

const planPath = arg('plan', '/tmp/weline-weight-batch-plan.json');
const outPath = arg('out', '/tmp/weline-weights-extracted.json');
const delayMs = Math.max(3000, Number(arg('delay-ms', '15000')) || 15000);
const jitterMs = Math.max(0, Number(arg('jitter-ms', '5000')) || 5000);
const limit = Math.max(1, Math.min(100, Number(arg('limit', '15')) || 15));
const headless = !flag('headed');
const storagePath = arg('storage', '/tmp/weline-1688-storage.json');
const warmUrl = arg('warm-url', 'https://www.1688.com/');
const coolDownOnBanMs = Math.max(0, Number(arg('cooldown-ms', '60000')) || 60000);

const plan = JSON.parse(readFileSync(planPath, 'utf8')).slice(0, limit);

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function extractKg(page) {
  return page.evaluate(() => {
    const pack = ((window.context || {}).result || {}).data?.productPackInfo?.fields || {};
    let unit = pack.unitWeight != null ? Number(pack.unitWeight) : 0;
    const scale = pack.pieceWeightScale || null;
    let isGrams = false;
    if (scale && Array.isArray(scale.columnList)) {
      for (const c of scale.columnList) {
        if (c && c.name === 'weight') {
          const label = String(c.label || '').toLowerCase();
          isGrams = label.includes('(g)') || label.includes('（g）') || label.includes('克');
          break;
        }
      }
    }
    const vals = (scale && Array.isArray(scale.pieceWeightScaleInfo)
      ? scale.pieceWeightScaleInfo
      : [])
      .map((r) => Number(r.weight))
      .filter((n) => n > 0);
    const uniq = [...new Set(vals.map(String))];
    let fromScale = null;
    if (vals.length && !(uniq.length === 1 && Math.abs(Number(uniq[0]) - 1) < 0.0001)) {
      const max = Math.max(...vals);
      const kg = isGrams ? max / 1000 : max;
      if (kg >= 0.05 && kg < 500) fromScale = Math.round(kg * 1000) / 1000;
    }
    const kg = unit > 0 && unit < 500 ? unit : fromScale;
    return {
      kg: kg == null ? null : kg,
      unitWeight: unit,
      fromScale,
      blocked: Boolean(
        document.documentElement.innerHTML.includes('_____tmd_____')
          || document.documentElement.innerHTML.includes('"action":"captcha"'),
      ),
      title: document.title || '',
    };
  });
}

const rows = [];
const summary = {
  planned: plan.length,
  updated: 0,
  skipped: 0,
  failed: 0,
  banned: false,
};

const browser = await chromium.launch({
  headless,
  args: ['--disable-blink-features=AutomationControlled'],
});
const contextOptions = {
  locale: 'zh-CN',
  userAgent:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
  viewport: { width: 1400, height: 900 },
};
if (existsSync(storagePath)) {
  contextOptions.storageState = storagePath;
}
const context = await browser.newContext(contextOptions);
const page = await context.newPage();

async function loadOffer(url) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  for (let i = 0; i < 25; i++) {
    const state = await page.evaluate(() => ({
      ready: !!(window.context && window.context.result && window.context.result.data),
      blocked: document.documentElement.innerHTML.includes('_____tmd_____')
        || document.documentElement.innerHTML.includes('"action":"captcha"')
        || location.href.includes('_____tmd_____'),
    }));
    if (state.ready) {
      return extractKg(page);
    }
    if (state.blocked && i >= 8) {
      return { kg: null, unitWeight: 0, fromScale: null, blocked: true, title: await page.title() };
    }
    await sleep(1000);
  }
  return extractKg(page);
}

try {
  if (warmUrl) {
    try {
      await page.goto(warmUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await sleep(3000);
    } catch {
      // warm-up is best-effort
    }
  }

  for (let i = 0; i < plan.length; i++) {
    const item = plan[i];
    const row = {
      product_id: item.id,
      sku: item.sku || '',
      source_url: item.url || '',
      offer_id: String(item.offer || ''),
      method: 'browser_batch',
    };
    if (i > 0) {
      await sleep(delayMs + (jitterMs > 0 ? Math.floor(Math.random() * (jitterMs + 1)) : 0));
    }
    try {
      let extracted = await loadOffer(item.url);
      if (extracted.blocked) {
        console.log(JSON.stringify({ phase: 'cooldown', product_id: item.id, ms: coolDownOnBanMs }));
        await sleep(coolDownOnBanMs);
        if (warmUrl) {
          try {
            await page.goto(warmUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
            await sleep(4000);
          } catch {
            // ignore
          }
        }
        extracted = await loadOffer(item.url);
      }
      if (extracted.blocked) {
        row.status = 'banned';
        row.error = '1688_blocked';
        summary.failed++;
        summary.banned = true;
        rows.push(row);
        console.log(JSON.stringify({ phase: 'stop_ban', row }));
        break;
      }
      if (extracted.kg == null || !(extracted.kg > 0)) {
        row.status = 'skipped';
        row.error = 'source_weight_empty';
        row.unitWeight = extracted.unitWeight;
        summary.skipped++;
        rows.push(row);
        console.log(JSON.stringify({ phase: 'row', row }));
        continue;
      }
      row.weight_kg = extracted.kg;
      row.status = 'ok';
      summary.updated++;
      rows.push(row);
      console.log(JSON.stringify({ phase: 'row', row }));
    } catch (e) {
      row.status = 'failed';
      row.error = String(e && e.message ? e.message : e);
      summary.failed++;
      rows.push(row);
      console.log(JSON.stringify({ phase: 'row', row }));
      if (/captcha|punish|blocked/i.test(row.error)) {
        summary.banned = true;
        console.log(JSON.stringify({ phase: 'stop_ban', row }));
        break;
      }
    }
  }
} finally {
  try {
    await context.storageState({ path: storagePath });
  } catch {
    // ignore
  }
  await browser.close();
}

const applyRows = rows
  .filter((r) => r.status === 'ok' && r.weight_kg > 0)
  .map((r) => ({
    product_id: r.product_id,
    weight_kg: r.weight_kg,
    method: r.method,
    sku: r.sku,
    source_url: r.source_url,
  }));

writeFileSync(
  outPath,
  JSON.stringify({ summary, rows, apply: applyRows }, null, 2),
  'utf8',
);
writeFileSync(
  outPath.replace(/\.json$/i, '.apply.json'),
  JSON.stringify(applyRows, null, 2),
  'utf8',
);
console.log(JSON.stringify({ phase: 'summary', summary, out: outPath, apply_count: applyRows.length }));
process.exit(summary.banned || summary.failed > 0 ? 1 : 0);
