// @weline-e2e-runtime wls
// @ts-check
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { test, expect, loginAsAdmin, buildBackendUrl } = require('../../../../../../../tests/e2e/framework');

const ROOT = path.resolve(__dirname, '../../../../../../..');
const manifestPath = process.env.THEME_PERF_MANIFEST || '';
const manifest = manifestPath && fs.existsSync(manifestPath)
  ? JSON.parse(fs.readFileSync(manifestPath, 'utf8')) : null;
const evidenceDir = process.env.THEME_PERF_EVIDENCE_DIR || path.join(ROOT, 'dev/tmp/theme-render-performance');
const stage = process.env.THEME_PERF_STAGE || 'preflight';
const timingPath = path.join(ROOT, 'var/log/wls/timing.log');
const traceCookie = 'w_weline_trace_panel';
const isTraceCookie = cookie => cookie.name === traceCookie || cookie.name === `${traceCookie}_w0`;
const observedOrigins = new WeakMap();
const traceControls = new WeakMap();

test.describe.configure({ mode: 'default', retries: 0, timeout: 1800000 });
test.use({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true,
  ...(manifest?.browser_loopback_mapping ? { launchOptions: {
    ignoreDefaultArgs: ['--enable-automation'],
    args: ['--ignore-certificate-errors', '--disable-blink-features=AutomationControlled', '--no-proxy-server',
      `--host-resolver-rules=MAP ${manifest.expected_host} 127.0.0.1, EXCLUDE localhost`],
  } } : {}),
});

function readManifest() {
  expect(manifestPath, '必须提供本轮冻结的 manifest，不能以 skipped 充当基线。').not.toBe('');
  expect(manifest, 'manifest 必须存在且可解析。').toBeTruthy();
  expect(manifest.server_cache_mode).toBe('normal');
  expect(manifest.request_headers?.['X-Wls-Fpc-Bypass']).toBe('1');
  expect(manifest.expected_host, '隔离Host必须来自本轮冻结的manifest。').toBeTruthy();
  expect(new URL(manifest.origin).hostname).toBe(manifest.expected_host);
  expect(Number(manifest.theme_id)).toBeGreaterThan(0);
  expect(manifest.editor_route).toContain(`theme_id=${manifest.theme_id}`);
  return manifest;
}

function logCursor() {
  if (!fs.existsSync(timingPath)) return { ino: null, size: 0 };
  const stat = fs.statSync(timingPath);
  return { ino: stat.ino, size: stat.size };
}

// 只读本次请求后的日志。trace 关闭时用严格 URI + 时间窗口匹配，歧义不猜 Worker。
function matchingTiming(cursor, url, requestId) {
  if (!fs.existsSync(timingPath)) return { available: false, reason: 'timing not created' };
  const stat = fs.statSync(timingPath);
  if ((cursor.ino !== null && stat.ino !== cursor.ino) || stat.size < cursor.size) return { available: false, reason: 'timing rotated' };
  const length = stat.size - cursor.size;
  if (length > 4 * 1024 * 1024) return { available: false, reason: 'timing window exceeds 4 MiB' };
  const fd = fs.openSync(timingPath, 'r');
  let content;
  try {
    const buffer = Buffer.alloc(length);
    fs.readSync(fd, buffer, 0, length, cursor.size);
    content = buffer.toString('utf8');
  } finally { fs.closeSync(fd); }
  const rows = content.split('\n').filter(Boolean).flatMap(line => {
    try { return [JSON.parse(line)]; } catch { return []; }
  });
  const uri = new URL(url).pathname + new URL(url).search;
  const byId = rows.filter(row => requestId && row.request_id === requestId);
  const exact = byId.length ? byId : rows.filter(row => row.method === 'GET' && row.uri === uri
    && row.router_profile?.stage === 'return' && row.router_profile?.uri === url);
  return exact.length === 1
    ? { available: true, match: byId.length ? 'request_id' : 'unique_uri_in_appended_window', record: exact[0] }
    : { available: false, reason: 'no unique timing match', candidates: exact.length };
}

