// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { createHash } = require('crypto');
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const ROOT = path.resolve(__dirname, '../../../../../../..');
const EVIDENCE = path.resolve(process.env.PHTML_EVIDENCE_DIR || path.join(ROOT, 'dev/tmp/theme-phtml-solidification'));
const FIXTURE_PATH = path.resolve(process.env.PHTML_FIXTURE_PATH || path.join(EVIDENCE, 'runtime-browser-store.json'));
let FIXTURE;
const SCRIPT = path.join(__dirname, 'theme-phtml-solidification-fixture.php');
const NET_LOG_PATH = process.env.PHTML_NETLOG_PATH ? path.resolve(process.env.PHTML_NETLOG_PATH) : '';
const NET_LOG_LABEL = process.env.PHTML_NETLOG_LABEL || 'runtime-netlog';
if (NET_LOG_PATH) {
  if (!/^[a-z0-9-]+$/.test(NET_LOG_LABEL)) throw new Error('Invalid PHTML NetLog evidence label.');
  if (!NET_LOG_PATH.startsWith(`${EVIDENCE}${path.sep}`)) throw new Error('PHTML NetLog must remain in this task evidence directory.');
  fs.closeSync(fs.openSync(NET_LOG_PATH, 'a', 0o600));
  fs.chmodSync(NET_LOG_PATH, 0o600);
  const configured = require(path.join(ROOT, 'tests/e2e/playwright.config.js')).projects.find(project => project.name === 'chromium').use.launchOptions;
  test.use({ launchOptions: { ...configured, args: [...(configured.args || []), `--log-net-log=${NET_LOG_PATH}`] } });
}

