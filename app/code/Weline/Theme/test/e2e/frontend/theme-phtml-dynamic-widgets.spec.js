// @weline-e2e-runtime wls
// @weline-e2e-transport direct
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../../../../../../..');
const { test, expect, moduleDescribe, moduleCase } = require(path.join(ROOT, 'tests/e2e/framework'));
const EVIDENCE = path.resolve(process.env.PHTML_OBSERVER_EVIDENCE || path.join(ROOT, 'dev/tmp/theme-phtml-dynamic-widgets'));
fs.mkdirSync(EVIDENCE, { recursive: true });
moduleDescribe(test, 'Weline_Theme', '部件动态加载观察器', () => {
  for (const width of [1440, 375]) {
  moduleCase(test, { module: 'Weline_Theme', id: `PHTML-WIDGET-OBSERVER-${width}` }, '页面加载后仍能初始化新增部件和更新实例样式', async ({ page }) => {
    await page.setViewportSize({ width, height: width === 375 ? 812 : 900 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const observerDetails = [];
    const pendingConsole = [];
    const assets = [];
    const pendingAssets = [];
    page.on('response', response => {
      if (!/(?:widget-(assets-runtime|instance-styles)|cart(?:-remove-pixel-stamp)?|selling-mode)\.js/.test(response.url())) return;
      pendingAssets.push((async () => {
        const body = await response.text();
        const headers = response.headers();
        assets.push({ url: response.url(), status: response.status(), coalesced: /(?:window|global)\.Weline\.dom\.observe/.test(body), sha256: require('crypto').createHash('sha256').update(body).digest('hex'), server: await response.serverAddr(), headers: Object.fromEntries(['x-wls-static-cache', 'x-wls-worker', 'age', 'etag', 'last-modified', 'cache-control'].filter(key => headers[key]).map(key => [key, headers[key]])) });
      })());
    });
    page.on('console', message => {
      if (message.type() !== 'error' || !/MutationObserver|Mutation flush loop/.test(message.text())) return;
      errors.push(message.text());
      pendingConsole.push((async () => {
        for (const arg of message.args()) {
          const value = await arg.jsonValue();
          if (value && typeof value === 'object') observerDetails.push({ reason: value.reason, label: value.label, observeStack: value.observeStack, createStack: value.createStack });
        }
      })());
    });
    const response = await page.goto(new URL('/mt_MT/checkout', process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com').toString(), { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await page.waitForTimeout(2000);
    const result = await page.evaluate(async () => {
      let initialized = 0;
      const host = document.createElement('section');
      document.body.appendChild(host);
      window.WelineWidgetAssets.register('observer-runtime-probe', () => { initialized++; });
      for (let i = 0; i < 80; i++) {
        host.appendChild(document.createElement('span'));
        await new Promise(resolve => setTimeout(resolve, 1));
      }
      const anchor = document.createElement('span');
      anchor.dataset.widgetScript = 'observer-runtime-probe';
      anchor.dataset.widgetStyle = 'color: rgb(1, 2, 3);';
      host.appendChild(anchor);
      const removeButton = document.createElement('button');
      removeButton.setAttribute('data-remove-item', 'observer-runtime-probe');
      host.appendChild(removeButton);
      const sellingRoot = document.createElement('div');
      sellingRoot.setAttribute('data-b2b-selling-mode', '1');
      host.appendChild(sellingRoot);
      await new Promise(resolve => setTimeout(resolve, 600));
      const initialColor = getComputedStyle(anchor).color;
      anchor.dataset.widgetStyle = 'color: rgb(4, 5, 6);';
      await new Promise(resolve => setTimeout(resolve, 600));
      const updatedColor = getComputedStyle(anchor).color;
      return { initialized, initialColor, updatedColor, removePixelEvent: removeButton.getAttribute('data-pixel-event'), sellingBound: sellingRoot.getAttribute('data-b2b-selling-bound'), framework_observe_available: typeof window.Weline?.dom?.observe === 'function' };
    });
    await Promise.all(pendingAssets);
    await Promise.all(pendingConsole);
    fs.writeFileSync(path.join(EVIDENCE, `observation-${width}.json`), JSON.stringify({ result, errors, assets, observerDetails }, null, 2));
    expect(result.initialized).toBe(1);
    expect(result.initialColor).toBe('rgb(1, 2, 3)');
    expect(result.updatedColor).toBe('rgb(4, 5, 6)');
    expect(result.removePixelEvent).toBe('remove_from_cart');
    expect(result.sellingBound).toBe('1');
    expect(errors).toEqual([]);
  });
  }
});