function persist(name, value) {
  fs.mkdirSync(evidenceDir, { recursive: true });
  const file = path.join(evidenceDir, `${stage}-${name}.json`);
  fs.writeFileSync(file, JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
  return file;
}

async function clickWomenLink(frame, link) {
  try {
    await link.click({ timeout: 5000 });
  } catch (error) {
    const popup = frame.locator('[data-widget-code="newsletter-popup"].is-open').first();
    if (!await popup.isVisible()) throw error;
    await popup.locator('.popup-close').click();
    await link.click({ timeout: 30000 });
  }
}

async function preparePage(page) {
  const origins = new Map();
  observedOrigins.set(page, origins);
  page.on('request', request => {
    const url = new URL(request.url());
    const key = `${request.resourceType()} ${url.origin}`;
    origins.set(key, (origins.get(key) || 0) + 1);
  });
  await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  await page.setExtraHTTPHeaders(manifest.request_headers);
}

async function pageFacts(frame) {
  return frame.evaluate(() => {
    const scopes = [];
    for (const script of document.scripts) {
      for (const match of script.textContent.replace(/\\"/g, '"').matchAll(/"configScope"\s*:\s*(\{[^{}]*\})/g)) {
        try { scopes.push(JSON.parse(match[1])); } catch { /* 不把不完整序列化当 Scope 证据。 */ }
      }
    }
    const node = selector => [...document.querySelectorAll(selector)].map(el => ({
      tag: el.tagName, text: el.textContent.replace(/\s+/g, ' ').trim(),
      id: el.getAttribute('data-product-id') || el.getAttribute('data-id') || null,
    }));
    return { url: location.href, lang: document.documentElement.lang, scopes,
      html_attributes: Object.fromEntries([...document.documentElement.attributes].map(a => [a.name, a.value])),
      body_attributes: Object.fromEntries([...document.body.attributes].map(a => [a.name, a.value])),
      currencies: [...new Set([...document.querySelectorAll('[data-currency],[data-currency-code]')]
        .map(el => el.getAttribute('data-currency') || el.getAttribute('data-currency-code')).filter(Boolean))],
      header_count: document.querySelectorAll('header,[data-slot-id="header"]').length,
      footer_count: document.querySelectorAll('footer,.weline-footer,[data-slot-id="footer"]').length,
      result: node('[data-product-id]'), headings: node('h1'),
      runtime_error: /Fatal error|ParseError|WLS Runtime Error|historical_revision_head_missing/.test(document.body.innerText),
      navigation: performance.getEntriesByType('navigation').map(item => item.toJSON()),
    };
  });
}

async function capture(page, frame, response, cursor, started, role, trace, storefront = true) {
  expect(response, '必须取得真实 document 响应。').toBeTruthy();
  expect(response.status()).toBe(200);
  const headers = await response.allHeaders();
  const sentHeaders = await response.request().allHeaders();
  expect(sentHeaders['x-wls-fpc-bypass']).toBe('1');
  const fpc = Object.fromEntries(Object.entries(headers).filter(([key]) => /fpc/i.test(key)));
  expect(JSON.stringify(fpc)).not.toMatch(/\bHIT\b/i);
  const facts = await pageFacts(frame);
  expect(facts.runtime_error).toBe(false);
  if (storefront) {
    expect(facts.header_count).toBeGreaterThan(0);
    expect(facts.footer_count).toBeGreaterThan(0);
  }
  const timing = matchingTiming(cursor, response.url(), headers['x-weline-request-id']);
  expect(timing.available, JSON.stringify(timing)).toBe(true);
  if (manifest.instance_name) {
    expect(timing.record.instance).toBe(manifest.instance_name);
    expect(new URL(response.url()).origin).toBe(manifest.origin);
  }
  const result = { role, trace, started_at: started, completed_at: Date.now(), request_id: headers['x-weline-request-id'],
    wall_ms: Date.now() - started, url: response.url(), status: response.status(), fpc, sent_bypass: sentHeaders['x-wls-fpc-bypass'],
    response_timing: response.request().timing(), server_address: await response.serverAddr(), facts, timing,
    sent_trace_cookie_names: (sentHeaders.cookie || '').split(';').map(pair => pair.trim().split('=')[0])
      .filter(name => /^w_weline_trace_panel(?:_w\d+)?$/.test(name)),
    sent_trace_header_names: Object.keys(sentHeaders).filter(name => /trace|weline-request-id/i.test(name)),
    response_trace_header_names: Object.keys(headers).filter(name => /trace|weline-request-id/i.test(name)) };
  result.request_origins = Object.fromEntries(observedOrigins.get(page) || []);
  if (trace) {
    result.executed_sources = Object.keys(timing.record.trace_summary?.template_render_files?.files || {}).flatMap(filename => {
      const absolute = path.resolve(ROOT, filename);
      if (!absolute.startsWith(ROOT + path.sep) || !fs.existsSync(absolute)) return [];
      const encoded = fs.readFileSync(absolute, 'utf8').match(/weline-source:([A-Za-z0-9+/=]+)/)?.[1];
      if (!encoded) return [];
      try { return [{ file: filename, metadata: JSON.parse(Buffer.from(encoded, 'base64').toString('utf8')) }]; }
      catch { return []; }
    });
  }
  result.result_sha256 = crypto.createHash('sha256').update(JSON.stringify({ headings: facts.headings, result: facts.result })).digest('hex');
  if (Boolean(timing.record.trace_summary) !== trace) {
    persist(`trace-mismatch-${Date.now()}`, result);
  }
  expect(Boolean(timing.record.trace_summary), '主测关闭 trace，诊断单独开启。').toBe(trace);
  return result;
}

function roles() {
  return stage === 'preflight' ? ['first_observed']
    : ['first_observed', 'warmup_1', 'warmup_2', 'warmup_3', ...Array.from({ length: 20 }, (_, i) => `sample_${i + 1}`)];
}

function metrics(rows) {
  const samples = rows.filter(row => row.role.startsWith('sample_'));
  const values = key => samples.map(key).sort((a, b) => a - b);
  const summary = data => data.length ? { count: data.length,
    median: (data[Math.floor((data.length - 1) / 2)] + data[Math.ceil((data.length - 1) / 2)]) / 2,
    p95_nearest_rank: data[Math.ceil(data.length * 0.95) - 1], min: data[0], max: data.at(-1) } : null;
  return { app_ms: summary(values(row => row.timing.record.total_ms)),
    ttfb_ms: summary(values(row => row.response_timing.responseStart)),
    action_wall_ms: summary(values(row => row.wall_ms)),
    worker_samples: samples.map(row => ({ pid: row.timing.record.pid, worker_id: row.timing.record.worker_id,
      request_count: row.timing.record.request_count })), p95_is_descriptive_not_sla: true };
}

async function setTrace(page, enabled) {
  let config = traceControls.get(page);
  if (enabled || !config) {
    await page.waitForFunction(() => Boolean(window.__WELINE_PANEL_CONFIG__?.apiBase), null, { timeout: 30000 });
    config = await page.evaluate(() => ({ apiBase: window.__WELINE_PANEL_CONFIG__.apiBase,
      tokenRequired: window.__WELINE_PANEL_CONFIG__.tokenRequired }));
    traceControls.set(page, config);
  }
  const base = String(config.apiBase).replace(/^\/+|\/+$/g, '');
  const endpoint = new URL(`${base}/trace/panel`, new URL('/', page.url()));
  const response = manifest?.browser_loopback_mapping
    ? await page.evaluate(async ({ endpoint, enabled }) => {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Weline-Panel': '1', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ enabled }) });
      return { status: response.status, ok: response.ok, body: await response.text() };
    }, { endpoint: endpoint.href, enabled })
    : await page.request.post(endpoint.href, {
      data: { enabled }, headers: { 'X-Weline-Panel': '1', 'X-Requested-With': 'XMLHttpRequest' },
    });
  const body = typeof response.body === 'string' ? response.body : await response.text();
  const status = typeof response.status === 'number' ? response.status : response.status();
  persist(`trace-switch-${Date.now()}`, { enabled, endpoint: endpoint.href, status, body,
    token_required: config.tokenRequired, cookie_names: (await page.context().cookies()).map(cookie => cookie.name) });
  expect(typeof response.ok === 'boolean' ? response.ok : response.ok(), body).toBe(true);
  expect((await page.context().cookies()).some(cookie => isTraceCookie(cookie) && cookie.value)).toBe(enabled);
}

for (const pathname of ['/products', '/category/women']) {
  test(`[case:THEME-PERF-${pathname === '/products' ? 'PRODUCTS' : 'CATEGORY'}] ordinary published dynamic rendering`, async ({ page }, testInfo) => {
    const config = readManifest();
    await preparePage(page);
    const rows = [];
    try {
      for (const role of roles()) {
        expect((await page.context().cookies()).some(isTraceCookie)).toBe(false);
        const cursor = logCursor(); const started = Date.now();
        const response = await page.goto(new URL(pathname, config.origin).href, { waitUntil: 'load', timeout: 120000 });
        rows.push(await capture(page, page, response, cursor, started, role, false));
        persist(pathname.replaceAll('/', '_'), { rows, metrics: metrics(rows), manifest: config });
      }
      if (process.env.THEME_PERF_DIAGNOSTICS !== '0') {
        await setTrace(page, true);
        try {
          for (let i = 0; i < (stage === 'preflight' ? 1 : 2); i += 1) {
            const cursor = logCursor(); const started = Date.now();
            const response = await page.goto(new URL(pathname, config.origin).href, { waitUntil: 'load', timeout: 120000 });
            rows.push(await capture(page, page, response, cursor, started, `diagnostic_${i + 1}`, true));
          }
        } finally {
          await setTrace(page, false);
        }
      }
    } finally {
      const file = persist(pathname.replaceAll('/', '_'), { rows, metrics: metrics(rows), manifest: config });
      await testInfo.attach('performance-evidence', { path: file, contentType: 'application/json' });
    }
  });
}

test('[case:THEME-PERF-EDITOR] editor browses Women then refreshes the selected category layout', async ({ page }, testInfo) => {
  const config = readManifest();
  await preparePage(page);
  await loginAsAdmin(page, { timeout: 120000, settleMs: 0 });
  const rows = [];
  const traceFlow = process.env.THEME_PERF_EDITOR_TRACE === '1';
  if (traceFlow) expect(stage, '单次诊断不能混入主采样。').toBe('preflight');
  const httpObservations = new Map();
  const pendingHeaders = [];
  if (traceFlow) {
    page.on('request', request => {
      const url = new URL(request.url());
      if (!request.isNavigationRequest() && url.pathname !== '/api/framework/query-bin') return;
      const record = { started_at: Date.now(), method: request.method(),
        origin: url.origin, path: url.pathname, navigation: request.isNavigationRequest() };
      httpObservations.set(request, record);
      pendingHeaders.push(request.allHeaders().then(headers => {
        record.trace_cookie_names = (headers.cookie || '').split(';').map(pair => pair.trim().split('=')[0])
          .filter(name => /^w_weline_trace_panel(?:_w\d+)?$/.test(name));
        record.request_trace_header_names = Object.keys(headers).filter(name => /trace|weline-request-id/i.test(name));
      }).catch(error => { record.request_header_error = String(error); }));
    });
    page.on('response', response => {
      const record = httpObservations.get(response.request());
      if (!record) return;
      record.response_at = Date.now(); record.status = response.status();
      pendingHeaders.push(response.allHeaders().then(headers => {
        record.request_id = headers['x-weline-request-id'] || null;
        record.response_trace_header_names = Object.keys(headers).filter(name => /trace|weline-request-id/i.test(name));
        record.response_timing = response.request().timing();
      }).catch(error => { record.header_error = String(error); }));
    });
    page.on('requestfailed', request => {
      const record = httpObservations.get(request);
      if (record) { record.failed_at = Date.now(); record.failure = request.failure()?.errorText || 'unknown'; }
    });
  }
  const editorUrl = buildBackendUrl(config.editor_route, { useProxy: false });
  try {
    if (traceFlow) await setTrace(page, true);
    for (const role of roles()) {
      const started = Date.now();
      const cursor = logCursor();
      const shellResponse = await page.goto(editorUrl, { waitUntil: 'load', timeout: 120000 });
      const shell = await capture(page, page, shellResponse, cursor, started, role, traceFlow, false);
      shell.scenario = 'editor_shell_open'; rows.push(shell);
      await expect(page.locator('#previewFrame')).toHaveAttribute('src', /editor_mode=1/);
      await page.waitForFunction(() => Boolean(window.Weline?.Theme?.Editor?.state?.themeId), null, { timeout: 60000 });
      const frame = page.frameLocator('#previewFrame');
      await expect(frame.locator('html')).toHaveAttribute('data-w-editor-preview-engine', 'full', { timeout: 120000 });
      const interaction = page.locator('[data-theme-editor-action="set-interaction-mode"][data-interaction-mode="preview"]');
      if (await interaction.count()) {
        await interaction.first().click();
        await expect(interaction.first()).toHaveAttribute('aria-pressed', 'true');
      }
      const linkBlock = page.locator('[data-theme-editor-action="toggle-link-block"]');
      if (await linkBlock.first().getAttribute('aria-pressed') === 'true') await linkBlock.first().click();
      const women = frame.locator('a[href*="/category/women"]:visible').first();
      await expect(women, 'Women 必须通过真实可见链接选择，不能直接覆写 iframe src。').toBeVisible({ timeout: 120000 });
      const beforeFrame = await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame());
      persist(`editor-before-click-${Date.now()}`, {
        href: await women.getAttribute('href'), resolved_href: await women.evaluate(link => link.href),
        iframe_src: await page.locator('#previewFrame').getAttribute('src'), iframe_location: beforeFrame.url(),
        parent_location: page.url(),
        iframe_html_attributes: await beforeFrame.evaluate(() => Object.fromEntries([...document.documentElement.attributes].map(a => [a.name, a.value]))),
        state: await page.evaluate(() => ({ themeId: window.Weline?.Theme?.Editor?.state?.themeId,
          canvasRoute: window.Weline?.Theme?.Editor?.state?.canvasRoute,
          interactionMode: window.Weline?.Theme?.Editor?.state?.interactionMode })),
      });
      const selectedResponse = page.waitForResponse(response => response.request().isNavigationRequest()
        && new URL(response.url()).pathname.endsWith('/category/women'), { timeout: 120000 });
      await clickWomenLink(frame, women);
      const response = await selectedResponse;
      await response.finished();
      const redirectChain = [];
      for (let request = response.request(); request; request = request.redirectedFrom()) {
        const headers = await request.allHeaders();
        redirectChain.unshift({ url: request.url(), method: request.method(), host: headers.host || headers[':authority'] || null,
          status: (await request.response())?.status() });
      }
      const actualFrame = await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame());
      await actualFrame.waitForLoadState('load');
      persist(`editor-navigation-${Date.now()}`, { response_url: response.url(), iframe_src: await page.locator('#previewFrame').getAttribute('src'),
        iframe_location: actualFrame.url(), redirect_chain: redirectChain,
        state: await page.evaluate(() => ({ canvasRoute: window.Weline?.Theme?.Editor?.state?.canvasRoute,
          interactionMode: window.Weline?.Theme?.Editor?.state?.interactionMode,
          pageType: window.Weline?.Theme?.Editor?.state?.pageType })) });
      expect(new URL(actualFrame.url()).pathname).toBe('/category/women');
      expect(new URL(actualFrame.url()).searchParams.get('theme_id')).toBe(String(config.theme_id));
      const entry = await capture(page, actualFrame, response, cursor, started, role, traceFlow);
      entry.scenario = 'editor_open_and_select_women';
      rows.push(entry);
      persist('editor', { rows, manifest: config });
    }
    for (const role of roles()) {
      // Rebuild the selected canvas state for each refresh. Repeatedly refreshing one
      // long-lived iframe can leave it blank without issuing a navigation request.
      await page.goto(editorUrl, { waitUntil: 'load', timeout: 120000 });
      await expect(page.locator('#previewFrame')).toHaveAttribute('src', /editor_mode=1/);
      const setupFrame = page.frameLocator('#previewFrame');
      await expect(setupFrame.locator('html')).toHaveAttribute('data-w-editor-preview-engine', 'full', { timeout: 120000 });
      const setupInteraction = page.locator('[data-theme-editor-action="set-interaction-mode"][data-interaction-mode="preview"]');
      if (await setupInteraction.count()) {
        await setupInteraction.first().click();
        await expect(setupInteraction.first()).toHaveAttribute('aria-pressed', 'true');
      }
      const setupLinkBlock = page.locator('[data-theme-editor-action="toggle-link-block"]');
      if (await setupLinkBlock.first().getAttribute('aria-pressed') === 'true') await setupLinkBlock.first().click();
      const setupWomen = setupFrame.locator('a[href*="/category/women"]:visible').first();
      await expect(setupWomen).toBeVisible({ timeout: 120000 });
      const setupSelected = page.waitForResponse(response => response.request().isNavigationRequest()
        && new URL(response.url()).pathname.endsWith('/category/women'), { timeout: 120000 });
      await clickWomenLink(setupFrame, setupWomen);
      await (await setupSelected).finished();
      await (await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame())).waitForLoadState('load');
      const cursor = logCursor(); const started = Date.now();
      const refreshedResponse = page.waitForResponse(response => response.request().isNavigationRequest()
        && new URL(response.url()).pathname === '/category', { timeout: 120000 });
      await page.locator('#btnRefreshPreview').click();
      const response = await refreshedResponse;
      await response.finished();
      const actualFrame = await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame());
      await actualFrame.waitForLoadState('load');
      expect(new URL(actualFrame.url()).pathname).toBe('/category');
      expect(new URL(actualFrame.url()).searchParams.get('theme_id')).toBe(String(config.theme_id));
      const entry = await capture(page, actualFrame, response, cursor, started, role, traceFlow);
      entry.scenario = 'editor_refresh_category'; rows.push(entry);
      persist('editor', { rows, manifest: config });
    }
    if (!traceFlow && process.env.THEME_PERF_DIAGNOSTICS !== '0') {
      await setTrace(page, true);
      for (let i = 0; i < (stage === 'preflight' ? 1 : 2); i += 1) {
        const cursor = logCursor(); const started = Date.now();
        const refreshedResponse = page.waitForResponse(response => response.request().isNavigationRequest()
          && new URL(response.url()).pathname === '/category', { timeout: 120000 });
        await page.locator('#btnRefreshPreview').click();
        const response = await refreshedResponse; await response.finished();
        const actualFrame = await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame());
        await actualFrame.waitForLoadState('load');
        const entry = await capture(page, actualFrame, response, cursor, started, `diagnostic_${i + 1}`, true);
        entry.scenario = 'editor_refresh_category'; rows.push(entry);
      }
      await setTrace(page, false);
    }
  } finally {
    try {
      if (traceFlow) await setTrace(page, false);
    } finally {
      // Aborted navigations can leave auxiliary allHeaders() unresolved; sampled headers were awaited in capture().
      let headerObservationWait = null;
      let settledHeaderCount = 0;
      const observedHeaderCount = pendingHeaders.length;
      await Promise.race([
        Promise.allSettled(pendingHeaders.map(promise => promise.finally(() => { settledHeaderCount += 1; }))),
        new Promise(resolve => { headerObservationWait = setTimeout(resolve, 3000); }),
      ]);
      if (headerObservationWait) clearTimeout(headerObservationWait);
      const scenarios = [...new Set(rows.map(row => row.scenario))];
      const file = persist('editor', { rows, manifest: config, trace_flow: traceFlow,
        http_observations: [...httpObservations.values()],
        auxiliary_headers: { observed: observedHeaderCount, settled: settledHeaderCount,
          unsettled: observedHeaderCount - settledHeaderCount, maximum_wait_ms: 3000 },
        database_request_attribution: 'Join request_id to WLS PID, then sampled TCP client port to PG PID; concurrent requests remain ambiguous.',
        metrics: Object.fromEntries(scenarios.map(scenario => [scenario, metrics(rows.filter(row => row.scenario === scenario))])) });
      await testInfo.attach('performance-evidence', { path: file, contentType: 'application/json' });
    }
  }
});