function fixtureAction(action, options = {}) {
  const stdout = execFileSync('php', [SCRIPT], { cwd: ROOT, input: JSON.stringify({ action, fixture_path: FIXTURE_PATH, evidence_dir: EVIDENCE, ...options }), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  return JSON.parse(stdout.trim().split('\n').filter(line => line.startsWith('{')).pop());
}

function inspect(options = {}) { return fixtureAction('inspect', options); }
function readRevisionStatus() { return fixtureAction('revision_status'); }

function editorRequestObservation(page) {
  const packets = page.__phtmlQueryPackets || [];
  const output = execFileSync('php', [path.join(__dirname, 'theme-phtml-decode-requests.php')], { cwd: ROOT, input: JSON.stringify(packets), encoding: 'utf8' });
  const decoded = JSON.parse(output);
  return { decoded, calls: decoded.flatMap(packet => packet.calls), direct: page.__phtmlDirectEditorRequests || [] };
}

function assertNoAutomaticReconcile(observation) {
  expect(observation.decoded.filter(packet => packet.decode_error), '真实请求必须成功解码，不能把未观测当作零调用').toHaveLength(0);
  expect(observation.calls.length, '实际BinQuery必须有可观察的editorRequest').toBeGreaterThan(0);
  expect([...observation.calls, ...observation.direct].filter(call => call.method === 'POST' && call.path.endsWith('/reconcile-required-defaults')), '读取/切语言不得自动提交默认注入').toHaveLength(0);
}

function evidence(name, value) {
  fs.writeFileSync(path.join(EVIDENCE, `${name}.json`), JSON.stringify(value, (key, item) => {
    if (/^(?:csrf(?:_?token)?|cookie|set-cookie|preview_token|access_token|session_id)$/i.test(key)) return '[redacted]';
    if (key === 'token' && item !== FIXTURE.token) return '[redacted]';
    if (typeof item === 'string') return item.replace(/(cookie:)[^\r\n]*/gi, '$1 [redacted]').replace(/([?&](?:token|(?:weline_)?preview_token|access_token)=)[^&#\s"<>]*/gi, '$1[redacted]').replace(/(["'](?:csrf(?:_?token)?|session_id|preview_token|access_token)["']\s*:\s*["'])[^"']*(["'])/gi, '$1[redacted]$2');
    return item;
  }, 2));
}

function pendingNetwork(page) {
  return Array.from(page.__phtmlPending?.entries() || []).map(([req, item]) => ({ ...item, elapsed_ms: Date.now() - item.started, timing: req.timing() })).sort((a, b) => a.started - b.started);
}

function publicUrl(value) {
  try {
    const url = new URL(value);
    for (const key of Array.from(url.searchParams.keys())) if (/token/i.test(key)) url.searchParams.set(key, '[redacted]');
    return url.toString();
  } catch { return value; }
}

async function previewFrameState(page, nodeUid) {
  const element = await page.locator('#previewFrame').elementHandle();
  const frame = await element?.contentFrame();
  const state = { iframe_src: await element?.getAttribute('src'), frame_url: frame?.url(), document_requests: page.__phtmlDocuments || [] };
  if (!frame) return state;
  try {
    state.document = await frame.evaluate(uid => {
      const body = document.body;
      const wrapper = body?.querySelector(`.widget-wrapper[data-node-uid="${uid}"]`);
      return {
        href: location.href, readyState: document.readyState, body_exists: Boolean(body),
        navigation: performance.getEntriesByType('navigation').map(entry => entry.toJSON()),
        resources: performance.getEntriesByType('resource').map(entry => entry.toJSON()),
        wrapper: wrapper?.outerHTML.slice(0, 6000),
        attributes: Object.fromEntries(Array.from(wrapper?.attributes || []).map(attribute => [attribute.name, attribute.value])),
        html: { ...document.documentElement?.dataset }, body: { ...body?.dataset },
        widgetActionsEventsBound: body?._widgetActionsEventsBound, sortableDelegationBound: body?._sortableDelegationBound,
        hasHoverActions: Boolean(wrapper?.querySelector('.widget-hover-actions')),
        wrapper_inventory: Array.from(body?.querySelectorAll('.widget-wrapper') || []).map(element => ({ ...element.dataset })),
        images: Array.from(wrapper?.querySelectorAll('img') || []).map(img => ({ src: img.src, currentSrc: img.currentSrc, alt: img.alt, loading: img.loading, complete: img.complete, naturalWidth: img.naturalWidth })),
        last_scripts: Array.from(document.scripts).slice(-5).map(script => script.outerHTML), body_tail: body?.innerHTML.slice(-1500),
        scripts: Array.from(document.scripts).map(script => script.src).filter(Boolean),
        globals: Object.keys(window).filter(key => /weline|theme.*editor|editor.*theme/i.test(key)), messages: window.__phtmlObservedMessages,
      };
    }, nodeUid);
  } catch (error) { state.read_error = error.message; }
  return state;
}

async function boundedObservation(observe, timeout = 15000) {
  let timer;
  try {
    return await Promise.race([observe(), new Promise(resolve => { timer = setTimeout(() => resolve({ diagnostic_error: `observation exceeded ${timeout}ms` }), timeout); })]);
  } catch (error) { return { diagnostic_error: error.message }; }
  finally { clearTimeout(timer); }
}

async function readCanvasHttp(page, url) {
  if (!url) return { diagnostic_error: 'no observed iframe URL' };
  const started = Date.now();
  try {
    const response = await page.request.get(new URL(url, page.url()).toString(), { timeout: 20000 });
    const html = await response.text();
    const wrapperTags = (html.match(/<[A-Za-z][^>]*>/g) || []).filter(tag => /\bclass=["'][^"']*\bwidget-wrapper\b/.test(tag));
    return {
      url, started, ended: Date.now(), elapsed_ms: Date.now() - started, status: response.status(), bytes: Buffer.byteLength(html), request_id: response.headers()['x-weline-request-id'],
      all_menu_count: wrapperTags.filter(tag => /\bdata-widget-code=["']all-menu["']/.test(tag)).length,
      store_music_count: wrapperTags.filter(tag => /\bdata-widget-code=["']store-music["']/.test(tag)).length,
      wrapper_tags: wrapperTags, error_body: response.status() >= 400 ? html.slice(0, 2000) : undefined,
    };
  } catch (error) { return { url, started, ended: Date.now(), elapsed_ms: Date.now() - started, transport_error: error.message }; }
}

async function readBrowserBoundary(page) {
  const context = page.context();
  const frames = await Promise.all(page.frames().map(frame => boundedObservation(async () => ({
    url: frame.url(),
    state: await frame.evaluate(async () => ({
      service_worker_available: Boolean(navigator.serviceWorker),
      controller: navigator.serviceWorker?.controller?.scriptURL || null,
      registrations: navigator.serviceWorker ? (await navigator.serviceWorker.getRegistrations()).map(registration => ({ scope: registration.scope, active: registration.active?.scriptURL, waiting: registration.waiting?.scriptURL, installing: registration.installing?.scriptURL })) : [],
    })),
  }))));
  const url = new URL('/Weline/Frontend/view/statics/js/weline-api-business.js?v=1.0.0_1790593507', page.url()).toString();
  const started = Date.now();
  let staticResponse;
  try {
    const response = await context.request.get(url, { timeout: 20000 });
    const bytes = await response.body();
    staticResponse = { url, started, ended: Date.now(), elapsed_ms: Date.now() - started, status: response.status(), bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex'), headers: response.headers(), request_id: response.headers()['x-weline-request-id'] };
  } catch (error) { staticResponse = { url, started, ended: Date.now(), transport_error: error.message }; }
  evidence('runtime-browser-boundary-readonly', { context_service_workers: context.serviceWorkers().map(worker => worker.url()), frames, static_response: staticResponse });
}

function requestTrace(requestId) {
  const result = fixtureAction('trace', { request_id: requestId });
  if (!requestId) return result;
  try {
    const lines = execFileSync('rg', ['-F', requestId, path.join(ROOT, 'var/log/wls/timing.log')], { encoding: 'utf8' });
    result.timing = lines.trim().split('\n').filter(Boolean).map(line => {
      try { return JSON.parse(line); } catch { return { raw: line }; }
    });
    result.source_execution = result.timing.filter(item => item.trace_summary).map(item => {
      const summary = item.trace_summary;
      const derived = [];
      for (const [file, stats] of Object.entries(summary.template_render_files?.files || {})) {
        const absolute = path.resolve(ROOT, file);
        if (!absolute.startsWith(`${ROOT}${path.sep}`) || !fs.existsSync(absolute)) continue;
        const match = fs.readFileSync(absolute, 'utf8').match(/weline-source:([A-Za-z0-9+/=]+)/);
        if (!match) continue;
        try {
          const metadata = JSON.parse(Buffer.from(match[1], 'base64').toString('utf8'));
          if (metadata.identity?.theme_id === FIXTURE.theme_id && metadata.identity?.canonical_scope === FIXTURE.scope) derived.push({ file, calls: stats.calls, bytes: stats.bytes, metadata });
        } catch { /* 非来源元数据不能当作固化页面执行证据。 */ }
      }
      return {
        request_id: requestId, worker_id: item.worker_id, truncated: summary.truncated, dropped_span_count: summary.dropped_span_count,
        overflow_calls: summary.template_render_files?.overflow_calls, db_span_count: summary.db_span_count, derived_templates: derived,
        retired_phases: Object.fromEntries(['theme.layout_slot.zero_runtime_fill', 'theme.layout_slot.safety_net_heal', 'theme.layout_slot.narrow_filter_heal'].map(name => [name, summary.phases?.[name] || 0])),
      };
    });
  } catch (error) {
    result.timing_unavailable = error.status === 1 ? 'no matching timing record' : error.message;
  }
  return result;
}

async function openEditor(page, options = {}) {
  const wire = [];
  const errors = [];
  const pending = new Map();
  const documents = [];
  page.__phtmlPending = pending;
  page.__phtmlDocuments = documents;
  if (options.observeEditorRequests) {
    page.__phtmlQueryPackets = [];
    page.__phtmlDirectEditorRequests = [];
    page.on('request', req => {
      const pathname = new URL(req.url()).pathname;
      if (pathname.includes('/theme-editor/')) page.__phtmlDirectEditorRequests.push({ started: Date.now(), method: req.method(), path: pathname });
      if (req.method() !== 'POST' || !pathname.endsWith('/query-bin')) return;
      const bytes = req.postDataBuffer();
      if (bytes) page.__phtmlQueryPackets.push({ started: Date.now(), sha256: createHash('sha256').update(bytes).digest('hex'), bytes: bytes.toString('base64') });
    });
  }
  if (options.networkDiagnostics) {
    const events = [];
    page.__phtmlNetworkEvents = events;
    const connection = await page.context().newCDPSession(page);
    await connection.send('Network.enable');
    const append = item => { events.push(item); if (events.length > 2000) events.shift(); };
    connection.on('Network.requestWillBeSent', item => append({ event: 'request', id: item.requestId, at: item.timestamp, wall_time: item.wallTime, type: item.type, frame: item.frameId, loader: item.loaderId, url: publicUrl(item.request.url), initiator: item.initiator?.type }));
    connection.on('Network.responseReceived', item => append({ event: 'response', id: item.requestId, at: item.timestamp, type: item.type, frame: item.frameId, url: publicUrl(item.response.url), status: item.response.status, protocol: item.response.protocol, connection_id: item.response.connectionId, timing: item.response.timing, from_disk_cache: item.response.fromDiskCache, from_service_worker: item.response.fromServiceWorker }));
    connection.on('Network.loadingFinished', item => append({ event: 'finished', id: item.requestId, at: item.timestamp, encoded_bytes: item.encodedDataLength }));
    connection.on('Network.loadingFailed', item => append({ event: 'failed', id: item.requestId, at: item.timestamp, type: item.type, error: item.errorText, canceled: item.canceled, blocked_reason: item.blockedReason }));
  }
  page.on('request', req => {
    let frameUrl = '';
    try { const url = new URL(req.frame().url()); for (const key of Array.from(url.searchParams.keys())) if (/token/i.test(key)) url.searchParams.set(key, '[redacted]'); frameUrl = url.toString(); } catch { /* worker或初始空frame没有URL。 */ }
    const requestUrl = new URL(req.url());
    for (const key of Array.from(requestUrl.searchParams.keys())) if (/token/i.test(key)) requestUrl.searchParams.set(key, '[redacted]');
    pending.set(req, { url: new URL(req.url()).origin + new URL(req.url()).pathname, request_url: requestUrl.toString(), type: req.resourceType(), frame_url: frameUrl, started: Date.now() });
    if (req.resourceType() === 'document') documents.push({ event: 'request', at: Date.now(), url: requestUrl.toString(), frame_url: frameUrl });
  });
  page.on('requestfinished', req => { if (req.resourceType() === 'document') documents.push({ event: 'finished', at: Date.now(), url: publicUrl(req.url()), timing: req.timing() }); pending.delete(req); });
  page.on('requestfailed', req => { if (req.resourceType() === 'document') documents.push({ event: 'failed', at: Date.now(), url: publicUrl(req.url()), timing: req.timing(), failure: req.failure() }); pending.delete(req); });
  page.on('framenavigated', frame => documents.push({ event: 'navigated', at: Date.now(), url: publicUrl(frame.url()), main_frame: frame === page.mainFrame() }));
  page.on('pageerror', error => { errors.push(error.message); evidence('runtime-browser-errors', errors); });
  page.on('console', message => { if (message.type() === 'error') { errors.push(message.text().slice(0, 1400)); evidence('runtime-browser-errors', errors); } });
  page.on('requestfailed', req => { errors.push({ url: new URL(req.url()).origin + new URL(req.url()).pathname, failure: req.failure()?.errorText }); evidence('runtime-browser-errors', errors); });
  page.on('response', async response => {
    const pendingRequest = pending.get(response.request());
    if (pendingRequest) pendingRequest.response_received = { at: Date.now(), status: response.status(), headers: response.headers() };
    if (response.request().resourceType() === 'document') documents.push({ event: 'response', at: Date.now(), url: publicUrl(response.url()), status: response.status(), headers: response.headers() });
    const route = new URL(response.url()).pathname;
    if (response.status() >= 400) { errors.push({ url: new URL(response.url()).origin + route, http: response.status() }); evidence('runtime-browser-errors', errors); }
    if (response.status() >= 400 && response.request().resourceType() === 'document') {
      const publicUrl = new URL(response.url());
      for (const key of Array.from(publicUrl.searchParams.keys())) if (/token/i.test(key)) publicUrl.searchParams.set(key, '[redacted]');
      try { evidence('runtime-browser-document-error', { route, url: publicUrl.toString(), http: response.status(), request_id: response.headers()['x-weline-request-id'], body: (await response.text()).slice(0, 12000) }); } catch { /* 连接断开已由 requestfailed 留证。 */ }
    }
    const target = /theme-editor\/(?:save-widget-config|update-config|widget-config|scope-versions|save-layout-config)$/.test(route);
    if (!target && !(response.headers()['content-type'] || '').includes('json')) return;
    try {
      const result = await response.json();
      const nestedFailures = [];
      const collectFailures = (value, depth = 0) => {
        if (!value || typeof value !== 'object' || depth > 6 || nestedFailures.length > 20) return;
        if (value.success === false || value.error) nestedFailures.push({ code: value.code, message: value.message, error: typeof value.error === 'string' ? value.error.slice(0, 1200) : value.error?.message });
        Object.values(value).forEach(child => collectFailures(child, depth + 1));
      };
      collectFailures(result);
      if (!target && result.success !== false && response.status() < 400 && !nestedFailures.length) return;
      wire.push({ route, http: response.status(), success: result.success, code: result.code, message: result.message, nested_failures: nestedFailures, data_keys: Object.keys(result.data || {}), content_revision: result.data?.content_revision, revision: result.data?.revision });
      evidence('runtime-browser-wire', wire);
    } catch { /* 非JSON错误仍由可见界面与test断言记录。 */ }
  });
  await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
  await page.addInitScript(() => {
    window.__phtmlObservedMessages = [];
    window.addEventListener('message', event => {
      if (event.data && typeof event.data === 'object' && /selected|select-widget|mode/.test(event.data.type || '')) {
        window.__phtmlObservedMessages.push({ origin: event.origin, data: event.data });
        if (window.__phtmlObservedMessages.length > 100) window.__phtmlObservedMessages.shift();
      }
    });
  });
  // 官方runner每例使用新context；保持浏览器默认缓存语义，编译缓存冷读另由精确com清理覆盖。
  await loginAsAdmin(page, { timeout: 60000, settleMs: 500, useProxy: false });
  const query = new URLSearchParams({ theme_id: String(FIXTURE.theme_id), page_type: 'homepage', editor_area: 'frontend', layout_option: 'default', scope: FIXTURE.scope });
  Object.entries(FIXTURE.identity).forEach(([key, value]) => query.set(key, String(value)));
  const response = await gotoBackend(page, `theme/backend/theme-editor?${query}`, { waitUntil: 'domcontentloaded', timeout: 90000, settleMs: 500, useProxy: false });
  if ((await page.locator('#themeEditor').count()) === 0) {
    const body = await page.locator('body').innerText();
    evidence('runtime-editor-open-failure', { status: response?.status(), url: page.url(), body });
    await page.screenshot({ path: path.join(EVIDENCE, 'runtime-editor-open-failure.png'), fullPage: false });
    throw new Error(`编辑器未返回真实界面，HTTP ${response?.status()}: ${body.slice(0, 500)}`);
  }
  await expect(page.locator('#themeEditor')).toHaveAttribute('data-scope', FIXTURE.scope, { timeout: 60000 });
  await expect(page.locator('#previewFrame')).toHaveAttribute('src', /editor_mode=1/, { timeout: 90000 });
  await page.waitForFunction(() => typeof (window.Weline?.Theme?.Editor || window.ThemeEditor)?.getScopeIdentity === 'function', null, { timeout: 45000 });
  const entry = await page.evaluate(() => ({
    href: location.href, dataset: { ...document.querySelector('#themeEditor').dataset },
    scope: (window.Weline?.Theme?.Editor || window.ThemeEditor).getScopeIdentity(),
    preview: document.querySelector('#previewFrame')?.getAttribute('src'),
    scripts: Array.from(document.scripts).map(script => script.src).filter(Boolean),
  }));
  const serverMarkup = await response.text();
  const initialIframe = serverMarkup.match(/<iframe\b[^>]*\bid=["']previewFrame["'][^>]*>/i)?.[0] || '';
  entry.initial_preview_src = initialIframe.match(/\bsrc=["']([^"']*)["']/i)?.[1]?.replaceAll('&amp;', '&') || '';
  entry.document_requests = [...documents];
  page.__phtmlEntry = entry;
  evidence('runtime-editor-entry', entry);
  return options.readonlyStatus ? readRevisionStatus().editor_context : inspect().editor_context;
}

async function request(page, action, context, data = {}, method = 'POST', traced = false, transport = {}) {
  const apiBase = transport.apiBase || await page.locator('#themeEditor').getAttribute('data-api-base');
  const url = new URL(`${apiBase.replace(/\/$/, '')}/${action}`, page.url());
  const payload = { theme_id: FIXTURE.theme_id, page_type: 'homepage', layout_option: 'default', scope: FIXTURE.scope, editor_context: context, ...data };
  if (traced) { url.searchParams.set('wls_tpl_perf', '1'); url.searchParams.set('weline_trace', '1'); }
  if (method === 'GET') {
    Object.entries(payload).forEach(([key, value]) => url.searchParams.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value)));
  }
  if (action === 'remove-widget' || action === 'save-layout') evidence(`runtime-${action}-request`, { url: url.toString(), method, payload });
  const response = await page.request.fetch(url.toString(), {
    method, data: method === 'GET' ? undefined : payload,
    headers: { accept: 'application/json', 'x-requested-with': 'XMLHttpRequest', ...(traced ? { 'X-Weline-Trace': '1' } : {}), ...(transport.requestId ? { 'X-Weline-Request-Id': transport.requestId } : {}) }, timeout: 90000,
  });
  const text = await response.text();
  let result;
  try { result = JSON.parse(text); } catch { throw new Error(`${action}: HTTP ${response.status()} ${text.slice(0, 600)}`); }
  return { http: response.status(), request_id: response.headers()['x-weline-request-id'], ...result };
}

function ok(result, label) {
  expect(result.success, `${label}: ${JSON.stringify(result).slice(0, 1800)}`).toBe(true);
}

function ownNodes(snapshot) {
  const ids = ['A', 'B'].map(name => createHash('md5').update(`${FIXTURE.token}:${name}`).digest('hex'));
  return Object.values(snapshot.state?.draft_payload?.nodes || {}).filter(node => ids.includes(node.node_uid) || node.config?._phtml_test === FIXTURE.token);
}

function existingImages(snapshot) {
  const found = new Map();
  const visit = value => {
    if (!value || typeof value !== 'object') return;
    if (value.type === 'file-image' && value.usage?.asset_id) found.set(value.usage.asset_id, value);
    Object.values(value).forEach(visit);
  };
  visit(snapshot.state.draft_payload);
  visit(FIXTURE.image_sources || []);
  // 升级后空草稿不再水合旧布局；复用本轮先前从真实库读取并留证的媒体引用。
  if (found.size < 2) {
    const previous = path.join(EVIDENCE, 'runtime-before-flow.json');
    if (fs.existsSync(previous)) visit(JSON.parse(fs.readFileSync(previous, 'utf8')).state?.draft_payload);
  }
  return Array.from(found.values());
}

function snapshotRevision(snapshot) {
  const rows = Array.isArray(snapshot.versions) ? snapshot.versions : [snapshot.versions];
  const selectedVersion = Number(snapshot.state?.theme_version_id || snapshot.state?.version_identity?.theme_version_id || 0);
  const current = rows.find(row => Number(row.version_id) === selectedVersion) || rows[0];
  return { version: Number(current?.version_id || 0), content_revision: Number(snapshot.state?.content_revision || current?.content_revision || 0), resource_revision: Number(snapshot.state.revision || 0) };
}

async function refreshCanvas(page) {
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
  await expect(page.locator('#themeEditor')).toHaveAttribute('data-scope', FIXTURE.scope, { timeout: 60000 });
  await expect(page.locator('#previewFrame')).toHaveAttribute('src', /editor_mode=1/, { timeout: 90000 });
}

async function homepageInventory(page) {
  const element = await page.locator('#previewFrame').elementHandle();
  const frame = await element.contentFrame();
  await frame.waitForLoadState('domcontentloaded', { timeout: 90000 });
  await expect(frame.locator('#homepage-main')).toHaveCount(1, { timeout: 90000 });
  return frame.evaluate(() => ({
    url: location.href, ready_state: document.readyState,
    header_count: document.querySelectorAll('header').length, footer_count: document.querySelectorAll('footer').length,
    public_header_count: Array.from(document.querySelectorAll('header')).filter(element => !element.closest('#homepage-main,[data-wslot="homepage-bottom"]')).length,
    public_footer_count: Array.from(document.querySelectorAll('footer')).filter(element => !element.closest('#homepage-main,[data-wslot="homepage-bottom"]')).length,
    wrappers: Array.from(document.querySelectorAll('.widget-wrapper')).map(element => ({
      dataset: { ...element.dataset },
      attributes: Object.fromEntries(Array.from(element.attributes).map(attribute => [attribute.name, attribute.value])),
      in_layout_content: Boolean(element.closest('#homepage-main,[data-wslot="homepage-bottom"]')),
      closest_slot: element.closest('[data-wslot]')?.getAttribute('data-wslot'),
      parent: { tag: element.parentElement?.tagName, id: element.parentElement?.id, class: element.parentElement?.className },
      text: element.textContent?.trim().slice(0, 180),
    })),
  }));
}

async function canvasHttpInventory(page, name) {
  const url = new URL(await page.locator('#previewFrame').getAttribute('src'), page.url()).toString();
  const typed = JSON.parse(new URL(url).searchParams.get('editor_context') || '{}');
  expect(typed.scope?.identity, 'HTTP响应必须使用实际画布的完整owner身份').toMatchObject(FIXTURE.identity);
  const started = Date.now();
  const response = await page.request.get(url, { timeout: 90000 });
  const html = await response.text();
  const rawPath = path.join(EVIDENCE, `${name}-html-private.html`);
  fs.writeFileSync(rawPath, html, { mode: 0o600 });
  fs.chmodSync(rawPath, 0o600);
  const inventory = await page.evaluate(source => {
    const document = new DOMParser().parseFromString(source, 'text/html');
    return {
      main_count: document.querySelectorAll('#homepage-main').length,
      header_count: document.querySelectorAll('header').length, footer_count: document.querySelectorAll('footer').length,
      public_header_count: Array.from(document.querySelectorAll('header')).filter(element => !element.closest('#homepage-main,[data-wslot="homepage-bottom"]')).length,
      public_footer_count: Array.from(document.querySelectorAll('footer')).filter(element => !element.closest('#homepage-main,[data-wslot="homepage-bottom"]')).length,
      wrappers: Array.from(document.querySelectorAll('.widget-wrapper')).map(element => ({
        dataset: { ...element.dataset },
        in_layout_content: Boolean(element.closest('#homepage-main,[data-wslot="homepage-bottom"]')),
        closest_slot: element.closest('[data-wslot]')?.getAttribute('data-wslot'),
        headings: Array.from(element.querySelectorAll('h1,h2,h3,h4')).map(heading => heading.textContent?.trim()),
        content_buttons: element.querySelectorAll('.content-button').length,
        images: Array.from(element.querySelectorAll('img')).map(img => ({ src: img.getAttribute('src'), alt: img.getAttribute('alt') })),
      })),
    };
  }, html);
  const result = { verification: 'actual_http_response_parsed_as_inert_html_not_live_canvas_dom', url, started, ended: Date.now(), status: response.status(), request_id: response.headers()['x-weline-request-id'], bytes: Buffer.byteLength(html), sha256: createHash('sha256').update(html).digest('hex'), private_html_path: rawPath, ...inventory };
  evidence(name, result);
  expect(response.status()).toBe(200);
  expect(html).not.toContain('WLS Runtime Error');
  expect(inventory.main_count).toBe(1);
  return result;
}

function publicWrapperCounts(inventory) {
  return inventory.wrappers.filter(item => !item.in_layout_content).reduce((counts, item) => {
    const key = [item.dataset.widgetModule, item.dataset.widgetCode, item.dataset.templateRef || ''].join('|');
    counts[key] = (counts[key] || 0) + 1;
    return counts;
  }, {});
}

function layoutContentNodes(snapshot, inventory, activeOnly = true) {
  const contentSlots = new Set(inventory.wrappers.filter(item => item.in_layout_content).map(item => item.closest_slot || item.dataset.slotId).filter(Boolean));
  const publicSlots = new Set(inventory.wrappers.filter(item => !item.in_layout_content).map(item => item.closest_slot || item.dataset.slotId).filter(slot => slot && !contentSlots.has(slot)));
  return Object.values(snapshot.state.draft_payload.nodes || {}).filter(node => {
    if (node.widget_code === '__no_widget_placements__' || publicSlots.has(node.slot_id)) return false;
    if (node.area !== 'content' && !contentSlots.has(node.slot_id)) return false;
    return !activeOnly || (![false, 0, '0'].includes(node.is_active) && node.source !== 'user_deleted');
  });
}

function rememberedClearedContent(snapshot) {
  const cursor = snapshotRevision(snapshot);
  const receipts = ['runtime-empty-http-save.json', 'runtime-empty-http-verified.json']
    .map(name => path.join(EVIDENCE, name)).filter(file => fs.existsSync(file))
    .map(file => JSON.parse(fs.readFileSync(file, 'utf8')))
    .filter(item => item.saved?.success === true && item.metadata?.identity?.canonical_scope === FIXTURE.scope
      && item.metadata?.identity?.theme_id === FIXTURE.theme_id && item.cursor?.version === cursor.version
      && item.cursor?.content_revision <= cursor.content_revision);
  const baselinePath = path.join(EVIDENCE, 'runtime-empty-http-before.json');
  if (!receipts.length || !fs.existsSync(baselinePath)) return [];
  return JSON.parse(fs.readFileSync(baselinePath, 'utf8')).wrappers.filter(item => item.in_layout_content);
}

async function clearAndVerifyEmptyLayout(page, context) {
  const before = readRevisionStatus();
  let baseline;
  const baselineStarted = Date.now();
  try {
    baseline = await homepageInventory(page);
  } catch (error) {
    const frame = await boundedObservation(() => previewFrameState(page, ''));
    const diagnostics = { stage: 'baseline_before_save', save_sent: false, started: baselineStarted, ended: Date.now(), error: error.message, cursor: before.cursor, frame, pending: pendingNetwork(page), cdp: page.__phtmlNetworkEvents || [] };
    evidence('runtime-explicit-empty-baseline-failure', diagnostics);
    diagnostics.http = await readCanvasHttp(page, frame.iframe_src || frame.frame_url);
    evidence('runtime-explicit-empty-baseline-failure', diagnostics);
    throw error;
  }
  evidence('runtime-explicit-empty-before', { snapshot: before, canvas: baseline });
  expect(baseline.wrappers.filter(item => item.in_layout_content).length, '清空前主内容实际含可编辑部件').toBeGreaterThan(0);
  const cleared = await request(page, 'save-layout', context, { layout_data: {} });
  const empty = readRevisionStatus();
  const workspace = cleared.data?.workspace || cleared.data?.scoped_workspace;
  const homepage = path.resolve(ROOT, empty.owner_dir, 'draft/pages/layouts/homepage/default.phtml');
  const homepageBytes = fs.existsSync(homepage) ? fs.readFileSync(homepage) : null;
  const metadataMatch = homepageBytes?.toString('utf8').match(/weline-source:([A-Za-z0-9+/=]+)/);
  const sourceMetadata = metadataMatch ? JSON.parse(Buffer.from(metadataMatch[1], 'base64').toString('utf8')) : null;
  const effectiveContentNodes = workspace ? layoutContentNodes({ state: workspace }, baseline) : null;
  const ownerPath = path.resolve(ROOT, empty.owner_dir);
  const files = fs.readdirSync(ownerPath, { recursive: true }).map(name => path.join(ownerPath, name)).filter(file => fs.statSync(file).isFile());
  evidence('runtime-explicit-empty-layout', { cleared, before_cursor: before.cursor, status: empty, source: homepage, source_metadata: sourceMetadata, effective_content_nodes: effectiveContentNodes, files });
  ok(cleared, 'explicitly clear all layout placements');
  expect(effectiveContentNodes, '明确清空后当前布局没有生效content节点，允许保留人工卸载墓碑').toHaveLength(0);
  expect(empty.cursor.resource_revision).toBe(before.cursor.resource_revision + 1);
  expect(empty.cursor.content_revision).toBe(before.cursor.content_revision + 1);
  expect((workspace.changes || []).length, '显式清空有真实保存意图，不能等同于从未编辑').toBeGreaterThan(0);
  expect(homepageBytes?.length).toBeGreaterThan(0);
  expect(files.every(file => file.endsWith('.phtml')), '固化目录只保留PHTML').toBe(true);
  expect(Number(workspace.revision)).toBe(empty.cursor.resource_revision);
  expect(Number(workspace.content_revision)).toBe(empty.cursor.content_revision);
  expect(sourceMetadata?.identity?.theme_version_id).toBe(empty.cursor.version);
  expect(sourceMetadata?.identity?.content_revision).toBe(empty.cursor.content_revision);
  await refreshCanvas(page);
  let rendered;
  try {
    rendered = await homepageInventory(page);
  } finally {
    evidence('runtime-explicit-empty-after-refresh', { status: readRevisionStatus(), before: baseline, after: rendered, frame: await boundedObservation(() => previewFrameState(page, '')), pending: pendingNetwork(page) });
  }
  expect(rendered.wrappers.filter(item => item.in_layout_content), '主内容与homepage-bottom全部部件在显式清空后为零').toHaveLength(0);
  expect(rendered.wrappers.filter(item => item.dataset.widgetCode === 'store-music'), '污染轮新增的内容实例也必须正常卸载').toHaveLength(0);
  const currentStatus = readRevisionStatus();
  expect(currentStatus.cursor, '刷新读取不能推进版本').toEqual(empty.cursor);
  expect(currentStatus.resources).toEqual(empty.resources);
  const publicCounts = inventory => inventory.wrappers.filter(item => !item.in_layout_content).reduce((counts, item) => {
    const key = [item.dataset.widgetModule, item.dataset.widgetCode, item.dataset.templateRef || ''].join('|');
    counts[key] = (counts[key] || 0) + 1;
    return counts;
  }, {});
  expect(publicCounts(rendered), '清空本布局内容不会误空公共部分').toEqual(publicCounts(baseline));
  expect(rendered.wrappers.filter(item => item.dataset.widgetCode === 'all-menu')).toHaveLength(1);
  expect(rendered.public_header_count).toBe(baseline.public_header_count);
  expect(rendered.public_footer_count).toBe(baseline.public_footer_count);
}

async function setFixtureThemeBinding(page) {
  const snapshot = inspect();
  const context = snapshot.binding_context;
  const before = await request(page, 'scoped-workspace', context, {}, 'GET');
  ok(before, 'load isolated theme binding');
  const backupPath = path.join(EVIDENCE, 'runtime-original-theme-binding.json');
  if (!fs.existsSync(backupPath)) evidence('runtime-original-theme-binding', { context, workspace: before, published: snapshot.published_binding });
  if (Number(before.data.published_payload?.theme_id) === FIXTURE.theme_id) return;
  if (Number(before.data.draft_payload?.theme_id) !== FIXTURE.theme_id) {
    const applied = await request(page, 'scoped-workspace', context, {
      expected_revision: before.data.revision, expected_parent_release_id: before.data.expected_parent_release_id,
      changes: [{ op: 'set', path: '/theme_id', value: FIXTURE.theme_id }], summary: `PHTML isolated owner ${FIXTURE.token}`,
    });
    evidence('runtime-theme-binding-apply', applied);
    ok(applied, 'choose isolated Theme1');
  }
  const published = await request(page, 'publish', snapshot.editor_context, {
    create_version: true, version_name: `PHTML binding ${FIXTURE.token}`,
  });
  evidence('runtime-theme-binding-full-publish', published);
  ok(published, 'publish isolated Theme1 binding');
  expect(inspect().published_binding.effectiveValue).toBe(FIXTURE.theme_id);
}

async function publishAndVerify(page, browser, context, record = () => {}) {
  const uidA = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
  const uidB = createHash('md5').update(`${FIXTURE.token}:B`).digest('hex');
  const localeCases = [
    { locale: 'zh_Hans_CN', title: `PHTML 中文标题 ${FIXTURE.token}`, alt: `PHTML 中文图片 ${FIXTURE.token}` },
    { locale: 'en_US', title: `PHTML English title ${FIXTURE.token}`, alt: `PHTML English image ${FIXTURE.token}` },
  ];
  const current = snapshotRevision(inspect());
  const sealed = await request(page, 'save-scope-version', context, { theme_version_id: current.version, expected_content_revision: current.content_revision, version_name: `PHTML runtime ${FIXTURE.token}` });
  record('seal-version', sealed);
  ok(sealed, 'seal scope version');
  const published = await request(page, 'publish-scope-version', context, { theme_version_id: current.version, publish_set: 'all' });
  record('publish-version', published);
  ok(published, 'publish scope version');
  const clean = await browser.newContext({ ignoreHTTPSErrors: true });
  try {
    const formal = await clean.newPage();
    await formal.setExtraHTTPHeaders({ 'X-Weline-Trace': '1' });
    let traceCapture;
    formal.on('response', response => {
      if (traceCapture || response.request().resourceType() !== 'document' || !response.url().includes('_phtml_accept=')) return;
      traceCapture = response.finished().then(() => {
        const captured = requestTrace(response.headers()['x-weline-request-id']);
        evidence('runtime-formal-performance-trace', captured);
        return captured;
      }).catch(error => { evidence('runtime-formal-trace-capture-error', { error: error.message }); });
    });
    const response = await formal.goto(`${FIXTURE.storefront_url}?no_cache=1&wls_tpl_perf=1&weline_trace=1&_phtml_accept=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const headers = await response.allHeaders();
    if (traceCapture) await traceCapture;
    else evidence('runtime-formal-performance-trace', requestTrace(headers['x-weline-request-id']));
    expect(response.status()).toBe(200);
    await expect(formal.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toHaveCount(1);
    await expect(formal.locator(`.widget-wrapper[data-node-uid="${uidB}"]`)).toHaveCount(0);
    await expect(formal.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(/PHTML (?:中文标题|English title)/);
    expect(await formal.locator('header, .site-header, [data-wslot="header"]').count()).toBeGreaterThan(0);
    expect(await formal.locator('footer, .site-footer, [data-wslot="footer"]').count()).toBeGreaterThan(0);
    record('clean-formal-page', { status: response.status(), headers: await response.allHeaders(), url: formal.url(), uidA, uidB });
    await formal.screenshot({ path: path.join(EVIDENCE, 'runtime-browser-formal-final.png'), fullPage: false });
    for (const item of localeCases) {
      const languageLink = formal.locator(`a.w-language-switcher__option[data-lang="${item.locale}"]`).first();
      const href = await languageLink.getAttribute('href');
      expect(href, `正式页面提供${item.locale}真实路由`).toBeTruthy();
      const localizedResponse = await formal.goto(new URL(href, formal.url()).toString(), { waitUntil: 'domcontentloaded', timeout: 90000 });
      expect(localizedResponse.status()).toBe(200);
      const localizedWidget = formal.locator(`.widget-wrapper[data-node-uid="${uidA}"]`);
      await expect(localizedWidget).toContainText(item.title);
      await expect(localizedWidget.locator(`img[alt="${item.alt}"]`)).toHaveCount(1);
      record(`formal-locale-${item.locale}`, { status: localizedResponse.status(), url: formal.url(), text: await localizedWidget.innerText(), image_src: await localizedWidget.locator('img').first().getAttribute('src') });
    }
  } finally { await clean.close(); }
}

moduleDescribe(test, 'Weline_Theme', '纯 PHTML 真实运行验收', () => {
  test.setTimeout(360000);
  test.beforeAll(() => {
    test.skip(!fs.existsSync(FIXTURE_PATH), '专项运行需要独立 owner fixture；设置 PHTML_FIXTURE_PATH，见 theme-phtml-solidification.README.md。缺少临时验收数据不阻断其它 E2E 收集。');
    FIXTURE = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
    const status = fixtureAction('fixture_status');
    test.skip(!status.available, `隔离 fixture 已清理或不可用：${status.reason}。请准备新的独立 owner，禁止回退到默认 owner。`);
    fs.mkdirSync(EVIDENCE, { recursive: true });
  });
  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-CANVAS-NETLOG' }, '只读记录当前画布加载的浏览器发送边界', async ({ page }) => {
    test.skip(!NET_LOG_PATH, 'Only run with an explicitly configured private PHTML_NETLOG_PATH for diagnosis.');
    const started = Date.now();
    const before = snapshotRevision(inspect());
    let baseline, failure;
    try {
      await openEditor(page, { networkDiagnostics: true });
      baseline = await homepageInventory(page);
    }
    catch (error) { failure = error; }
    finally {
      const frame = await boundedObservation(() => previewFrameState(page, ''));
      const serviceWorker = await boundedObservation(() => page.evaluate(async () => ({
        controller: navigator.serviceWorker?.controller?.scriptURL || null,
        registrations: navigator.serviceWorker ? (await navigator.serviceWorker.getRegistrations()).map(registration => ({ scope: registration.scope, active: registration.active?.scriptURL })) : [],
      })));
      evidence(`${NET_LOG_LABEL}-frame-observation`, { started, ended: Date.now(), save_sent: false, before, after: snapshotRevision(inspect()), baseline, failure: failure?.message, frame, pending: pendingNetwork(page), cdp: page.__phtmlNetworkEvents || [], context_service_workers: page.context().serviceWorkers().map(worker => worker.url()), parent_service_worker: serviceWorker, netlog_private_path: NET_LOG_PATH });
      await page.screenshot({ path: path.join(EVIDENCE, `${NET_LOG_LABEL}-frame.png`), fullPage: false, timeout: 10000 }).catch(() => {});
    }
    if (failure) throw failure;
  });
  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-RUNTIME-PROBE' }, '隔离店铺编辑器和正式入口真实可用', async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const context = await openEditor(page);
    const workspace = await request(page, 'scoped-workspace', context, {}, 'GET');
    const versions = await request(page, 'scope-versions', context, {}, 'GET');
    const snapshot = inspect();
    const binding = await request(page, 'scoped-workspace', snapshot.binding_context, {}, 'GET');
    const editor = await page.evaluate(() => ({
      href: location.href,
      dataset: { ...document.querySelector('#themeEditor').dataset },
      preview: document.querySelector('#previewFrame')?.getAttribute('src'),
      api: typeof window.ThemeEditor,
      apiKeys: Object.keys(window.ThemeEditor || {}),
    }));
    evidence('runtime-browser-probe', { context, workspace, versions, binding, editor, errors, inspect: snapshot });
    ok(workspace, 'scoped workspace');
    ok(versions, 'scope versions');
    const clean = await browser.newContext({ ignoreHTTPSErrors: true });
    try {
      const formal = await clean.newPage();
      const response = await formal.goto(`${FIXTURE.storefront_url}?_phtml_probe=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
      expect(response.status()).toBe(200);
      await expect(formal.locator('body')).not.toContainText('Runtime Error');
      expect(await formal.locator('header, .site-header, [data-wslot="header"]').count()).toBeGreaterThan(0);
      expect(await formal.locator('footer, .site-footer, [data-wslot="footer"]').count()).toBeGreaterThan(0);
      await formal.screenshot({ path: path.join(EVIDENCE, 'runtime-browser-formal-probe.png'), fullPage: false });
      evidence('runtime-browser-formal-probe', {
        status: response.status(), headers: await response.allHeaders(), url: formal.url(), bytes: (await formal.content()).length,
        themeAssets: await formal.locator('link[href*="theme/frontend/disk"], script[src*="theme/frontend/disk"]').evaluateAll(elements => elements.map(element => element.href || element.src)),
        scope: await formal.evaluate(() => window.__WelinePixelEnv || null),
      });
    } finally { await clean.close(); }
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-01-02' }, '真实连续编辑、固化画布、历史 Token 和正式发布', async ({ page, browser }) => {
    test.setTimeout(600000);
    const steps = [];
    const context = await openEditor(page);
    await setFixtureThemeBinding(page);
    const record = (name, result = null) => {
      const snapshot = inspect();
      steps.push({ name, result, cursor: snapshotRevision(snapshot), nodes: ownNodes(snapshot), files: snapshot.files });
      evidence('runtime-browser-flow', { fixture: FIXTURE, context, steps });
      return snapshot;
    };
    const created = await request(page, 'create-scope-draft', context, { creation_source_kind: 'package_defaults', force_new: true });
    record('create-scope-draft', created);
    ok(created, 'create scope draft');
    const firstDraft = snapshotRevision(inspect());
    expect(firstDraft.content_revision, '新草稿在首次编辑前即捕获R1').toBe(1);
    const firstHead = inspect({ action: 'revision_head', theme_version_id: firstDraft.version, content_revision: 1 });
    evidence('runtime-first-draft-revision-head', firstHead);
    expect(firstHead.head, '创建草稿即存在真实历史头记录').toBeTruthy();
    const firstDraftPreview = await request(page, 'start-preview', context, {
      theme_version_id: firstDraft.version, content_revision: firstDraft.content_revision,
      mode: 'draft', status: 'draft', preview_mode: 'draft', canonical_scope: FIXTURE.scope,
    });
    ok(firstDraftPreview, 'first draft token before any widget write');
    fs.writeFileSync(path.join(EVIDENCE, 'runtime-baseline-preview-private.json'), JSON.stringify(firstDraftPreview.data), { mode: 0o600 });
    const baselineContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const baselinePage = await baselineContext.newPage();
    const baselineResponse = await baselinePage.goto(firstDraftPreview.data.preview_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    evidence('runtime-first-draft-token-response', {
      cursor: firstDraft,
      preview: JSON.parse(JSON.stringify(firstDraftPreview).replaceAll(firstDraftPreview.data.token, '[redacted]')),
      http: baselineResponse.status(), headers: await baselineResponse.allHeaders(),
      body: baselineResponse.status() >= 400 ? await baselinePage.locator('body').innerText() : '',
    });
    expect(baselineResponse.status()).toBe(200);
    await expect(baselinePage.locator('body')).not.toContainText('WLS Runtime Error');
    const baselineUids = await baselinePage.locator('.widget-wrapper[data-node-uid]').evaluateAll(elements => elements.map(element => element.dataset.nodeUid));
    record('first-draft-baseline-token', { cursor: firstDraft, uids: baselineUids, token_sha256: createHash('sha256').update(firstDraftPreview.data.token).digest('hex') });
    for (const node of ownNodes(inspect())) {
      const removed = await request(page, 'remove-widget', context, { node_uid: node.node_uid });
      ok(removed, '清理本用例上次实例');
    }
    const uidA = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
    const uidB = createHash('md5').update(`${FIXTURE.token}:B`).digest('hex');
    const oldTitle = `PHTML-A-旧值-${FIXTURE.token}`;
    const newTitle = `PHTML-A-新值-${FIXTURE.token}`;
    const imageSources = existingImages(inspect());
    expect(imageSources.length, '复用现有真实FileAsset，不构造不存在的媒体引用').toBeGreaterThanOrEqual(2);
    const configA = { title: oldTitle, content: '<p>实例 A 的真实正文</p>', image: imageSources[0], layout: 'image-left', button_text: '', button_link: '', _phtml_test: FIXTURE.token, empty_value: '', false_value: false };
    const common = { area: 'content', slot_id: 'homepage-bottom', widget_module: 'Weline_Theme', widget_type: 'content', widget_code: 'text-block', exclusive: false };
    const first = await request(page, 'save-widget', context, { ...common, widget_code: 'image-text', node_uid: uidA, sort_order: 0, config: configA });
    const afterFirst = record('add-A', first);
    ok(first, 'add A');
    expect(first.data.node_uid).toBe(uidA);
    const second = await request(page, 'save-widget', context, { ...common, node_uid: uidB, sort_order: 1, config: { title: `PHTML-B-${FIXTURE.token}`, content: '<p>实例 B 的真实正文</p>', _phtml_test: FIXTURE.token } });
    const afterSecond = record('add-B', second);
    ok(second, 'add B');
    expect(snapshotRevision(afterSecond).content_revision).toBeGreaterThan(snapshotRevision(afterFirst).content_revision);
    expect(ownNodes(afterSecond)).toHaveLength(2);
    const laterBaselineResponse = await baselinePage.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
    expect(laterBaselineResponse.status()).toBe(200);
    await expect(baselinePage.locator('body')).not.toContainText('WLS Runtime Error');
    expect(await baselinePage.locator('.widget-wrapper[data-node-uid]').evaluateAll(elements => elements.map(element => element.dataset.nodeUid))).toEqual(baselineUids);
    record('first-draft-token-after-later-saves', { cursor: firstDraft, uids: baselineUids });
    await baselineContext.close();
    expect(afterSecond.files.length).toBeGreaterThan(0);
    expect(afterSecond.files.every(file => file.extension === 'phtml')).toBe(true);
    await refreshCanvas(page);
    const canvas = page.frameLocator('#previewFrame');
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toHaveCount(1, { timeout: 90000 });
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidB}"]`)).toHaveCount(1, { timeout: 90000 });
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(oldTitle);
    evidence('runtime-canvas-wrapper-inventory', await canvas.locator('.widget-wrapper').evaluateAll(elements => elements.map(element => ({ dataset: { ...element.dataset }, text: element.innerText.slice(0, 200) }))));
    evidence('runtime-default-injection-catalog', await request(page, 'default-injections', context, {}, 'GET'));
    const sorted = await request(page, 'update-sort', context, { sort_data: { [uidB]: 0, [uidA]: 1 } });
    record('move-B-before-A', sorted);
    ok(sorted, 'move B before A');
    await refreshCanvas(page);
    const selected = canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"], .widget-wrapper[data-node-uid="${uidB}"]`);
    await expect(selected).toHaveCount(2, { timeout: 90000 });
    expect(await selected.evaluateAll(elements => elements.map(element => element.dataset.nodeUid))).toEqual([uidB, uidA]);
    const historical = snapshotRevision(inspect());
    const preview = await request(page, 'start-preview', context, {
      theme_version_id: historical.version, content_revision: historical.content_revision,
      mode: 'draft', status: 'draft', preview_mode: 'draft', canonical_scope: FIXTURE.scope,
    });
    ok(preview, 'start historical preview');
    const tokenUrl = preview.data.preview_url;
    fs.writeFileSync(path.join(EVIDENCE, 'runtime-old-preview-private.json'), JSON.stringify(preview.data), { mode: 0o600 });
    record('start-old-token', { success: preview.success, cursor: historical, token_sha256: createHash('sha256').update(preview.data.token).digest('hex'), url_without_token: tokenUrl.replace(/([?&]weline_preview_token=)[^&]+/g, '$1[redacted]') });

    // 点选真实固化 wrapper，再在编辑器表单内改标题，验证选择与自动保存链。
    if (await page.locator('#editorLangSwitcher').inputValue() !== '') {
      await page.locator('#editorLangSwitcher').selectOption('');
      await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toHaveCount(1, { timeout: 90000 });
    }
    try {
      await page.getByRole('group', { name: '选中目标', exact: true }).getByRole('button', { name: '部件', exact: true }).click({ timeout: 30000 });
      await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"] .widget-hover-actions`)).toHaveCount(1, { timeout: 60000 });
      const popup = canvas.locator('[data-newsletter-kind="popup"] .popup-close');
      if (await popup.isVisible()) await popup.click();
      await canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`).getByRole('heading', { name: oldTitle, exact: true }).click({ timeout: 30000 });
      const titleInput = page.locator('#configContent .widget-config-panel input[name="title"], #widgetConfigModal input[name="title"]').filter({ visible: true }).first();
      await expect(titleInput).toBeVisible({ timeout: 20000 });
      await titleInput.fill(newTitle);
      await titleInput.press('Tab');
      await expect.poll(() => ownNodes(inspect()).find(node => node.node_uid === uidA)?.config?.title, { timeout: 45000, intervals: [1000, 2000] }).toBe(newTitle);
      record('visible-ui-edit-A', { success: true });
      await page.screenshot({ path: path.join(EVIDENCE, 'runtime-browser-solidified-wrapper-edit.png'), fullPage: false });
    } catch (error) {
      record('visible-ui-edit-A', { success: false, error: error.message });
      evidence('runtime-wrapper-main-failure', await page.evaluate(() => ({ messages: window.__phtmlObservedMessages, panel: document.querySelector('#configContent')?.innerHTML })));
      evidence('runtime-wrapper-main-pending-network', pendingNetwork(page));
      evidence('runtime-wrapper-main-frame', await canvas.locator('body').evaluate(body => ({ href: location.href, readyState: document.readyState, widgetActionsEventsBound: body._widgetActionsEventsBound, sortableDelegationBound: body._sortableDelegationBound, last_scripts: Array.from(document.scripts).slice(-5).map(script => script.outerHTML), body_tail: body.innerHTML.slice(-1500) })));
      expect.soft(false, '真实固化wrapper选中/表单保存失败；继续收集独立后续证据').toBe(true);
    }
    const saved = await request(page, 'save-widget-config', context, { node_uid: uidA, config: { ...configA, title: newTitle, content: '', false_value: false } });
    const afterConfig = record('save-explicit-empty-false', saved);
    ok(saved, 'save explicit config');
    expect(ownNodes(afterConfig).find(node => node.node_uid === uidA).config.content).toBe('');
    expect(ownNodes(afterConfig).find(node => node.node_uid === uidA).config.false_value).toBe(false);
    const historyContext = await browser.newContext({ ignoreHTTPSErrors: true });
    try {
      const historyPage = await historyContext.newPage();
      const response = await historyPage.goto(tokenUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
      expect(response.status()).toBe(200);
      await expect(historyPage.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(oldTitle, { timeout: 30000 });
      await expect(historyPage.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).not.toContainText(newTitle);
      record('old-token-kept-old-value', { http: response.status(), cursor: historical });
      const cleared = inspect({ action: 'compiled_history', theme_version_id: historical.version, content_revision: historical.content_revision, mode: 'draft', delete: true });
      evidence('runtime-history-compiled-cache-clear', cleared);
      expect(cleared.files.length, '只清除与实际旧Token owner/V/R匹配的HTTP编译文件').toBeGreaterThan(0);
      await historyPage.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      await expect(historyPage.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(oldTitle);
      await expect(historyPage.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).not.toContainText(newTitle);
      const regenerated = inspect({ action: 'compiled_history', theme_version_id: historical.version, content_revision: historical.content_revision, mode: 'draft' });
      evidence('runtime-history-compiled-cache-regenerated', regenerated);
      expect(regenerated.files.length).toBeGreaterThan(0);
      record('old-token-after-exact-cache-clear', { cleared_files: cleared.files.length, regenerated_files: regenerated.files.length, cursor: historical });
      const historyIdentity = { theme_version_id: historical.version, content_revision: historical.content_revision };
      const missing = inspect({ action: 'history_reference_suspend', ...historyIdentity });
      let missingResponse;
      let restored;
      try {
        inspect({ action: 'compiled_history', ...historyIdentity, mode: 'draft', delete: true });
        const response = await historyPage.goto(tokenUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
        missingResponse = { status: response.status(), body: await historyPage.locator('body').innerText() };
      } finally {
        restored = inspect({ action: 'history_reference_restore', ...historyIdentity });
        evidence('runtime-history-missing-reference', { missing, response: missingResponse, restored });
      }
      expect(restored.restored).toBe(true);
      expect(missingResponse.status).toBeGreaterThanOrEqual(400);
      expect(missingResponse.body).toMatch(/historical_intent_reference_missing/);
      expect(missingResponse.body).not.toContain(newTitle);
      await historyPage.goto(tokenUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await expect(historyPage.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(oldTitle);
      record('old-token-missing-reference-explicit-error', { status: missingResponse.status, intent_revision_id: missing.intent_revision_id, restored: restored.restored });
    } finally { await historyContext.close(); }
    const removed = await request(page, 'remove-widget', context, { node_uid: uidB });
    record('remove-B', removed);
    ok(removed, 'remove B');
    await refreshCanvas(page);
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toHaveCount(1, { timeout: 90000 });
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidB}"]`)).toHaveCount(0);
    await expect(canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`)).toContainText(newTitle);
    const localeCases = [
      { locale: 'zh_Hans_CN', title: `PHTML 中文标题 ${FIXTURE.token}`, alt: `PHTML 中文图片 ${FIXTURE.token}`, image: imageSources[0] },
      { locale: 'en_US', title: `PHTML English title ${FIXTURE.token}`, alt: `PHTML English image ${FIXTURE.token}`, image: imageSources[1] },
    ];
    for (const item of localeCases) {
      const localized = await request(page, 'save-widget-config', context, { node_uid: uidA, locale: item.locale, config: { title: item.title, image: { type: 'file-image', usage: { ...item.image.usage, locale_code: item.locale, alt: item.alt, alt_state: 'confirmed' } } } });
      record(`save-locale-${item.locale}`, localized);
      ok(localized, `save ${item.locale}`);
    }
    await refreshCanvas(page);
    for (const item of localeCases) {
      await page.locator('#editorLangSwitcher').selectOption(item.locale);
      const localizedWidget = canvas.locator(`.widget-wrapper[data-node-uid="${uidA}"]`);
      await expect(localizedWidget).toContainText(item.title, { timeout: 90000 });
      await expect(localizedWidget.locator(`img[alt="${item.alt}"]`)).toHaveCount(1);
      const image = localizedWidget.locator('img').first();
      await image.scrollIntoViewIfNeeded();
      try {
        await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0), { timeout: 30000 }).toBe(true);
      } catch (error) {
        expect.soft(false, `真实${item.locale}图片未加载: ${error.message}`).toBe(true);
      } finally {
        evidence(`runtime-image-${item.locale}`, await image.evaluate(img => ({ src: img.src, currentSrc: img.currentSrc, loading: img.loading, complete: img.complete, naturalWidth: img.naturalWidth, html: img.outerHTML })));
      }
      record(`canvas-locale-${item.locale}`, { title: await localizedWidget.innerText(), image_src: await localizedWidget.locator('img').first().getAttribute('src'), iframe: await page.locator('#previewFrame').getAttribute('src') });
    }
    await publishAndVerify(page, browser, context, record);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-FORMAL' }, '已保存双语言数据的独立正式发布和真实页面', async ({ page, browser }) => {
    const context = await openEditor(page);
    const steps = [];
    const record = (name, result) => {
      const snapshot = inspect();
      steps.push({ name, result, cursor: snapshotRevision(snapshot), nodes: ownNodes(snapshot), files: snapshot.files });
      evidence('runtime-formal-continuation', { fixture: FIXTURE, steps });
    };
    await publishAndVerify(page, browser, context, record);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-FORMAL-IMAGES' }, '正式中英文实际图片加载', async ({ page }) => {
    const imageResponses = new Map();
    page.on('response', response => {
      if (response.request().resourceType() === 'image') imageResponses.set(response.url(), { status: response.status(), headers: response.headers() });
    });
    await page.goto(FIXTURE.storefront_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const uid = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
    for (const item of [
      { locale: 'zh_Hans_CN', title: `PHTML 中文标题 ${FIXTURE.token}`, alt: `PHTML 中文图片 ${FIXTURE.token}` },
      { locale: 'en_US', title: `PHTML English title ${FIXTURE.token}`, alt: `PHTML English image ${FIXTURE.token}` },
    ]) {
      const href = await page.locator(`a.w-language-switcher__option[data-lang="${item.locale}"]`).first().getAttribute('href');
      const response = await page.goto(new URL(href, page.url()).toString(), { waitUntil: 'domcontentloaded', timeout: 90000 });
      const widget = page.locator(`.widget-wrapper[data-node-uid="${uid}"]`);
      const image = widget.locator(`img[alt="${item.alt}"]`);
      await expect(widget).toContainText(item.title);
      await expect(image).toHaveCount(1);
      try {
        await image.scrollIntoViewIfNeeded({ timeout: 15000 });
        await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0), { timeout: 30000 }).toBe(true);
      } finally {
        const rendered = await image.evaluate(img => ({ src: img.src, currentSrc: img.currentSrc, alt: img.alt, loading: img.loading, complete: img.complete, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, readyState: document.readyState }));
        evidence(`runtime-formal-image-${item.locale}`, { page_status: response.status(), url: page.url(), rendered, request: imageResponses.get(rendered.currentSrc || rendered.src) || null });
      }
    }
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-04-HTTP' }, '真实同 owner 并发保存 CAS 回执', async ({ page }) => {
    const baseContext = await openEditor(page);
    const context = { ...baseContext, resource_type: 'meta' };
    const before = await request(page, 'scoped-workspace', context, {}, 'GET');
    ok(before, 'load meta workspace');
    evidence('runtime-http-cas-before', { before, cursor: snapshotRevision(inspect()), started: new Date().toISOString() });
    const common = { expected_revision: before.data.revision, expected_parent_release_id: before.data.expected_parent_release_id };
    const attempts = await Promise.all(['甲', '乙'].map(async marker => {
      const started = Date.now();
      try {
        const result = await request(page, 'scoped-workspace', context, { ...common, changes: [{ op: 'set', path: '/values/title', value: `PHTML CAS ${marker} ${FIXTURE.token}` }] }, 'POST', true);
        const attempt = { marker, started, ended: Date.now(), result };
        evidence(`runtime-http-cas-attempt-${marker}`, attempt);
        if (result.request_id) evidence(`runtime-http-cas-trace-${marker}`, requestTrace(result.request_id));
        return attempt;
      } catch (error) {
        const attempt = { marker, started, ended: Date.now(), transport_error: error.message, result: null };
        evidence(`runtime-http-cas-attempt-${marker}`, attempt);
        return attempt;
      }
    }));
    evidence('runtime-http-cas-after-attempts', { attempts, db: inspect() });
    const after = await request(page, 'scoped-workspace', context, {}, 'GET');
    const casEvidence = { fixture: FIXTURE, before, attempts, after, db: inspect() };
    evidence('runtime-http-cas', casEvidence);
    if (attempts.some(attempt => attempt.transport_error) && Number(after.data.revision) > Number(before.data.revision)) {
      const started = Date.now();
      const stale = { expected_revision: before.data.revision, actual_revision: after.data.revision, started };
      try {
        stale.result = await request(page, 'scoped-workspace', context, { ...common, changes: [{ op: 'set', path: '/values/title', value: `PHTML stale ${FIXTURE.token}` }] }, 'POST', true);
        stale.ended = Date.now();
        if (stale.result.request_id) evidence('runtime-http-single-stale-trace', requestTrace(stale.result.request_id));
      } catch (error) { stale.transport_error = error.message; stale.ended = Date.now(); }
      stale.db = inspect();
      evidence('runtime-http-single-stale', stale);
    }
    expect(attempts.filter(attempt => attempt.transport_error), '真实服务必须返回两条可判定并发收据').toHaveLength(0);
    expect(attempts.filter(attempt => attempt.result.success)).toHaveLength(1);
    expect(attempts.filter(attempt => !attempt.result.success)).toHaveLength(1);
    expect(attempts.find(attempt => !attempt.result.success).result.message).toContain('revision_conflict');
    expect(Number(after.data.revision)).toBe(Number(before.data.revision) + 1);
    expect(Number(attempts.find(attempt => attempt.result.success).result.data.content_revision)).toBe(snapshotRevision(casEvidence.db).content_revision);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-04-WRITE' }, '真实操作系统写失败回执与权限恢复后保存', async ({ page }) => {
    const baseContext = await openEditor(page);
    const context = { ...baseContext, resource_type: 'meta' };
    const after = await request(page, 'scoped-workspace', context, {}, 'GET');
    ok(after, 'load write-failure workspace');
    const beforeFailure = inspect();
    const homepage = beforeFailure.files.find(file => file.path.endsWith('/draft/pages/layouts/homepage/default.phtml'));
    expect(homepage, '实际保存应已生成隔离 owner homepage PHTML').toBeTruthy();
    const directory = path.dirname(homepage.path);
    const permissions = fs.statSync(directory).mode & 0o777;
    const oldSource = fs.readFileSync(homepage.path, 'utf8');
    let failed;
    fs.chmodSync(directory, 0o555);
    try {
      failed = await request(page, 'scoped-workspace', context, { expected_revision: after.data.revision, expected_parent_release_id: after.data.expected_parent_release_id, changes: [{ op: 'set', path: '/values/title', value: `PHTML OS 写失败 ${FIXTURE.token}` }] });
    } finally { fs.chmodSync(directory, permissions); }
    const failedState = await request(page, 'scoped-workspace', context, {}, 'GET');
    const failedDb = inspect();
    const unchangedSource = fs.readFileSync(homepage.path, 'utf8') === oldSource;
    evidence('runtime-http-write-failure', { fixture: FIXTURE, before: snapshotRevision(beforeFailure), failed, failedState, after: snapshotRevision(failedDb), unchanged_source: unchangedSource, blocked_directory: directory });
    const recovered = await request(page, 'scoped-workspace', context, { expected_revision: failedState.data.revision, expected_parent_release_id: failedState.data.expected_parent_release_id, changes: [{ op: 'set', path: '/values/title', value: `PHTML 恢复 ${FIXTURE.token}` }] });
    evidence('runtime-http-write-recovery', { recovered, db: inspect() });
    ok(recovered, '普通写权限恢复后的真实保存');
    expect(failed.success).toBe(false);
    expect(failed.message).toMatch(/write_failed|bake|solidif|materializ/);
    expect(Number(failedState.data.revision)).toBe(Number(after.data.revision) + 1);
    expect(snapshotRevision(failedDb).content_revision).toBeGreaterThan(snapshotRevision(beforeFailure).content_revision);
    expect(Number(failed.data?.actual_content_revision)).toBe(snapshotRevision(failedDb).content_revision);
    expect(Number(failed.data?.actual_revision)).toBe(Number(failedState.data.revision));
    expect(unchangedSource).toBe(true);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-04-TWO-SESSIONS' }, '两个独立合法登录session的真实保存CAS', async ({ page, browser }) => {
    const initialStatus = readRevisionStatus();
    const authOptions = { bootstrapOnly: true, useProxy: false, bootstrapModes: ['wls'], wlsBootstrapAttempts: 1 };
    const backendRoot = await loginAsAdmin(page, authOptions);
    const apiBase = `${backendRoot.replace(/\/$/, '')}/theme/backend/theme-editor`;
    const transport = { apiBase };
    const secondBrowser = await browser.newContext({ ignoreHTTPSErrors: true });
    try {
      const secondPage = await secondBrowser.newPage();
      await loginAsAdmin(secondPage, authOptions);
      const context = { ...initialStatus.editor_context, resource_type: 'meta' };
      const sessionHashes = await Promise.all([page, secondPage].map(async current => {
        const cookies = (await current.context().cookies(apiBase)).filter(cookie => /^WELINE_SESSID(?:_|$)/.test(cookie.name));
        return cookies.map(cookie => ({ name: cookie.name, domain: cookie.domain, sha256: createHash('sha256').update(cookie.value).digest('hex') }));
      }));
      evidence('runtime-http-two-sessions-auth', { session_hashes: sessionHashes });
      const first = await request(page, 'scoped-workspace', context, {}, 'GET', false, transport);
      const second = await request(secondPage, 'scoped-workspace', context, {}, 'GET', false, transport);
      evidence('runtime-http-two-sessions-auth', { first, second, session_hashes: sessionHashes });
      ok(first, 'first session remains authenticated after second login');
      ok(second, 'second independent session authenticated');
      expect(sessionHashes[0].length).toBeGreaterThan(0);
      expect(sessionHashes[1].length).toBeGreaterThan(0);
      expect(sessionHashes[0].map(item => item.sha256).some(hash => sessionHashes[1].some(item => item.sha256 === hash)), '两个context不能复用同一session').toBe(false);
      expect(first.data.revision).toBe(second.data.revision);
      const beforeStatus = readRevisionStatus();
      const before = beforeStatus.cursor;
      expect(beforeStatus.cursor, '仅登录与认证读取不能推进版本').toEqual(initialStatus.cursor);
      expect(beforeStatus.resources).toEqual(initialStatus.resources);
      const common = { expected_revision: first.data.revision, expected_parent_release_id: first.data.expected_parent_release_id };
      const timingFile = path.join(ROOT, 'var/log/wls/timing.log');
      const timingOffset = fs.statSync(timingFile).size;
      const attempts = await Promise.all([page, secondPage].map(async (current, index) => {
        const marker = index === 0 ? '甲' : '乙';
        const started = Date.now();
        const traceRequestId = `phtml-cas-${index === 0 ? 'a' : 'b'}-${started}`;
        evidence(`runtime-http-two-sessions-${marker}-start`, { marker, started, request_id: traceRequestId, expected_revision: common.expected_revision, expected_parent_release_id: common.expected_parent_release_id, version_cursor: before });
        let attempt;
        try {
          const result = await request(current, 'scoped-workspace', context, { ...common, changes: [{ op: 'set', path: '/values/title', value: `PHTML dual-session CAS ${marker} ${FIXTURE.token}` }] }, 'POST', true, { apiBase, requestId: traceRequestId });
          attempt = { marker, started, ended: Date.now(), sent_request_id: traceRequestId, result };
        } catch (error) { attempt = { marker, started, ended: Date.now(), sent_request_id: traceRequestId, transport_error: error.message, result: null }; }
        evidence(`runtime-http-two-sessions-${marker}`, attempt);
        // Collect each completed request immediately; a slow peer must not expire the winner's trace.
        try {
          const trace = requestTrace(attempt.result?.request_id || traceRequestId);
          const tracePath = path.join(EVIDENCE, `runtime-http-two-sessions-${index === 0 ? 'a' : 'b'}-trace-private.json`);
          fs.writeFileSync(tracePath, JSON.stringify(trace), { mode: 0o600 });
          fs.chmodSync(tracePath, 0o600);
          attempt.trace = { private_path: tracePath, complete_available: Boolean(trace.complete_trace), timing: (trace.timing || []).map(item => Object.fromEntries(['request_id', 'timestamp', 'worker_id', 'pid', 'request_count', 'total_ms'].map(key => [key, item[key]]))) };
        } catch (error) { attempt.trace_read_error = error.message; }
        evidence(`runtime-http-two-sessions-${marker}`, attempt);
        return attempt;
      }));
      const after = await request(secondPage, 'scoped-workspace', context, {}, 'GET', false, transport);
      const db = readRevisionStatus();
      const bytes = fs.statSync(timingFile).size - timingOffset;
      let timings = [];
      if (bytes >= 0 && bytes < 10 * 1024 * 1024) {
        const file = fs.openSync(timingFile, 'r');
        try {
          const buffer = Buffer.alloc(bytes);
          fs.readSync(file, buffer, 0, bytes, timingOffset);
          timings = buffer.toString('utf8').split('\n').flatMap(line => {
            try {
              const item = JSON.parse(line);
              if (item.method !== 'POST' || !String(item.uri).includes('scoped-workspace')) return [];
              return [Object.fromEntries(['uri', 'method', 'timestamp', 'worker_id', 'worker_port', 'pid', 'request_count', 'total_ms', 'session_start_ms', 'request_id'].map(key => [key, item[key]]))];
            } catch { return []; }
          });
        } finally { fs.closeSync(file); }
      }
      if (!timings.length) timings = attempts.flatMap(attempt => attempt.trace?.timing || []);
      evidence('runtime-http-two-sessions', { fixture: FIXTURE, session_hashes: sessionHashes, before, before_status: beforeStatus, before_resource_revision: first.data.revision, attempts, after, cursor: db.cursor, after_status: db, timings, timing_bytes_read: bytes, editor_pages_opened: false });
      expect(attempts.filter(attempt => attempt.transport_error).length, '两个独立session均收到可判断真实收据').toBe(0);
      expect(attempts.filter(attempt => attempt.result?.success)).toHaveLength(1);
      expect(attempts.filter(attempt => attempt.result && !attempt.result.success)).toHaveLength(1);
      expect(attempts.find(attempt => attempt.result && !attempt.result.success).result.message).toContain('revision_conflict');
      expect(Number(after.data.revision)).toBe(Number(first.data.revision) + 1);
      expect(db.cursor.content_revision).toBe(before.content_revision + 1);
      expect(db.cursor.resource_revision, 'meta保存不推进布局resource R').toBe(before.resource_revision);
      expect(new Set(timings.map(item => item.worker_id).filter(id => id !== undefined && id !== null).map(String)).size, '本轮须有两个实际Worker进入证据').toBe(2);
    } finally { await secondBrowser.close(); }
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-01-EMPTY' }, '默认必装人工卸载不复活且明确空布局保留PHTML', async ({ page, browser }) => {
    const context = await openEditor(page, { networkDiagnostics: true, readonlyStatus: true });
    const resumeVersion = Number(process.env.PHTML_RESUME_REQUIRED_VERSION || 0);
    const resumeUninstalled = process.env.PHTML_RESUME_REQUIRED_UNINSTALLED === '1';
    if (resumeVersion) {
      expect(readRevisionStatus().cursor.version, '仅续跑明确指定的隔离草稿').toBe(resumeVersion);
    } else {
      const created = await request(page, 'create-scope-draft', context, { creation_source_kind: 'package_defaults', force_new: true });
      ok(created, 'independent draft for uninstall and empty layout');
    }
    if (process.env.PHTML_RESUME_REQUIRED_EMPTY_ONLY === '1') {
      await clearAndVerifyEmptyLayout(page, context);
      return;
    }
    const canvas = page.frameLocator('#previewFrame');
    let declaration, uid, baseline, before, historical, previewData, rebuilt;
    if (resumeUninstalled) {
      const saved = JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-required-default-removal.json'), 'utf8'));
      ok(saved.removed, '续验必须具有已成功删除的真实回执');
      ({ declaration, uid, baseline, before } = saved);
      historical = snapshotRevision(before);
      expect(historical.version).toBe(resumeVersion);
      expect(snapshotRevision(inspect()).content_revision).toBeGreaterThan(historical.content_revision);
      previewData = JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-required-preview-private.json'), 'utf8'));
    } else {
      await refreshCanvas(page);
      declaration = inspect().required_declarations.find(item => item.widget_module === 'Weline_StoreMusic' && item.widget_code === 'store-music' && item.node.area === 'content');
      expect(declaration, '真实catalog声明的content必装部件').toBeTruthy();
      const required = canvas.locator('.widget-wrapper[data-widget-code="store-music"]');
      await expect(required).toHaveCount(1, { timeout: 90000 });
      uid = await required.getAttribute('data-node-uid');
      baseline = await canvas.locator('.widget-wrapper').evaluateAll(elements => elements.map(element => ({ ...element.dataset })));
      before = inspect();
      expect(before.required_declarations.some(item => item.widget_code === 'all-menu')).toBe(true);
      expect(baseline.filter(item => item.widgetCode === 'all-menu')).toHaveLength(1);
      historical = snapshotRevision(before);
      const preview = await request(page, 'start-preview', context, { theme_version_id: historical.version, content_revision: historical.content_revision, mode: 'draft', status: 'draft', preview_mode: 'draft', canonical_scope: FIXTURE.scope });
      ok(preview, 'required default before uninstall token');
      previewData = preview.data;
      fs.writeFileSync(path.join(EVIDENCE, 'runtime-required-preview-private.json'), JSON.stringify(previewData), { mode: 0o600 });
    }
    const historyContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const historyPage = await historyContext.newPage();
    const initialResponse = await historyPage.goto(previewData.preview_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    expect(initialResponse.status()).toBe(200);
    await expect(historyPage.locator('.widget-wrapper[data-widget-code="store-music"]')).toHaveCount(1);
    if (!resumeUninstalled) {
      const removed = await request(page, 'remove-widget', context, { node_uid: uid });
      evidence('runtime-required-default-removal', { declaration, uid, baseline, before, removed, after: inspect() });
      ok(removed, 'remove required default via actual wrapper UID');
      // 无关修改再次触发真实固化，验证人工卸载不会被默认注入复活。
      const metaContext = { ...context, resource_type: 'meta' };
      const meta = await request(page, 'scoped-workspace', metaContext, {}, 'GET');
      rebuilt = await request(page, 'scoped-workspace', metaContext, {
        expected_revision: meta.data.revision, expected_parent_release_id: meta.data.expected_parent_release_id,
        changes: [{ op: 'set', path: '/values/title', value: `PHTML uninstall re-bake ${FIXTURE.token}` }],
      });
      evidence('runtime-required-rebake-receipt', { rebuilt, cursor: snapshotRevision(inspect()) });
      ok(rebuilt, 're-bake after required uninstall');
    }
    await refreshCanvas(page);
    try {
      await expect(canvas.locator('.widget-wrapper[data-widget-code="all-menu"]')).toHaveCount(1, { timeout: 90000 });
      await expect(canvas.locator('.widget-wrapper[data-widget-code="store-music"]')).toHaveCount(0);
    } finally {
      const frame = await boundedObservation(() => previewFrameState(page, uid));
      const http = await readCanvasHttp(page, frame.iframe_src || frame.frame_url);
      evidence('runtime-required-default-after-rebake', { rebuilt, resumed_after_successful_uninstall: resumeUninstalled, cursor: snapshotRevision(inspect()), frame, http, pending: pendingNetwork(page), wrappers: frame.document?.wrapper_inventory || [] });
    }
    try {
      expect(snapshotRevision(inspect()).version, '卸载与旧Token必须同V不同R').toBe(historical.version);
      const retainedResponse = await historyPage.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      const retainedCount = await historyPage.locator('.widget-wrapper[data-widget-code="store-music"]').count();
      const cleared = inspect({ action: 'compiled_history', theme_version_id: historical.version, content_revision: historical.content_revision, mode: 'draft', delete: true });
      const regeneratedResponse = await historyPage.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      const regeneratedCount = await historyPage.locator('.widget-wrapper[data-widget-code="store-music"]').count();
      evidence('runtime-required-history-after-uninstall', { historical, current: snapshotRevision(inspect()), retained_status: retainedResponse.status(), retained_count: retainedCount, cleared, regenerated_status: regeneratedResponse.status(), regenerated_count: regeneratedCount });
      expect.soft(retainedCount, '同V旧Token在人工卸载后保留原默认部件').toBe(1);
      expect(cleared.files.length).toBeGreaterThan(0);
      expect.soft(regeneratedCount, '同V旧Token精确清编译缓存后保留原默认部件').toBe(1);
    } finally { await historyContext.close(); }
    await clearAndVerifyEmptyLayout(page, context);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-FIRST-CONTINUE' }, '继续当前来源的新草稿R1立即具备可渲染历史依据', async ({ page, browser }) => {
    const context = await openEditor(page);
    const created = await request(page, 'create-scope-draft', context, { creation_source_kind: 'continue_current', force_new: true });
    const snapshot = inspect();
    const cursor = snapshotRevision(snapshot);
    const head = inspect({ action: 'revision_head', theme_version_id: cursor.version, content_revision: cursor.content_revision });
    evidence('runtime-continue-current-regression', { created, cursor, head, snapshot });
    ok(created, 'create from current after missing-head fix');
    expect(cursor.content_revision).toBe(1);
    expect(head.head).toBeTruthy();
    const preview = await request(page, 'start-preview', context, { theme_version_id: cursor.version, content_revision: 1, mode: 'draft', status: 'draft', preview_mode: 'draft', canonical_scope: FIXTURE.scope });
    ok(preview, 'first continue-current token');
    fs.writeFileSync(path.join(EVIDENCE, 'runtime-continue-preview-private.json'), JSON.stringify(preview.data), { mode: 0o600 });
    const clean = await browser.newContext({ ignoreHTTPSErrors: true });
    try {
      const visible = await clean.newPage();
      const response = await visible.goto(preview.data.preview_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
      evidence('runtime-continue-current-token', { cursor, http: response.status(), body: response.status() >= 400 ? await visible.locator('body').innerText() : '', wrapper_count: await visible.locator('.widget-wrapper[data-node-uid]').count() });
      expect(response.status()).toBe(200);
      await expect(visible.locator('body')).not.toContainText('WLS Runtime Error');
    } finally { await clean.close(); }
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-EMPTY-HTTP' }, '实际全空布局保存与同身份HTTP产物', async ({ page }) => {
    const context = await openEditor(page);
    const resume = process.env.PHTML_RESUME_EMPTY_HTTP === '1';
    const receipt = resume ? JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-empty-http-save.json'), 'utf8')) : null;
    const before = resume ? JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-empty-http-layout-baseline.json'), 'utf8')).snapshot : inspect();
    if (resume) expect(snapshotRevision(inspect()), '续验只能读取已保存的同一V/R，不重复写入').toEqual(receipt.cursor);
    const read = await request(page, 'layout', context, {}, 'GET');
    if (!resume) evidence('runtime-empty-http-layout-baseline', { read, snapshot: before });
    ok(read, 'read real current layout endpoint');
    const baseline = resume ? JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-empty-http-before.json'), 'utf8')) : await canvasHttpInventory(page, 'runtime-empty-http-before');
    if (resume && baseline.public_header_count === undefined) {
      const html = fs.readFileSync(baseline.private_html_path, 'utf8');
      expect(createHash('sha256').update(html).digest('hex'), '公共边界重分类仅使用该次真实原响应').toBe(baseline.sha256);
      const regions = await page.evaluate(source => {
        const document = new DOMParser().parseFromString(source, 'text/html');
        const locate = tag => Array.from(document.querySelectorAll(tag)).map(element => ({ tag, id: element.id, class_name: element.className, in_layout_content: Boolean(element.closest('#homepage-main,[data-wslot="homepage-bottom"]')) }));
        return { headers: locate('header'), footers: locate('footer') };
      }, html);
      baseline.public_header_count = regions.headers.filter(item => !item.in_layout_content).length;
      baseline.public_footer_count = regions.footers.filter(item => !item.in_layout_content).length;
      evidence('runtime-empty-http-baseline-public-regions', { sha256: baseline.sha256, request_id: baseline.request_id, ...regions, public_header_count: baseline.public_header_count, public_footer_count: baseline.public_footer_count });
    }
    expect(baseline.wrappers.filter(item => item.in_layout_content).length).toBeGreaterThan(0);
    const saved = resume ? receipt.saved : await request(page, 'save-layout', context, { layout_data: {} });
    const after = inspect();
    const cursor = snapshotRevision(after);
    const effective = layoutContentNodes(after, baseline);
    const source = after.files.find(file => file.path.endsWith('/draft/pages/layouts/homepage/default.phtml'));
    const match = source && fs.readFileSync(path.resolve(ROOT, source.path), 'utf8').match(/weline-source:([A-Za-z0-9+/=]+)/);
    const metadata = match ? JSON.parse(Buffer.from(match[1], 'base64').toString('utf8')) : null;
    evidence(resume ? 'runtime-empty-http-verified' : 'runtime-empty-http-save', { resumed_readonly: resume, before: snapshotRevision(before), saved, snapshot: after, cursor, effective_content_nodes: effective, source, metadata });
    ok(saved, 'actual explicit empty save-layout');
    expect(cursor.version).toBe(snapshotRevision(before).version);
    expect(cursor.content_revision).toBe(snapshotRevision(before).content_revision + 1);
    expect(cursor.resource_revision).toBeGreaterThan(snapshotRevision(before).resource_revision);
    expect(effective).toHaveLength(0);
    expect(after.state.changes.length).toBeGreaterThan(0);
    expect(source?.bytes).toBeGreaterThan(0);
    expect(after.files.every(file => file.extension === 'phtml')).toBe(true);
    expect(metadata?.identity?.theme_version_id).toBe(cursor.version);
    expect(metadata?.identity?.content_revision).toBe(cursor.content_revision);
    const rendered = await canvasHttpInventory(page, 'runtime-empty-http-after');
    expect(rendered.wrappers.filter(item => item.in_layout_content)).toHaveLength(0);
    expect(publicWrapperCounts(rendered)).toEqual(publicWrapperCounts(baseline));
    expect(rendered.wrappers.filter(item => item.dataset.widgetCode === 'all-menu')).toHaveLength(1);
    expect(rendered.public_header_count).toBe(baseline.public_header_count);
    expect(rendered.public_footer_count).toBe(baseline.public_footer_count);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-SAVE-LAYOUT-COMPAT-HTTP' }, '非空分组布局表单保存与同身份HTTP回读', async ({ page }) => {
    await verifyCompatibilityLayoutSave(page, true);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-SAVE-LAYOUT-COMPAT' }, '非空分组布局表单保存保留实例和值类型', async ({ page }) => {
    await verifyCompatibilityLayoutSave(page, false);
  });

  async function verifyCompatibilityLayoutSave(page, httpOnly) {
    const resume = httpOnly && process.env.PHTML_RESUME_COMPAT_HTTP === '1';
    const receipt = resume ? JSON.parse(fs.readFileSync(path.join(EVIDENCE, 'runtime-save-layout-compat.json'), 'utf8')) : null;
    const initialStatus = readRevisionStatus();
    if (resume) expect(initialStatus.cursor, '只读续验不能重复保存或换版本').toEqual(receipt.cursor);
    const context = await openEditor(page, { readonlyStatus: true });
    const before = resume ? null : inspect();
    const prior = resume ? receipt.before : snapshotRevision(before);
    const priorHttpPath = path.join(EVIDENCE, 'runtime-save-layout-compat-http-before.json');
    const wasExplicitlyEmpty = resume ? fs.existsSync(priorHttpPath) : Object.values(before.state.draft_payload.nodes || {}).some(node => node.widget_code === '__no_widget_placements__' && node.config?.no_widget_placements === true);
    const priorHttp = httpOnly && wasExplicitlyEmpty ? (resume ? JSON.parse(fs.readFileSync(priorHttpPath, 'utf8')) : await canvasHttpInventory(page, 'runtime-save-layout-compat-http-before')) : null;
    const uid = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
    const image = structuredClone(resume ? receipt.input.config.image : existingImages(before)[0]);
    expect(image?.usage?.asset_id, '复用现有真实媒体引用').toBeTruthy();
    image.usage.decorative = false;
    image.usage.caption = null;
    image.usage.alt = `PHTML compatibility image ${FIXTURE.token}`;
    const title = `PHTML compatibility form ${FIXTURE.token}`;
    const widget = {
      node_uid: uid, widget_module: 'Weline_Theme', widget_type: 'content', widget_code: 'image-text',
      slot_id: 'homepage-bottom', sort_order: 7, is_active: true,
      config: { title, content: '', button_text: '', button_link: null, image, layout: 'image-left', _phtml_test: FIXTURE.token },
    };
    const saved = resume ? receipt.saved : await request(page, 'save-layout', context, { layout_data: { content: [widget] } });
    const after = resume ? receipt.snapshot : inspect();
    const cursor = snapshotRevision(after);
    const source = after.files.find(file => file.path.endsWith('/draft/pages/layouts/homepage/default.phtml'));
    const sourceBytes = source && fs.readFileSync(path.resolve(ROOT, source.path), 'utf8');
    const match = sourceBytes?.match(/weline-source:([A-Za-z0-9+/=]+)/);
    const metadata = match ? JSON.parse(Buffer.from(match[1], 'base64').toString('utf8')) : null;
    if (!resume) evidence('runtime-save-layout-compat', { input: widget, before: prior, saved, snapshot: after, cursor, source, metadata });
    ok(saved, 'save nonempty grouped compatibility form');
    expect(cursor.version).toBe(prior.version);
    expect(cursor.content_revision).toBe(prior.content_revision + 1);
    const node = after.state.draft_payload.nodes[uid];
    expect(node).toMatchObject({ node_uid: uid, area: 'content', slot_id: 'homepage-bottom', sort_order: 7, widget_module: 'Weline_Theme', widget_type: 'content', widget_code: 'image-text', is_active: true });
    expect(node.config.content).toBe('');
    expect(node.config.title).toBe(title);
    expect(node.config.button_text).toBe('');
    expect(node.config.button_link).toBeNull();
    expect(node.config.image.usage.decorative).toBe(false);
    expect(node.config.image.usage.caption).toBeNull();
    expect(metadata?.identity?.theme_version_id).toBe(cursor.version);
    expect(metadata?.identity?.content_revision).toBe(cursor.content_revision);
    expect(sourceBytes).toContain(uid);
    if (httpOnly) {
      const read = await request(page, 'layout', context, {}, 'GET');
      ok(read, 'read saved grouped layout through real API');
      const baseConfig = await request(page, 'widget-config', context, { node_uid: uid }, 'GET');
      const englishConfig = await request(page, 'widget-config', context, { node_uid: uid, locale: 'en_US' }, 'GET');
      ok(baseConfig, 'read persisted base config');
      ok(englishConfig, 'read persisted English config');
      const rendered = await canvasHttpInventory(page, resume ? 'runtime-save-layout-compat-http-readonly' : 'runtime-save-layout-compat-http');
      const wrappers = rendered.wrappers.filter(item => item.dataset.nodeUid === uid);
      const status = readRevisionStatus();
      evidence(resume ? 'runtime-save-layout-compat-http-readonly-verified' : 'runtime-save-layout-compat-http-reread', { read, status, wrappers, base_config: baseConfig, english_config: englishConfig, resumed_without_save: resume });
      expect(baseConfig.data.config).toMatchObject({ title, content: '', button_text: '', button_link: null });
      expect(baseConfig.data.config.image.usage.decorative).toBe(false);
      expect(baseConfig.data.config.image.usage.caption).toBeNull();
      expect(wrappers).toHaveLength(1);
      expect(wrappers[0].dataset.slotId).toBe('homepage-bottom');
      expect(wrappers[0].headings, 'English页面采用实际保存的语言覆盖，base值另外核验').toContain(englishConfig.data.config.title);
      expect(wrappers[0].content_buttons).toBe(0);
      if (wasExplicitlyEmpty) {
        expect(rendered.wrappers.filter(item => item.in_layout_content), '全空后仅提交A不能让此前明确清空的默认内容复活').toHaveLength(1);
        expect(rendered.wrappers.filter(item => item.dataset.widgetCode === 'store-music')).toHaveLength(0);
        expect(publicWrapperCounts(rendered)).toEqual(publicWrapperCounts(priorHttp));
        expect(rendered.public_header_count).toBe(priorHttp.public_header_count);
        expect(rendered.public_footer_count).toBe(priorHttp.public_footer_count);
      }
      expect(status.cursor).toEqual(cursor);
      if (resume) expect(status.resources).toEqual(initialStatus.resources);
      return;
    }
    await refreshCanvas(page);
    const wrapper = page.frameLocator('#previewFrame').locator(`.widget-wrapper[data-node-uid="${uid}"]`);
    try {
      await expect(wrapper).toHaveCount(1, { timeout: 90000 });
      await expect(wrapper).toHaveAttribute('data-slot-id', 'homepage-bottom');
      await expect(wrapper).toContainText(title);
      await expect(wrapper.locator('.content-button')).toHaveCount(0);
    } finally {
      evidence('runtime-save-layout-compat-refresh', { cursor: snapshotRevision(inspect()), frame: await boundedObservation(() => previewFrameState(page, uid)), pending: pendingNetwork(page) });
    }
    const reread = inspect();
    expect(snapshotRevision(reread)).toEqual(cursor);
    expect(reread.state.draft_payload.nodes[uid].config).toEqual(node.config);
  }

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-CURRENT-DRAFT' }, '继续当前必须继承未发布草稿改动', async ({ page }) => {
    const context = await openEditor(page);
    const metaContext = { ...context, resource_type: 'meta' };
    const before = await request(page, 'scoped-workspace', metaContext, {}, 'GET');
    ok(before, 'read current draft meta');
    const title = `PHTML current D ${FIXTURE.token} ${Date.now()}`;
    expect(before.data.published_payload?.values?.title).not.toBe(title);
    const changed = await request(page, 'scoped-workspace', metaContext, { expected_revision: before.data.revision, expected_parent_release_id: before.data.expected_parent_release_id, changes: [{ op: 'set', path: '/values/title', value: title }] });
    ok(changed, 'save distinct unpublished current draft title');
    const source = snapshotRevision(inspect());
    const created = await request(page, 'create-scope-draft', context, { creation_source_kind: 'continue_current', force_new: true });
    const after = await request(page, 'scoped-workspace', metaContext, {}, 'GET');
    const snapshot = inspect();
    evidence('runtime-current-draft-inheritance', { before, changed, source, created, after, cursor: snapshotRevision(snapshot), snapshot });
    ok(created, 'fork current unpublished draft');
    ok(after, 'read copied draft meta');
    expect(Number(after.data.theme_version_id)).not.toBe(source.version);
    expect(Number(after.data.content_revision)).toBe(1);
    expect(after.data.draft_payload.values.title).toBe(title);
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-WRAPPER' }, '实际固化wrapper点选和配置表单', async ({ page }) => {
    const context = await openEditor(page, { networkDiagnostics: true });
    const firstUrl = new URL(page.__phtmlEntry.initial_preview_src, page.url());
    const firstContext = JSON.parse(firstUrl.searchParams.get('editor_context') || '{}');
    evidence('runtime-wrapper-initial-canvas', { initial_url: firstUrl.toString(), context: firstContext, documents: page.__phtmlDocuments });
    expect(firstContext.scope?.identity, '首document必须携带已授权选择的真实owner').toMatchObject(FIXTURE.identity);
    expect(firstContext.layout_type).toBe('homepage');
    expect(firstContext.layout_option).toBe('default');
    expect(firstContext.target_type).toBe('global');
    expect(Number(firstContext.target_id)).toBe(0);
    const initialRequest = page.__phtmlDocuments.find(item => item.event === 'request' && new URL(item.url, page.url()).searchParams.get('editor_mode') === '1');
    expect(initialRequest, '观察首个实际preview document请求').toBeTruthy();
    let initialProbe = null;
    try {
      await expect.poll(() => page.__phtmlDocuments.find(item => ['response', 'failed'].includes(item.event) && item.url === initialRequest.url), { timeout: 90000 }).toBeTruthy();
      const firstResult = page.__phtmlDocuments.find(item => ['response', 'failed'].includes(item.event) && item.url === initialRequest.url);
      if (firstResult.event === 'response') expect(firstResult.status, '首个真实owner预览后端响应必须成功').toBe(200);
      else {
        expect(firstResult.failure?.errorText, '仅正常导航取消允许由实际承接文档继续').toBe('net::ERR_ABORTED');
        const replacementUrl = new URL(await page.locator('#previewFrame').getAttribute('src'), page.url());
        expect(replacementUrl.toString()).not.toBe(initialRequest.url);
        expect(JSON.parse(replacementUrl.searchParams.get('editor_context') || '{}').scope?.identity).toMatchObject(FIXTURE.identity);
        await expect.poll(() => page.__phtmlDocuments.find(item => item.event === 'response' && item.url === replacementUrl.toString())?.status, { timeout: 90000 }).toBe(200);
        const response = await page.request.get(firstUrl.toString(), { timeout: 90000 });
        const html = await response.text();
        initialProbe = { verification: 'readonly_http_for_initial_url_canceled_by_parent_navigation', status: response.status(), request_id: response.headers()['x-weline-request-id'], bytes: Buffer.byteLength(html) };
        expect(response.status()).toBe(200);
        expect(html).not.toContain('WLS Runtime Error');
      }
    } finally { evidence('runtime-wrapper-initial-canvas', { initial_url: firstUrl.toString(), context: firstContext, initial_probe: initialProbe, documents: page.__phtmlDocuments }); }
    const uid = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
    const before = inspect();
    let prepared = null;
    if (!ownNodes(before).some(node => node.node_uid === uid)) {
      prepared = await request(page, 'save-widget', context, {
        area: 'content', slot_id: 'homepage-bottom', widget_module: 'Weline_Theme', widget_type: 'content', widget_code: 'image-text', exclusive: false,
        node_uid: uid, sort_order: 0, config: { title: `PHTML-A-点选准备-${FIXTURE.token}`, content: '<p>实例 A 的真实正文</p>', image: existingImages(before)[0], layout: 'image-left', _phtml_test: FIXTURE.token },
      });
      ok(prepared, 'prepare missing A through real save-widget');
      await refreshCanvas(page);
    }
    evidence('runtime-wrapper-input', { before: snapshotRevision(before), a_existed: ownNodes(before).some(node => node.node_uid === uid), prepared, current: snapshotRevision(inspect()), nodes: ownNodes(inspect()) });
    const canvas = page.frameLocator('#previewFrame');
    const wrapper = canvas.locator(`.widget-wrapper[data-node-uid="${uid}"]`);
    const input = page.locator('#configContent .widget-config-panel input[name="title"], #widgetConfigModal input[name="title"]').filter({ visible: true }).first();
    try {
      await expect(wrapper).toHaveCount(1, { timeout: 90000 });
      await page.getByRole('group', { name: '选中目标', exact: true }).getByRole('button', { name: '部件', exact: true }).click({ timeout: 30000 });
      await expect(wrapper.locator('.widget-hover-actions')).toHaveCount(1, { timeout: 60000 });
      const popup = canvas.locator('[data-newsletter-kind="popup"] .popup-close');
      if (await popup.isVisible()) await popup.click();
      const heading = wrapper.getByRole('heading', { name: /PHTML/ }).first();
      evidence('runtime-wrapper-click-target', await heading.evaluate(element => {
        const ancestors = [];
        for (let current = element; current; current = current.parentElement) ancestors.push({ tag: current.tagName, class: current.className, role: current.getAttribute('role'), data: { ...current.dataset } });
        return { target: element.outerHTML, ancestors, readyState: document.readyState, widgetActionsEventsBound: document.body._widgetActionsEventsBound, sortableDelegationBound: document.body._sortableDelegationBound };
      }));
      await heading.click({ timeout: 30000 });
      await expect(input).toBeVisible({ timeout: 20000 });
    } finally {
      const pending = pendingNetwork(page);
      evidence('runtime-wrapper-pending-network', pending);
      evidence('runtime-wrapper-cdp-network', page.__phtmlNetworkEvents || []);
      const frameState = await previewFrameState(page, uid);
      evidence('runtime-wrapper-frame', frameState);
      const frameUrl = publicUrl(frameState.frame_url || '');
      const currentStylesheet = pending.find(item => item.frame_url === frameUrl && item.type === 'stylesheet');
      if (currentStylesheet) {
        const started = Date.now();
        try {
          const response = await page.request.get(currentStylesheet.request_url, { timeout: 20000 });
          evidence('runtime-wrapper-same-cookie-resource', { url: currentStylesheet.request_url, http: response.status(), elapsed_ms: Date.now() - started, headers: response.headers(), bytes: (await response.body()).length });
        } catch (error) { evidence('runtime-wrapper-same-cookie-resource', { url: currentStylesheet.request_url, elapsed_ms: Date.now() - started, error: error.message }); }
      }
      evidence('runtime-wrapper-focused', await page.evaluate(() => ({ messages: window.__phtmlObservedMessages, panel: document.querySelector('#configContent')?.innerHTML, body: document.body.innerText.slice(0, 1800) })));
      try { await page.screenshot({ path: path.join(EVIDENCE, 'runtime-wrapper-focused.png'), fullPage: false, timeout: 10000 }); }
      catch (error) { evidence('runtime-wrapper-screenshot-failure', { error: error.message }); }
    }
    const title = `PHTML-A-可见表单-${FIXTURE.token}`;
    await input.fill(title);
    await input.press('Tab');
    await expect.poll(() => ownNodes(inspect()).find(node => node.node_uid === uid)?.config?.title, { timeout: 30000 }).toBe(title);
    evidence('runtime-wrapper-focused-saved', inspect());
    await refreshCanvas(page);
    let rendered;
    try {
      rendered = await homepageInventory(page);
      const actual = rendered.wrappers.filter(item => item.in_layout_content);
      expect(actual.filter(item => item.dataset.nodeUid === uid)).toHaveLength(1);
      await expect(page.frameLocator('#previewFrame').locator(`.widget-wrapper[data-node-uid="${uid}"]`)).toContainText(title);
      const clearedContent = rememberedClearedContent(before);
      for (const removed of clearedContent) {
        if (removed.dataset.nodeUid === uid) continue;
        expect(actual.filter(item => item.dataset.widgetCode === removed.dataset.widgetCode && item.closest_slot === removed.closest_slot), `保存后已清空的${removed.dataset.widgetCode}@${removed.closest_slot}不能复活`).toHaveLength(0);
      }
      if (clearedContent.length || Object.values(before.state.draft_payload.nodes || {}).some(node => node.widget_code === 'store-music' && node.source === 'user_deleted')) {
        expect(rendered.wrappers.filter(item => item.dataset.widgetCode === 'store-music')).toHaveLength(0);
      }
    } finally {
      evidence('runtime-wrapper-after-save', { cursor: snapshotRevision(inspect()), rendered, frame: await boundedObservation(() => previewFrameState(page, uid)), pending: pendingNetwork(page) });
    }
  });

  moduleCase(test, { module: 'Weline_Theme', id: 'UC-PHTML-CANVAS-LOCALE' }, '画布切换两种语言的真实文本和图片', async ({ page }) => {
    const beforeOpen = readRevisionStatus();
    const context = await openEditor(page, { readonlyStatus: true, observeEditorRequests: true });
    const uid = createHash('md5').update(`${FIXTURE.token}:A`).digest('hex');
    const sources = existingImages({ state: { draft_payload: {} } });
    expect(sources.length).toBeGreaterThanOrEqual(2);
    const initialLayout = await request(page, 'layout', context, {}, 'GET');
    ok(initialLayout, 'read current real layout');
    const initialCanvas = await homepageInventory(page);
    const afterOpen = readRevisionStatus();
    evidence('runtime-canvas-locale-read-baseline', { before: beforeOpen, after: afterOpen, canvas: initialCanvas, editor_requests: editorRequestObservation(page) });
    expect(afterOpen.cursor, '打开编辑器和读取布局不能推进版本').toEqual(beforeOpen.cursor);
    expect(afterOpen.resources).toEqual(beforeOpen.resources);
    expect(initialCanvas.wrappers.filter(item => item.dataset.nodeUid === uid), '此回归必须沿用清空后真实表单保存的A').toHaveLength(1);
    expect(initialCanvas.wrappers.filter(item => item.dataset.widgetCode === 'store-music'), '回归输入必须已明确卸载StoreMusic').toHaveLength(0);
    const cases = [
      { locale: 'zh_Hans_CN', title: `PHTML 中文标题 ${FIXTURE.token}`, alt: `PHTML 中文图片 ${FIXTURE.token}`, image: sources[0] },
      { locale: 'en_US', title: `PHTML English title ${FIXTURE.token}`, alt: `PHTML English image ${FIXTURE.token}`, image: sources[1] },
    ];
    for (const item of cases) {
      const saved = await request(page, 'save-widget-config', context, { node_uid: uid, locale: item.locale, config: { title: item.title, image: { type: 'file-image', usage: { ...item.image.usage, locale_code: item.locale, alt: item.alt, alt_state: 'confirmed' } } } });
      evidence(`runtime-canvas-locale-save-${item.locale}`, { saved, status: readRevisionStatus() });
      ok(saved, `save real locale ${item.locale}`);
    }
    const afterSaves = readRevisionStatus();
    expect(afterSaves.cursor.content_revision).toBe(beforeOpen.cursor.content_revision + 2);
    expect(afterSaves.cursor.resource_revision).toBe(beforeOpen.cursor.resource_revision);
    await refreshCanvas(page);
    const canvas = page.frameLocator('#previewFrame');
    for (const item of cases) {
      await page.locator('#editorLangSwitcher').selectOption(item.locale);
      const layoutRead = await request(page, 'layout', context, { locale: item.locale }, 'GET');
      ok(layoutRead, `read real layout ${item.locale}`);
      const wrapper = canvas.locator(`.widget-wrapper[data-node-uid="${uid}"]`);
      let failure = null;
      try {
        await expect(wrapper).toContainText(item.title, { timeout: 90000 });
        const image = wrapper.locator(`img[alt="${item.alt}"]`);
        await expect(image).toHaveCount(1);
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0), { timeout: 30000 }).toBe(true);
      } catch (error) { failure = error; }
      const frame = await previewFrameState(page, uid);
      const status = readRevisionStatus();
      const observedRequests = editorRequestObservation(page);
      const state = { locale: item.locale, selector: await page.locator('#editorLangSwitcher').inputValue(), before_reads: afterSaves, after_reads: status, frame, editor_requests: observedRequests, pending: pendingNetwork(page), failure: failure?.message || null };
      evidence(`runtime-canvas-locale-${item.locale}`, state);
      if (failure && /^https?:/.test(frame.frame_url || '')) {
        const started = Date.now();
        try {
          const response = await page.request.get(frame.frame_url, { timeout: 30000 });
          const html = await response.text();
          const rendered = await page.evaluate(({ source, nodeUid }) => {
            const document = new DOMParser().parseFromString(source, 'text/html');
            const wrapper = document.querySelector(`.widget-wrapper[data-node-uid="${nodeUid}"]`);
            return { wrapper_present: Boolean(wrapper), text: wrapper?.textContent?.slice(0, 2000), headings: Array.from(wrapper?.querySelectorAll('h1,h2,h3,h4') || []).map(element => element.textContent), images: Array.from(wrapper?.querySelectorAll('img') || []).map(element => ({ src: element.getAttribute('src'), alt: element.getAttribute('alt') })) };
          }, { source: html, nodeUid: uid });
          evidence(`runtime-canvas-locale-http-${item.locale}`, { url: frame.frame_url, status: response.status(), elapsed_ms: Date.now() - started, request_id: response.headers()['x-weline-request-id'], rendered });
        } catch (error) { evidence(`runtime-canvas-locale-http-${item.locale}`, { url: frame.frame_url, elapsed_ms: Date.now() - started, error: error.message }); }
      }
      if (failure) throw failure;
      expect(status.cursor, '语言切换和get-layout读取不能推进global/layout R').toEqual(afterSaves.cursor);
      expect(status.resources, '语言切换和get-layout读取不能推进任一资源R').toEqual(afterSaves.resources);
      expect(status.layout_history_counts).toEqual(afterSaves.layout_history_counts);
      expect(frame.document.wrapper_inventory.filter(widget => widget.widgetCode === 'store-music'), '语言切换后已卸载部件不得复活').toHaveLength(0);
      assertNoAutomaticReconcile(observedRequests);
    }
  });
});
