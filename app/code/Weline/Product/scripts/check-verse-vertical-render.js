const { chromium } = require('@playwright/test');

/**
 * PDP 竖排诗侧栏（.weline-detail-prose--verse-vertical）渲染核查。
 *
 * 用途：判断「详情里出现竖着的文字」是修好了还是又被谁改回去了。
 * 必须核查**两个** locale：中文页要竖排（设计如此），非 CJK 页要横排。
 *
 * 用法（在仓库根执行；需要 tests/e2e 下的 @playwright/test）：
 *   NODE_PATH=tests/e2e/node_modules node app/code/Weline/Product/scripts/check-verse-vertical-render.js \
 *       --host=changanhanfu.com --port=443 \
 *       --zh=/zh_Hans_CN/product/<slug> --en=/product/<slug>
 *
 * 输出每个 URL 的：document lang、元素 lang、computed writing-mode / letter-spacing / max-height、
 * poem-aside 的 grid-template-columns，以及所加载 CSS 的缓存状态（x-wls-static-cache / x-wls-edge-cache）。
 *
 * 期望值：
 *   中文页 → writing-mode: vertical-rl、grid 仍是 minmax(4.5rem,.32fr) minmax(0,1.68fr)
 *   英文页 → writing-mode: horizontal-tb、max-height: none、grid 为 1fr 1fr
 */

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const i = a.indexOf('=');
    return i === -1 ? [a.replace(/^--/, ''), true] : [a.slice(2, i), a.slice(i + 1)];
  }),
);

const host = args.host || 'changanhanfu.com';
const port = args.port || '443';
const targets = Object.entries(args).filter(([k]) => k === 'zh' || k === 'en');

if (targets.length === 0) {
  console.error('至少给一个 --zh=<path> 或 --en=<path>');
  process.exit(2);
}

(async () => {
  const browser = await chromium.launch({
    args: ['--no-sandbox', `--host-resolver-rules=MAP ${host} 127.0.0.1`, '--ignore-certificate-errors'],
  });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1200 } });
  const page = await ctx.newPage();

  let cssState = null;
  page.on('response', async (r) => {
    if (r.url().includes('product-native-detail')) {
      const body = await r.text().catch(() => '');
      const h = r.headers();
      cssState = {
        url: r.url(),
        status: r.status(),
        wlsStatic: h['x-wls-static-cache'] || null,
        wlsEdge: h['x-wls-edge-cache'] || null,
        etag: h['etag'] || null,
        lastModified: h['last-modified'] || null,
        bytes: body.length,
        hasLangPseudoGuard: body.includes(':lang(zh)'),
        hasAttrOnlyGuard: body.includes(':not([lang^="zh" i]):not('),
      };
    }
  });

  for (const [locale, path] of targets) {
    const url = `https://${host}:${port}${path.startsWith('/') ? path : '/' + path}`;
    cssState = null;
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1200);

    const info = await page.evaluate(() => {
      const el = document.querySelector('.weline-detail-prose--verse-vertical');
      if (!el) return { found: false };
      const cs = getComputedStyle(el);
      const aside = el.closest('.weline-detail-feature--poem-aside');
      return {
        found: true,
        documentLang: document.documentElement.getAttribute('lang'),
        elementLang: el.getAttribute('lang'),
        writingMode: cs.writingMode,
        letterSpacing: cs.letterSpacing,
        maxHeight: cs.maxHeight,
        gridColumns: aside ? getComputedStyle(aside).gridTemplateColumns : null,
        textSample: (el.textContent || '').trim().slice(0, 60),
      };
    });

    console.log(`\n=== ${locale} :: ${url}`);
    console.log(JSON.stringify(info, null, 2));
    console.log('css:', JSON.stringify(cssState, null, 2));

    const expectVertical = locale === 'zh';
    if (info.found) {
      const ok = expectVertical
        ? info.writingMode === 'vertical-rl'
        : info.writingMode === 'horizontal-tb' && info.maxHeight === 'none' && /\d+px \d+px/.test(info.gridColumns || '');
      console.log(ok ? 'PASS ✅' : 'FAIL ❌ 期望 ' + (expectVertical ? 'vertical-rl' : 'horizontal-tb + max-height:none + 等宽两栏'));
    }
  }

  await browser.close();
})().catch((e) => {
  console.error('ERR', e.message);
  process.exit(1);
});