test('[case:THEME-PERF-EDITOR-DIAGNOSTIC] captures one signed-trace initial preview without changing draft data', async ({ page }, testInfo) => {
  const config = readManifest();
  await preparePage(page);
  await loginAsAdmin(page, { timeout: 120000, settleMs: 0 });
  expect(new URL(page.url()).origin).toBe(config.origin);
  await setTrace(page, true);
  const cursor = logCursor();
  const started = Date.now();
  const rows = [];
  try {
    const previewResponse = page.waitForResponse(response => response.request().isNavigationRequest()
      && new URL(response.url()).searchParams.get('editor_mode') === '1', { timeout: 120000 });
    const shell = await page.goto(buildBackendUrl(config.editor_route, { useProxy: false }), { waitUntil: 'load', timeout: 120000 });
    const response = await previewResponse;
    await response.finished();
    const actualFrame = await page.locator('#previewFrame').elementHandle().then(handle => handle.contentFrame());
    await actualFrame.waitForLoadState('load');
    const headers = await response.allHeaders();
    rows.push({ scenario: 'initial_editor_preview_diagnostic', started_at: started, status: response.status(),
      shell_status: shell.status(), url: response.url(), request_id: headers['x-weline-request-id'],
      server_address: await response.serverAddr(), timing: matchingTiming(cursor, response.url(), headers['x-weline-request-id']),
      facts: await pageFacts(actualFrame), request_origins: Object.fromEntries(observedOrigins.get(page) || []) });
    await page.screenshot({ path: path.join(evidenceDir, 'editor-diagnostic.png'), fullPage: true });
    expect(rows[0].timing.available).toBe(true);
    expect(Boolean(rows[0].timing.record.trace_summary)).toBe(true);
  } finally {
    const file = persist('editor-single-diagnostic', { rows, manifest: config });
    await testInfo.attach('diagnostic-evidence', { path: file, contentType: 'application/json' });
    await setTrace(page, false);
  }
});
