// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const fs = require('fs');
const path = require('path');
const { createHash } = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect, moduleCase } = require('../../../../../../../tests/e2e/framework');

const ROOT = path.resolve(__dirname, '../../../../../../..');
const MANIFEST_PATH = process.env.PHTML_SITEWIDE_MANIFEST ? path.resolve(process.env.PHTML_SITEWIDE_MANIFEST) : '';
const EVIDENCE = path.resolve(process.env.PHTML_SITEWIDE_EVIDENCE_DIR || path.join(ROOT, 'dev/tmp/theme-phtml-sitewide/browser'));
const RUNNER_HASH = createHash('sha256').update(fs.readFileSync(__filename)).update(fs.readFileSync(path.join(__dirname, 'theme-phtml-sitewide-contract.js'))).digest('hex');
const sourceDocuments = new Map();
let manifest;
let manifestError;
try {
  if (MANIFEST_PATH) manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
} catch (error) { manifestError = error.message; }

const localHostMappings = manifest?.local_host_resolver || [];
if (localHostMappings.length) {
  const hosts = localHostMappings.map(mapping => {
    if (!/^[a-z0-9-]+(?:\.weline)?\.test$/.test(mapping.host) || mapping.address !== '127.0.0.1') throw new Error('Sitewide local transport requires an exact verified .test/.weline.test host and loopback address.');
    return `MAP ${mapping.host} ${mapping.address}`;
  });
  // Preserve the formal Chromium project flags. Only these exact catalog hosts
  // receive a process-local mapping; URLs, Host, protocol and system hosts stay intact.
  test.use({ launchOptions: {
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
    ignoreDefaultArgs: ['--enable-automation'],
    args: ['--ignore-certificate-errors', '--disable-blink-features=AutomationControlled', `--host-resolver-rules=${hosts.join(', ')}`],
  } });
}

function safeName(value) { return String(value).replace(/[^a-z0-9_-]/gi, '-'); }
function publicUrl(value) {
  try {
    const url = new URL(value);
    for (const key of [...url.searchParams.keys()]) if (/token|csrf|session|password|secret/i.test(key)) url.searchParams.set(key, '[redacted]');
    return url.toString();
  } catch { return value; }
}
function writeJson(file, value) {
  fs.writeFileSync(file, JSON.stringify(value, (key, item) => {
    if (/authorization|cookie|csrf|password|secret|access_token|preview_token|session_id/i.test(key)) return '[redacted]';
    if (typeof item === 'string') return item.replace(/([?&](?:[^=&\s]*(?:token|csrf|session)[^=&\s]*)=)[^&#\s"<>]*/gi, '$1[redacted]')
      .replace(/(cookie:)[^\r\n]*/gi, '$1 [redacted]')
      .replace(/(["'](?:csrf(?:_?token)?|session_id|preview_token|access_token)["']\s*:\s*["'])[^"']*(["'])/gi, '$1[redacted]$2');
    return item;
  }, 2) + '\n');
}
async function boundedRead(action, timeout = 15000) {
  let timer;
  try { return await Promise.race([action(), new Promise(resolve => { timer = setTimeout(() => resolve({ observation_error: `read exceeded ${timeout}ms` }), timeout); })]); }
  catch (error) { return { observation_error: error.message }; }
  finally { clearTimeout(timer); }
}

function sourceContract(item) {
  const requestUrl = require('./theme-phtml-sitewide-contract').sourceRequestUrl(item);
  if (!requestUrl.available) return { available: false, reason: requestUrl.reason };
  const filename = item.source_expectations_file || manifest.source_expectations_file;
  if (!filename) return { available: false, reason: 'independent source contract not supplied' };
  const absolute = path.isAbsolute(filename) ? filename : path.resolve(ROOT, filename);
  if (!fs.existsSync(absolute)) return { available: false, reason: 'independent source contract not yet present', path: absolute };
  const stat = fs.statSync(absolute);
  const cacheKey = `${absolute}|${stat.mtimeMs}|${stat.size}`;
  if (!sourceDocuments.has(cacheKey)) {
    const bytes = fs.readFileSync(absolute);
    try { sourceDocuments.set(cacheKey, { document: JSON.parse(bytes.toString('utf8')), hash: createHash('sha256').update(bytes).digest('hex') }); }
    catch (error) { return { available: false, path: absolute, reason: `source contract cannot be decoded: ${error.message}` }; }
  }
  const { document, hash } = sourceDocuments.get(cacheKey);
  const candidates = (document.pages || []).filter(candidate => Number(candidate.theme_id) === Number(item.identity.theme_id)
    && candidate.layout_type === item.layout_type && candidate.layout_option === item.layout_option);
  const identityFields = ['theme_id', 'canonical_scope', 'store_mode', 'area', 'theme_version_id', 'mode', 'content_revision'];
  const decisions = candidates.map(candidate => {
    const identity = candidate.published_identity || {};
    const mismatches = identityFields.filter(field => identity[field] === undefined || String(identity[field]) !== String(item.identity[field]));
    const target = item.target || { type: 'global', id: 0 };
    if (candidate.target?.type !== target.type || String(candidate.target?.id) !== String(target.id)) mismatches.push('target');
    if (!candidate.locale_applicability?.locales?.includes(item.locale)) mismatches.push('locale_applicability');
    const requestCase = Array.isArray(candidate.request_cases) ? candidate.request_cases.find(entry => {
      try { return new URL(entry.url).toString() === requestUrl.url; } catch { return false; }
    }) : undefined;
    if (Array.isArray(candidate.request_cases) && !requestCase) mismatches.push('request_case_url');
    return { candidate, mismatches, requestCase };
  });
  const matches = decisions.filter(decision => !decision.mismatches.length);
  return { available: matches.length === 1, path: absolute, sha256: hash, matches: matches.length,
    applicability: { intended_identity: item.identity, request_scope: item.request_scope, target: item.target, locale: item.locale,
      rejected_candidates: decisions.filter(decision => decision.mismatches.length).map(decision => ({ published_identity: decision.candidate.published_identity, mismatches: decision.mismatches })) },
    request_case: matches.length === 1 ? matches[0].requestCase : null,
    page: matches.length === 1 ? matches[0].candidate : null };
}

function requestFrame(request, page) {
  try { return { frame_url: publicUrl(request.frame().url()), main_frame: request.frame() === page.mainFrame() }; }
  catch { return { frame_unavailable: true }; }
}

function isAncillaryEmbeddedRequest(entry) {
  // Only the observed YouTube companion ad-status request is classified here.
  // Player scripts/media and all first-party resources retain required status.
  try {
    const url = new URL(entry.url);
    const frame = new URL(entry.frame_url);
    return url.protocol === 'https:' && url.hostname === 'static.doubleclick.net' && url.pathname === '/instream/ad_status.js'
      && entry.main_frame === false && frame.hostname === 'www.youtube.com' && frame.pathname.startsWith('/embed/');
  } catch { return false; }
}

function canResume(filename, item, viewport, contract) {
  if (process.env.PHTML_SITEWIDE_RESUME !== '1' || !contract.available || !fs.existsSync(filename)) return false;
  try {
    const prior = JSON.parse(fs.readFileSync(filename, 'utf8'));
    return prior.product_result === 'pass' && prior.intended_url === item.url
      && prior.runner_sha256 === RUNNER_HASH
      && prior.source_contract?.sha256 === contract.sha256
      && JSON.stringify(prior.intended_identity) === JSON.stringify(item.identity)
      && JSON.stringify(prior.request_scope) === JSON.stringify(item.request_scope)
      && JSON.stringify(prior.target) === JSON.stringify(item.target) && prior.locale === item.locale
      && prior.viewport?.width === viewport.width && prior.viewport?.height === viewport.height;
  } catch { return false; }
}

function actualTemplateSource(requestId) {
  if (!requestId) return { available: false, reason: 'document response supplied no request id' };
  try {
    const output = execFileSync('rg', ['-F', requestId, path.join(ROOT, 'var/log/wls/timing.log')], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
    const rows = output.trim().split('\n').map(line => JSON.parse(line)).filter(row => row.trace_summary);
    return {
      available: rows.length > 0,
      requests: rows.map(row => {
        const summary = row.trace_summary;
        const templates = [];
        for (const [filename, stats] of Object.entries(summary.template_render_files?.files || {})) {
          const absolute = path.resolve(ROOT, filename);
          if (!absolute.startsWith(ROOT + path.sep) || !fs.existsSync(absolute)) continue;
          const metadata = fs.readFileSync(absolute, 'utf8').match(/weline-source:([A-Za-z0-9+/=]+)/);
          if (!metadata) continue;
          try { templates.push({ file: filename, calls: stats.calls, bytes: stats.bytes, metadata: JSON.parse(Buffer.from(metadata[1], 'base64').toString('utf8')) }); }
          catch { /* A non-decodable comment is not source execution evidence. */ }
        }
        return { worker_id: row.worker_id, request_id: requestId, truncated: summary.truncated, dropped_span_count: summary.dropped_span_count,
          template_overflow_calls: summary.template_render_files?.overflow_calls, templates };
      }),
    };
  } catch (error) { return { available: false, reason: error.status === 1 ? 'no matching timing record' : error.message }; }
}

function actualRequestTiming(requestId) {
  if (!requestId) return { available: false, reason: 'No document request id.' };
  try {
    const output = execFileSync('rg', ['-F', requestId, path.join(ROOT, 'var/log/wls/timing.log')], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
    const rows = output.trim().split('\n').map(line => JSON.parse(line)).filter(row => row.request_id === requestId);
    const fields = ['request_id', 'uri', 'method', 'timestamp', 'instance', 'worker_id', 'worker_port', 'pid', 'request_count', 'total_ms', 'session_start_ms'];
    return { available: rows.length > 0, records: rows.map(row => Object.fromEntries(fields.map(field => [field, row[field]]))) };
  } catch (error) { return { available: false, reason: error.status === 1 ? 'Matching timing record unavailable.' : error.message }; }
}

async function collectMatchedVisibilityCss(page, client, selector, sheetHeaders, responses) {
  const ancestry = await page.locator(selector).evaluate(node => {
    const selectorFor = element => {
      const parts = [];
      for (let cursor = element; cursor; cursor = cursor.parentElement) {
        const siblings = cursor.parentElement ? [...cursor.parentElement.children] : [cursor];
        parts.unshift(`${cursor.localName}:nth-child(${siblings.indexOf(cursor) + 1})`);
      }
      return parts.join(' > ');
    };
    const rows = [];
    for (let cursor = node; cursor; cursor = cursor.parentElement) {
      const style = getComputedStyle(cursor);
      rows.push({ selector: selectorFor(cursor), tag: cursor.localName,
        attributes: Object.fromEntries([...cursor.attributes].filter(attribute => /^(id|class|style|hidden|data-)/.test(attribute.name)).map(attribute => [attribute.name, attribute.value])),
        inline_style: cursor.getAttribute('style'), display: style.display, visibility: style.visibility,
        opacity: style.opacity, rect: cursor.getBoundingClientRect().toJSON() });
    }
    return rows;
  });
  const properties = style => (style?.cssProperties || []).filter(property =>
    ['display', 'visibility', 'opacity', 'pointer-events', 'all', 'content-visibility'].includes(property.name));
  const { root } = await client.send('DOM.getDocument', { depth: 1 });
  for (const element of ancestry) {
    const { nodeId } = await client.send('DOM.querySelector', { nodeId: root.nodeId, selector: element.selector });
    const matched = await client.send('CSS.getMatchedStylesForNode', { nodeId });
    element.inline_properties = properties(matched.inlineStyle);
    element.attribute_properties = properties(matched.attributesStyle);
    element.matched_rules = (matched.matchedCSSRules || []).map(({ rule, matchingSelectors }) => ({
      selector: rule.selectorList?.text, matching_selectors: matchingSelectors.map(index => rule.selectorList?.selectors[index]?.text),
      origin: rule.origin, style_sheet_id: rule.styleSheetId || rule.style?.styleSheetId,
      source_url: sheetHeaders.get(rule.styleSheetId || rule.style?.styleSheetId)?.sourceURL,
      selector_range_zero_based: rule.selectorList?.range, style_range_zero_based: rule.style?.range,
      media: rule.media, supports: rule.supports, layers: rule.layers, properties: properties(rule.style),
    })).filter(rule => rule.properties.length);
  }
  const urls = new Set(ancestry.flatMap(element => element.matched_rules.map(rule => rule.source_url)).filter(Boolean));
  const stylesheetResponses = [];
  for (const response of responses.filter(response => urls.has(response.url()))) {
    const bytes = await boundedRead(() => response.body(), 10000);
    stylesheetResponses.push({ url: publicUrl(response.url()), status: response.status(),
      sha256: Buffer.isBuffer(bytes) ? createHash('sha256').update(bytes).digest('hex') : null,
      byte_length: Buffer.isBuffer(bytes) ? bytes.length : null, error: bytes?.observation_error });
  }
  return { selector, captured_at: new Date().toISOString(), read_only: true, ancestry, stylesheet_responses: stylesheetResponses };
}

async function collectDom(page, geometrySelectors = [], requestCaseAssertions = [], checkoutStages) {
  return page.evaluate(({ geometrySelectors, requestCaseAssertions, checkoutStages }) => {
    const elements = Array.from(document.querySelectorAll('*'));
    const indexes = new Map(elements.map((element, index) => [element, index]));
    const indexOf = element => element ? indexes.get(element) ?? null : null;
    const attributes = element => Object.fromEntries(Array.from(element.attributes).filter(attribute =>
      /^(id|class|role|hidden|aria-|data-|weline-)/.test(attribute.name)).map(attribute => [attribute.name, attribute.value]));
    const ancestors = (element, selector) => {
      const found = [];
      for (let parent = element.parentElement; parent; parent = parent.parentElement) if (parent.matches(selector)) found.push(indexOf(parent));
      return found;
    };
    const state = element => {
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return { x: rect.x + scrollX, y: rect.y + scrollY, width: rect.width, height: rect.height,
        display: style.display, visibility: style.visibility, opacity: style.opacity,
        visible: rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' };
    };
    const slotSelector = '[data-wslot], [data-slot-id]';
    const wrapperSelector = '.widget-wrapper';
    const node = element => ({ index: indexOf(element), parent: indexOf(element.parentElement), tag: element.tagName.toLowerCase(),
      attributes: attributes(element), wrapper_ancestors: ancestors(element, wrapperSelector), slot_ancestors: ancestors(element, slotSelector), ...state(element) });
    const wrappers = elements.filter(element => element.matches(wrapperSelector)).map(element => ({ ...node(element),
      text: (element.innerText || '').trim().slice(0, 400), direct_text: Array.from(element.childNodes).filter(child => child.nodeType === Node.TEXT_NODE).map(child => child.textContent).join(' ').trim().slice(0, 240) }));
    const markers = elements.filter(element => element.hasAttribute('data-widget-code') || element.hasAttribute('data-widget-name')).map(element => ({ ...node(element),
      owning_wrapper: indexOf(element.closest(wrapperSelector)), is_wrapper: element.matches(wrapperSelector) }));
    const slots = elements.filter(element => element.matches(slotSelector)).map(node);
    const regionSelectors = ['[data-slot-id="header"]', '[data-slot-id="content"]', 'main', '[role="main"]', '[data-slot-id="footer"]', '.weline-footer'];
    const serverConfigScopes = [];
    for (const script of document.scripts) {
      const normalized = script.textContent.replace(/\\"/g, '"');
      for (const match of normalized.matchAll(/"configScope"\s*:\s*(\{[^{}]*\})/g)) {
        try {
          const scope = JSON.parse(match[1]);
          serverConfigScopes.push(Object.fromEntries(Object.entries(scope).filter(([key]) => ['website_id', 'website_code', 'store_code', 'channel_code', 'scope_kind'].includes(key))));
        } catch { /* A partial serialized string is not identity evidence. */ }
      }
    }
    return {
      url: location.href, title: document.title, ready_state: document.readyState, lang: document.documentElement.lang,
      html_attributes: attributes(document.documentElement), body_attributes: document.body ? attributes(document.body) : null,
      server_config_scopes: serverConfigScopes,
      declared_website_scopes: Array.from(document.querySelectorAll('[data-i18n-switcher][data-website-id][data-website-mount]')).map(element => ({ website_id: element.getAttribute('data-website-id'), mount: element.getAttribute('data-website-mount') })),
      frontend_runtime_identity: Array.from(document.querySelectorAll('script#weline-frontend-runtime-config[data-weline-runtime-config]')).flatMap(script => {
        try {
          const runtime = JSON.parse(script.textContent);
          return [{ base_host: runtime.site?.base_host, endpoint: runtime.api?.endpoint }];
        } catch { return []; }
      }),
      webdriver: navigator.webdriver, body_text_length: document.body?.innerText?.length || 0,
      body_excerpt: document.body?.innerText?.slice(0, 1500) || '', document_height: document.documentElement.scrollHeight,
      runtime_error_text: /Fatal error|ParseError|WLS Runtime Error|historical_revision_head_missing|historical_intent_reference_missing/.test(document.body?.innerText || ''),
      topology: elements.map(element => ({ index: indexOf(element), parent: indexOf(element.parentElement), tag: element.tagName.toLowerCase(), attributes: attributes(element) })),
      checkout_stage_nodes: checkoutStages ? [...new Set([checkoutStages.hydrated?.when?.root_selector, checkoutStages.hydrated?.when?.extras_selector,
        checkoutStages.hydrated?.when?.credit_content_selector, checkoutStages.hydrated?.when?.shell_selector,
        checkoutStages.hydrated?.checks?.panels_selector, checkoutStages.hydrated?.checks?.panel_selector,
        checkoutStages.hydrated?.checks?.candidate_panel_tab_binding?.tab_selector,
        ...(checkoutStages.hydrated?.branches || []).flatMap(branch => [branch.checks?.panels_selector, branch.checks?.panel_selector, branch.checks?.candidate_panel_tab_binding?.tab_selector])].filter(Boolean))]
        .map(selector => ({ selector, indexes: Array.from(document.querySelectorAll(selector)).map(indexOf) })) : undefined,
      wrappers, markers, slots,
      source_roots: elements.filter(element => element.matches('[data-testid], [weline-code], [class*="wc-theme_widget_"]')).map(node),
      headings: Array.from(document.querySelectorAll('h1,h2,h3')).map(element => ({ index: indexOf(element), level: element.tagName, text: (element.innerText || '').trim() })),
      request_case_nodes: requestCaseAssertions.filter(assertion => assertion.selector).map(assertion => ({ id: assertion.id, selector: assertion.selector,
        nodes: Array.from(document.querySelectorAll(assertion.selector)).map(element => ({ ...node(element), text: (element.innerText || '').trim() })) })),
      canonical_links: Array.from(document.querySelectorAll('link[rel="canonical"]')).map(element => element.href),
      regions: regionSelectors.map(selector => ({ selector, elements: Array.from(document.querySelectorAll(selector)).map(element => ({ ...node(element), text_length: (element.innerText || '').trim().length })) })),
      images: Array.from(document.images).map(element => ({ index: indexOf(element), wrapper: indexOf(element.closest(wrapperSelector)),
        src: element.src, current_src: element.currentSrc, alt: element.alt, loading: element.loading, complete: element.complete,
        natural_width: element.naturalWidth, natural_height: element.naturalHeight, ...state(element) })),
      navigation: performance.getEntriesByType('navigation').map(entry => entry.toJSON()),
      geometry: geometrySelectors.map(selector => ({ selector, elements: Array.from(document.querySelectorAll(selector)).map(element => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        const clippingAncestors = [];
        for (let parent = element.parentElement; parent; parent = parent.parentElement) {
          const parentStyle = getComputedStyle(parent);
          if ([parentStyle.overflowX, parentStyle.overflowY].some(value => ['hidden', 'clip', 'scroll', 'auto'].includes(value))) {
            const parentRect = parent.getBoundingClientRect();
            clippingAncestors.push({ index: indexOf(parent), tag: parent.tagName, attributes: attributes(parent), rect: parentRect.toJSON(), overflow_x: parentStyle.overflowX,
              overflow_y: parentStyle.overflowY, intersects_inside: rect.left >= parentRect.left - 1 && rect.top >= parentRect.top - 1 && rect.right <= parentRect.right + 1 && rect.bottom <= parentRect.bottom + 1 });
          }
        }
        return { ...node(element), rect: rect.toJSON(), text: (element.innerText || '').trim().slice(0, 300), position: style.position,
          overflow_x: style.overflowX, overflow_y: style.overflowY, aspect_ratio: style.aspectRatio, min_height: style.minHeight, max_height: style.maxHeight,
          line_height: style.lineHeight, font_size: style.fontSize, clipping_ancestors: clippingAncestors };
      }) })),
      stylesheets: Array.from(document.styleSheets).map(sheet => ({ href: sheet.href, disabled: sheet.disabled })),
    };
  }, { geometrySelectors, requestCaseAssertions, checkoutStages });
}

async function collectCheckoutSsr(page, response, stages) {
  if (!response) return { available: false, reason: 'Actual document response unavailable' };
  const bytes = await boundedRead(() => response.body());
  if (!Buffer.isBuffer(bytes)) return { available: false, reason: 'Actual document response body unavailable' };
  const sourceChecks = [...(stages.ssr?.source_refs || []), ...(stages.source_refs || [])].map(source => {
    const file = path.resolve(ROOT, source.file);
    const actual = file.startsWith(ROOT + path.sep) && fs.existsSync(file) ? createHash('sha256').update(fs.readFileSync(file)).digest('hex') : null;
    return { file: source.file, expected_sha256: source.sha256, actual_sha256: actual, result: actual === source.sha256 ? 'pass' : 'fail' };
  });
  const topology = await page.evaluate(html => {
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const elements = Array.from(parsed.querySelectorAll('*'));
    const indexes = new Map(elements.map((element, index) => [element, index]));
    return elements.map((element, index) => ({ index, parent: indexes.get(element.parentElement) ?? null, tag: element.tagName.toLowerCase(),
      attributes: Object.fromEntries(Array.from(element.attributes).filter(attribute => /^(id|class|role|hidden|aria-|data-|weline-)/.test(attribute.name)).map(attribute => [attribute.name, attribute.value])) }));
  }, bytes.toString('utf8'));
  return { available: true, body_sha256: createHash('sha256').update(bytes).digest('hex'), body_bytes: bytes.length,
    document_url: publicUrl(response.url()), request_id: response.headers()['x-weline-request-id'], scripts_executed: false, source_sha_checks: sourceChecks, topology };
}

const { verifyContract, verifyGeometry, verifyNavigation, verifyResponseContract, verifyHeroControlsGeometry } = require('./theme-phtml-sitewide-contract');

async function verifyMainHeroInteraction(page, evidence) {
  evidence.wait_started = new Date().toISOString();
  await page.waitForFunction(() => typeof window.WelineSiteBlocks?.mount === 'function', null, { timeout: 10000 });
  const hero = page.locator('.hanfu-default-hero[data-site-block="hero"]');
  await expect(hero).toHaveCount(1);
  const pause = hero.locator('[data-slider-pause]');
  const slides = hero.locator('.slide');
  await expect(hero.locator('.slide.active')).toHaveCount(1);
  const total = await slides.count();
  expect(total).toBeGreaterThan(1);
  await expect(hero.locator('.slide:not(.active)').first()).toHaveAttribute('aria-hidden', 'true');
  evidence.module_ready = await page.evaluate(() => ({ at: new Date().toISOString(), ready_state: document.readyState,
    site_blocks_mount_available: typeof window.WelineSiteBlocks?.mount === 'function',
    resources: performance.getEntriesByType('resource').filter(entry => /\/site-blocks\.js(?:\?|$)/.test(entry.name)).map(entry => entry.toJSON()) }));
  evidence.started = new Date().toISOString();
  evidence.slide_count = total;
  evidence.before_pause = { aria_pressed: await pause.getAttribute('aria-pressed'), text: await pause.textContent(),
    autoplay: await hero.getAttribute('data-autoplay'), autoplay_speed: await hero.getAttribute('data-autoplay-speed'),
    active_index: await hero.locator('.slide.active').getAttribute('data-index') };
  expect(evidence.before_pause.aria_pressed).toBe('false');
  evidence.pause_click_started = new Date().toISOString();
  await pause.click();
  evidence.pause_click_completed = new Date().toISOString();
  evidence.after_pause_click = { aria_pressed: await pause.getAttribute('aria-pressed'), text: await pause.textContent() };
  await expect(pause).toHaveAttribute('aria-pressed', 'true');
  await expect(pause).toHaveText(await pause.getAttribute('data-play-label'));
  const activeIndex = () => hero.locator('.slide.active').getAttribute('data-index');
  evidence.before = Number(await activeIndex());
  evidence.next_click_started = new Date().toISOString();
  await hero.locator('.slider-next').click();
  evidence.next_click_completed = new Date().toISOString();
  const next = (evidence.before + 1) % total;
  await expect(hero.locator('.slide.active')).toHaveCount(1);
  await expect(hero.locator('.slide.active')).toHaveAttribute('data-index', String(next));
  await expect(hero.locator(`.dot[data-index="${next}"]`)).toHaveAttribute('aria-pressed', 'true');
  evidence.after_next = Number(await activeIndex());
  evidence.previous_click_started = new Date().toISOString();
  await hero.locator('.slider-prev').click();
  evidence.previous_click_completed = new Date().toISOString();
  await expect(hero.locator('.slide.active')).toHaveCount(1);
  await expect(hero.locator('.slide.active')).toHaveAttribute('data-index', String(evidence.before));
  evidence.after_previous = Number(await activeIndex());
  evidence.final_slides = await slides.evaluateAll(nodes => nodes.map(node => ({ index: node.dataset.index,
    active: node.classList.contains('active'), aria_hidden: node.getAttribute('aria-hidden'), inert: node.inert })));
  expect(evidence.final_slides.every(slide => slide.active ? slide.aria_hidden === 'false' && !slide.inert : slide.aria_hidden === 'true' && slide.inert)).toBe(true);
  evidence.paused = await pause.getAttribute('aria-pressed');
  evidence.ended = new Date().toISOString();
  evidence.result = 'pass';
}

async function verifyNonemptyCartCheckout(page, item, viewport, testInfo) {
  fs.mkdirSync(EVIDENCE, { recursive: true });
  const prefix = path.join(EVIDENCE, `PHTML-CART-FLOW-${safeName(item.id)}-${Date.now()}`);
  const result = { started: new Date().toISOString(), runner_sha256: RUNNER_HASH, website_id: item.request_scope.website_id,
    intended_identity: item.identity, source_expectations_file: item.source_expectations_file || manifest.source_expectations_file,
    product_url: item.product_url, cart_url: item.cart_url, checkout_url: item.checkout_url, stages: [], network: [], errors: [], cleanup: { result: 'not_attempted' } };
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  await page.setViewportSize(viewport);
  await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
  const bindNetwork = (targetPage, label) => {
    targetPage.on('request', request => result.network.push({ event: 'request', page: label, at: Date.now(), url: publicUrl(request.url()), resource_type: request.resourceType(), ...requestFrame(request, targetPage) }));
    targetPage.on('response', response => result.network.push({ event: 'response', page: label, at: Date.now(), url: publicUrl(response.url()), status: response.status(),
      request_id: response.headers()['x-weline-request-id'], resource_type: response.request().resourceType(), ...requestFrame(response.request(), targetPage) }));
    targetPage.on('requestfailed', request => result.network.push({ event: 'failed', page: label, at: Date.now(), url: publicUrl(request.url()), failure: request.failure(), resource_type: request.resourceType(), ...requestFrame(request, targetPage) }));
    targetPage.on('pageerror', error => result.errors.push({ type: 'pageerror', page: label, message: error.message }));
  };
  bindNetwork(page, 'primary');
  const captureDocument = async (phase, response) => {
    if (!item.capture_document_responses || !response) return;
    const bytes = await response.body();
    const headers = { response: await response.allHeaders(), request: await response.request().allHeaders() };
    const bodyFile = `${prefix}-${phase}-response-private.html`;
    const headersFile = `${prefix}-${phase}-headers-private.json`;
    fs.writeFileSync(bodyFile, bytes, { mode: 0o600 }); fs.chmodSync(bodyFile, 0o600);
    fs.writeFileSync(headersFile, JSON.stringify(headers, null, 2) + '\n', { mode: 0o600 }); fs.chmodSync(headersFile, 0o600);
    const observation = { phase, url: publicUrl(response.url()), captured_at: new Date().toISOString(), status: response.status(),
      request_id: headers.response['x-weline-request-id'], headers, byte_length: bytes.length,
      sha256: createHash('sha256').update(bytes).digest('hex'), body_file: bodyFile, private_headers_file: headersFile };
    (result.document_responses ||= []).push(observation);
    writeJson(`${prefix}-${phase}-response-summary.json`, observation);
    return observation;
  };
  const observe = async (phase, response, observedPage = page, evidenceLabel = phase) => {
    const phaseItem = { ...item, url: phase === 'cart' ? item.cart_url : item.checkout_url, layout_type: phase, layout_option: 'default' };
    const contract = sourceContract(phaseItem);
    await observedPage.evaluate(() => scrollTo(0, 0));
    await observedPage.screenshot({ path: `${prefix}-${evidenceLabel}-viewport.png`, timeout: 15000 });
    for (let step = 0; step < 60; step++) {
      const atEnd = await observedPage.evaluate(() => { const end = Math.max(0, document.documentElement.scrollHeight - innerHeight);
        if (scrollY >= end - 1) return true; scrollTo(0, Math.min(end, scrollY + innerHeight * 0.85)); return false; });
      if (atEnd) break;
      await observedPage.waitForTimeout(150);
    }
    await observedPage.evaluate(() => scrollTo(0, 0));
    await observedPage.screenshot({ path: `${prefix}-${evidenceLabel}-full.png`, fullPage: true, timeout: 15000 });
    const dom = await collectDom(observedPage, [], contract.page?.request_case_assertions || []);
    const html = await observedPage.content();
    fs.writeFileSync(`${prefix}-${evidenceLabel}-dom-private.html`, html, { mode: 0o600 });
    fs.chmodSync(`${prefix}-${evidenceLabel}-dom-private.html`, 0o600);
    const verification = verifyContract(contract, dom);
    result.stages.push({ phase, evidence_label: evidenceLabel, url: publicUrl(observedPage.url()), status: response?.status(), request_id: response?.headers()['x-weline-request-id'],
      source_contract: contract, verification, dom, selected_website_confirmed: dom.server_config_scopes.some(scope => Number(scope.website_id) === Number(item.request_scope.website_id)) });
  };
  let addAttempted = false;
  let primaryError;
  try {
    const emptyResponse = await page.goto(item.cart_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await captureDocument('initial-empty-cart', emptyResponse);
    await expect(page.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', 'empty', { timeout: 30000 });
    await expect(page.locator('[data-weline-cart] [data-cart-line]')).toHaveCount(0);
    result.initial_cart_empty = true;
    await page.goto(item.product_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const detail = page.locator('[data-testid="storefront-product-detail"]');
    await expect(detail).toHaveCount(1);
    await expect(detail).toHaveAttribute('data-product-id', String(item.product_id));
    await page.waitForFunction(() => typeof window.WelineCartPurchaseActions?.bindPurchaseButton === 'function', null, { timeout: 30000 });
    // Use the currently offered/default variant through its existing quantity and
    // purchase controls. An unavailable offer remains a real unmet precondition.
    const quantity = detail.locator('[data-testid="product-qty"]');
    await expect(quantity).toBeVisible();
    await quantity.selectOption('1');
    const add = detail.locator('[data-testid="product-add-to-cart"]:visible');
    await expect(add).toHaveCount(1);
    await expect(add).toBeEnabled();
    result.selected_offer = { product_id: await add.getAttribute('data-product-id'), offer_uuid: await add.getAttribute('data-global-offer-uuid'), quantity: await quantity.inputValue() };
    expect(result.selected_offer.offer_uuid).toBeTruthy();
    expect(result.selected_offer.quantity).toBe('1');
    result.add_click_started = new Date().toISOString();
    addAttempted = true;
    await add.click();
    result.add_click_completed = new Date().toISOString();
    const message = detail.locator('[data-testid="detail-message"]');
    await expect(message).toHaveClass(/is-success/, { timeout: 30000 });
    result.add_message = await message.innerText();
    const [cartResponse] = await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 }), message.locator('.product-native-detail__message-cart-link').click()]);
    await captureDocument('nonempty-cart', cartResponse);
    await expect(page.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', 'ready', { timeout: 30000 });
    const line = page.locator('[data-weline-cart] [data-cart-line]');
    await expect(line).toHaveCount(1);
    await expect(line.locator('[data-cart-quantity]')).toHaveValue('1');
    result.cart_line = await line.evaluate(node => ({ item_id: node.dataset.itemId, product_id: node.dataset.productId, name: node.dataset.productName }));
    await observe('cart', cartResponse);
    if (item.concurrent_cart_documents) {
      const sibling = await page.context().newPage();
      const siblingClient = await page.context().newCDPSession(sibling);
      bindNetwork(sibling, 'parallel-cart-b');
      await siblingClient.send('Network.enable');
      await siblingClient.send('Network.setCacheDisabled', { cacheDisabled: true });
      await sibling.setViewportSize(viewport);
      await sibling.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
      result.parallel_cart_documents = [];
      try {
        const reads = await Promise.all([{ targetPage: page, label: 'parallel-cart-a' }, { targetPage: sibling, label: 'parallel-cart-b' }].map(async ({ targetPage, label }) => {
          const record = { label, intended_url: item.cart_url, started: new Date().toISOString(), method: 'GET' };
          result.parallel_cart_documents.push(record);
          try {
            const response = await targetPage.goto(item.cart_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
            record.navigation_completed = new Date().toISOString();
            record.status = response?.status();
            record.request_id = response?.headers()['x-weline-request-id'];
            await captureDocument(label, response);
            record.document_received = new Date().toISOString();
            record.request_timing = response?.request().timing();
            await expect(targetPage.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', 'ready', { timeout: 30000 });
            await expect(targetPage.locator('[data-weline-cart] [data-cart-line]')).toHaveCount(1);
            return { targetPage, label, response, record };
          } catch (error) { record.error = error.message; return { targetPage, label, record }; }
        }));
        for (const read of reads) {
          read.record.worker_timing = actualRequestTiming(read.record.request_id);
          if (read.response) await observe('cart', read.response, read.targetPage, read.label);
        }
        writeJson(`${prefix}-parallel-documents.json`, result.parallel_cart_documents);
        const failed = reads.find(read => read.record.error);
        if (failed) throw new Error(failed.record.error);
      } finally {
        await siblingClient.detach().catch(() => {});
        await sibling.close().catch(() => {});
      }
    }
    if (item.source_diagnostic_cart) {
      // One explicit diagnostic navigation, separate from normal visual acceptance.
      // It uses the existing performance endpoint without changing product code.
      const diagnosticUrl = new URL(item.cart_url);
      diagnosticUrl.searchParams.set('wls_tpl_perf', '1');
      diagnosticUrl.searchParams.set('weline_trace', '1');
      await page.setExtraHTTPHeaders(item.source_diagnostic_request_headers || {});
      const diagnosticResponse = await page.goto(diagnosticUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 90000 });
      const diagnostic = await captureDocument('nonempty-cart-source-diagnostic', diagnosticResponse);
      result.source_diagnostic = { ...diagnostic, normal_visual_acceptance: false,
        actual_source_execution: actualTemplateSource(diagnostic?.request_id) };
      writeJson(`${prefix}-source-diagnostic.json`, result.source_diagnostic);
      console.log(`SITEWIDE_CART_TRACE ${JSON.stringify({ request_id: diagnostic?.request_id, captured_at: diagnostic?.captured_at, evidence: `${prefix}-source-diagnostic.json` })}`);
      await page.setExtraHTTPHeaders({});
    }
    if (!item.diagnostic_cart_only) {
      const [checkoutResponse] = await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 }), page.locator('[data-weline-cart] [data-cart-checkout]').click()]);
      await expect(page.locator('[data-weline-checkout]')).toHaveAttribute('data-checkout-view', 'ready', { timeout: 30000 });
      await expect(page.locator('[data-checkout-form-host]')).toBeVisible();
      await observe('checkout', checkoutResponse);
    }
  } catch (error) {
    primaryError = error.message;
    result.error = primaryError;
    result.failure_url = publicUrl(page.url());
    result.failure_dom = await boundedRead(() => collectDom(page));
    await page.screenshot({ path: prefix + '-failure.png', fullPage: false, timeout: 10000 }).catch(() => {});
  } finally {
    await page.setExtraHTTPHeaders({}).catch(() => {});
    if (addAttempted && result.initial_cart_empty) {
      try {
        await page.goto(item.cart_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await expect(page.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', /^(ready|empty)$/, { timeout: 30000 });
        const lines = page.locator('[data-weline-cart] [data-cart-line]');
        const count = await lines.count();
        result.cleanup.lines_before = count;
        if (count) {
          await expect(lines).toHaveCount(1);
          const remove = lines.locator('[data-cart-action="remove"]');
          result.cleanup.remove_click_started = new Date().toISOString();
          await remove.click();
          result.cleanup.remove_click_completed = new Date().toISOString();
        }
        await expect(page.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', 'empty', { timeout: 30000 });
        // Cart::render(empty) intentionally keeps the old ready subtree hidden
        // and inert. Check the actual shopper state, then reload the same cart
        // to prove that the server read is empty as well.
        await expect(page.locator('[data-weline-cart] [data-cart-line]:visible')).toHaveCount(0);
        result.cleanup.hidden_lines_retained_before_reload = await lines.count();
        result.cleanup.empty_state_before_reload = true;
        const cleanupResponse = await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await captureDocument('cleanup-empty-cart', cleanupResponse);
        await expect(page.locator('[data-weline-cart]')).toHaveAttribute('data-cart-view', 'empty', { timeout: 30000 });
        await expect(page.locator('[data-weline-cart] [data-cart-line]')).toHaveCount(0);
        result.cleanup.reload_status = cleanupResponse?.status();
        result.cleanup.reload_request_id = cleanupResponse?.headers()['x-weline-request-id'];
        result.cleanup.result = 'pass';
        result.cleanup.lines_after = 0;
      } catch (error) { result.cleanup.result = 'fail'; result.cleanup.error = error.message; }
    } else result.cleanup = { result: 'not_needed', reason: 'No add action occurred.' };
    const failures = result.network.filter(entry => (entry.event === 'response' && entry.status >= 400)
      || (entry.event === 'failed' && entry.failure?.errorText !== 'net::ERR_ABORTED'));
    result.required_resource_failures = failures.filter(entry => !isAncillaryEmbeddedRequest(entry));
    result.ancillary_embedded_request_failures = failures.filter(isAncillaryEmbeddedRequest);
    result.ended = new Date().toISOString();
    result.product_result = !primaryError && result.cleanup.result === 'pass' && result.stages.length === (item.diagnostic_cart_only ? 1 : 2) + (item.concurrent_cart_documents ? 2 : 0)
      && result.stages.every(stage => stage.status === 200 && stage.selected_website_confirmed && !stage.dom.runtime_error_text && stage.verification.result === 'pass')
      && !result.required_resource_failures.length && !result.errors.length ? 'pass' : 'fail';
    writeJson(prefix + '.json', result);
    console.log(`SITEWIDE_CART_FLOW ${item.id} product_result=${result.product_result} stages=${result.stages.length} cleanup=${result.cleanup.result}`);
    await testInfo.attach('nonempty cart and checkout', { path: prefix + '.json', contentType: 'application/json' });
    await client.detach().catch(() => {});
  }
  expect(primaryError, 'The real product→cart→checkout flow must finish without submitting an order.').toBeUndefined();
  expect(result.cleanup.result, 'Own anonymous cart must be removed through its normal UI.').toBe('pass');
  expect(result.stages).toHaveLength((item.diagnostic_cart_only ? 1 : 2) + (item.concurrent_cart_documents ? 2 : 0));
  for (const stage of result.stages) {
    expect(stage.status).toBe(200);
    expect(stage.selected_website_confirmed).toBe(true);
    expect(stage.dom.runtime_error_text).toBe(false);
    expect(stage.verification.result, `${stage.phase} must preserve the source-derived wrappers/slots after real hydration.`).toBe('pass');
  }
  expect(result.required_resource_failures).toEqual([]);
  expect(result.errors).toEqual([]);
}

test.describe('sitewide PHTML real browser observations and source assertions', () => {
  test.setTimeout(180000);
  if (!manifest || manifestError || !Array.isArray(manifest.pages) || !manifest.pages.length) {
    test('SITEWIDE-MANIFEST requires an explicit current site manifest', async () => {
      test.skip(!MANIFEST_PATH, 'Set PHTML_SITEWIDE_MANIFEST to the PM-approved current sites; no deleted fixture fallback.');
      expect(manifestError || (!manifest?.pages?.length && 'manifest.pages must not be empty')).toBeFalsy();
    });
    return;
  }
  const offset = Number(process.env.PHTML_SITEWIDE_OFFSET || 0);
  const limit = Number(process.env.PHTML_SITEWIDE_LIMIT || manifest.pages.length);
  const pages = manifest.pages.slice(offset, offset + limit);
  for (const item of pages) {
    const profile = item.evidence_profile || manifest.evidence_profile || 'full';
    const configuredViewports = item.viewports || manifest.viewports || [{ width: 1440, height: 900 }, { width: 375, height: 812 }];
    for (const viewport of profile === 'compact' ? configuredViewports.slice(0, 1) : configuredViewports) {
      const id = `PHTML-SITE-${safeName(item.id)}-${viewport.width}`;
      moduleCase(test, { module: 'Weline_Theme', id }, `${item.mode === 'verify' ? 'source verification' : 'OBSERVATION ONLY'} ${item.url}`, async ({ page }, testInfo) => {
        if (item.flow === 'cart_checkout') {
          test.setTimeout(360000);
          await verifyNonemptyCartCheckout(page, item, viewport, testInfo);
          return;
        }
        fs.mkdirSync(EVIDENCE, { recursive: true });
        const latestFile = path.join(EVIDENCE, id + '.json');
        const pending = new Map();
        const wire = [];
        const resourceResponses = [];
        const queryDiagnosticTasks = [];
        const failedQueryTasks = [];
        const stylesheetResponses = [];
        const stylesheetHeaders = new Map();
        const documentRequests = [];
        const elementScreenshots = [];
        const heroInteraction = item.verify_main_hero_controls ? { result: 'not_evaluated' } : null;
        const errors = [];
        const consoleDiagnosticTasks = [];
        const started = new Date().toISOString();
        const costs = {};
        const measure = async (name, action) => {
          const start = Date.now();
          try { return await action(); }
          finally { costs[name] = (costs[name] || 0) + Date.now() - start; }
        };
        const contractStarted = Date.now();
        const contract = sourceContract(item);
        const failureQueryWebsitePrefix = require('./theme-phtml-query-diagnostic').frozenWebsitePrefix(item);
        costs.source_contract_lookup_ms = Date.now() - contractStarted;
        test.skip(canResume(latestFile, item, viewport, contract), 'Already rendered and verified this exact URL, identity, source hash and viewport; prior evidence retained.');
        const prefix = path.join(EVIDENCE, `${id}-${Date.now()}`);
        let response;
        let documentResponse;
        let navigationError;
        let dom;
        let screenshotError;
        let protocolXmlDocument = false;
        let failureStage;
        let stage = 'navigation';
        await page.setViewportSize(viewport);
        await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
        const client = await page.context().newCDPSession(page);
        await client.send('Network.enable');
        await client.send('Network.setCacheDisabled', { cacheDisabled: true });
        if (item.capture_visibility_css_selector) {
          client.on('CSS.styleSheetAdded', ({ header }) => stylesheetHeaders.set(header.styleSheetId, header));
          await client.send('DOM.enable');
          await client.send('CSS.enable');
        }
        const requestHeaders = Object.fromEntries(Object.entries({
          ...((item.capture_source === true || manifest.capture_source === true) ? { 'X-Weline-Trace': '1' } : {}),
          ...(manifest.request_headers || {}), ...(item.request_headers || {}),
        }).map(([key, value]) => [key.toLowerCase(), String(value)]));
        if (Object.keys(requestHeaders).length) await page.setExtraHTTPHeaders(requestHeaders);
        page.on('pageerror', error => errors.push({ type: 'pageerror', message: error.message }));
        page.on('console', message => {
          if (message.type() !== 'error' && message.type() !== 'warning') return;
          const entry = { type: message.type(), message: message.text() };
          errors.push(entry);
          if (item.capture_console_diagnostics) {
            entry.at = Date.now(); entry.location = { ...message.location(), url: publicUrl(message.location().url) };
            consoleDiagnosticTasks.push((async () => {
              entry.safe_object_fields = [];
              for (const arg of message.args()) {
                try {
                  const properties = await arg.getProperties(); const fields = {};
                  for (const key of ['reason', 'deliveries', 'syncDepth', 'observeStack', 'createStack']) {
                    if (!properties.has(key)) continue;
                    const value = await properties.get(key).jsonValue();
                    if (['string', 'number', 'boolean'].includes(typeof value)) fields[key] = value;
                  }
                  if (Object.keys(fields).length) entry.safe_object_fields.push(fields);
                } catch { /* Unknown console objects are not serialized. */ }
              }
            })());
          }
        });
        page.on('request', request => {
          const entry = { started: Date.now(), method: request.method(), url: publicUrl(request.url()), resource_type: request.resourceType(), navigation: request.isNavigationRequest(), ...requestFrame(request, page) };
          pending.set(request, entry);
          wire.push({ event: 'request', ...entry });
          if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documentRequests.push(request);
        });
        page.on('response', result => {
          const headers = result.headers();
          if (result.request().isNavigationRequest() && result.request().frame() === page.mainFrame()) documentResponse = result;
          const entry = pending.get(result.request());
          if (item.capture_query_bin_diagnostic === true && new URL(result.url()).pathname === '/api/framework/query-bin') {
            queryDiagnosticTasks.push(require('./theme-phtml-query-diagnostic').observeQueryResponse(result, ROOT, entry?.started)
              .catch(() => ({ observation_error: 'query diagnostic unavailable' })));
          } else if (require('./theme-phtml-query-diagnostic').shouldObserveFailedQueryResponse(result.url(), result.status(), item.url, item.locale, failureQueryWebsitePrefix)) {
            failedQueryTasks.push(require('./theme-phtml-query-diagnostic').observeFailedQueryResponse(result, ROOT, entry?.started)
              .catch(() => ({ observation_error: 'Actual failed query packet unavailable', status: result.status(), request_id: headers['x-weline-request-id'] })));
          }
          if ((item.resource_hash_assertions || []).some(assertion => new URL(result.url()).pathname === assertion.path)) resourceResponses.push(result);
          if (item.capture_visibility_css_selector && result.request().resourceType() === 'stylesheet') stylesheetResponses.push(result);
          if (entry) Object.assign(entry, { status: result.status(), response_received: Date.now(), request_id: headers['x-weline-request-id'] });
          wire.push({ event: 'response', at: Date.now(), url: publicUrl(result.url()), status: result.status(), request_id: headers['x-weline-request-id'], resource_type: result.request().resourceType(),
            ...requestFrame(result.request(), page), main_document: result.request().isNavigationRequest() && result.request().frame() === page.mainFrame(), location: headers.location ? publicUrl(new URL(headers.location, result.url()).toString()) : undefined });
        });
        page.on('requestfinished', request => { wire.push({ event: 'finished', at: Date.now(), url: publicUrl(request.url()) }); pending.delete(request); });
        page.on('requestfailed', request => { wire.push({ event: 'failed', ...(pending.get(request) || {}), at: Date.now(), url: publicUrl(request.url()), resource_type: request.resourceType(), ...requestFrame(request, page), failure: request.failure() }); pending.delete(request); });
        const url = new URL(item.url);
        if (item.server_cache_mode !== 'normal') url.searchParams.set('no_cache', '1');
        // Template performance query flags insert diagnostic bars into the document.
        // A trace header alone does not guarantee an execution trace. Normal visual
        // acceptance keeps diagnostic bars out and records any unavailable evidence.
        try {
          response = await measure('navigation_ms', () => page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 90000 }));
          protocolXmlDocument = contract.page?.acceptance_kind === 'non_html_resource'
            && /^(?:application|text)\/xml(?:\s*;|$)/i.test(await response.headerValue('content-type') || '');
          if (protocolXmlDocument) fs.writeFileSync(prefix + '-response.xml', await response.body());
          if (item.verify_main_hero_controls) {
            stage = 'hero_controls_interaction';
            await verifyMainHeroInteraction(page, heroInteraction);
          }
          if (profile === 'full') {
            stage = 'viewport_screenshot';
            await measure('normal_screenshot_ms', () => page.screenshot({ path: prefix + '-viewport.png', fullPage: false, timeout: 15000 }));
          }
          // Scroll the real page so its own lazy components see the viewport. Do not
          // replace scripts, inject components, or wait for third-party networkidle.
          stage = 'lazy_content_scroll';
          const scrollStarted = Date.now();
          for (let step = 0; step < 60; step++) {
            const atEnd = await page.evaluate(() => {
              const end = Math.max(0, document.documentElement.scrollHeight - innerHeight);
              if (scrollY >= end - 1) return true;
              scrollTo(0, Math.min(end, scrollY + innerHeight * 0.85));
              return false;
            });
            if (atEnd) break;
            await page.waitForTimeout(150);
          }
          await page.evaluate(() => scrollTo(0, 0));
          costs.real_scroll_ms = Date.now() - scrollStarted;
          if (profile === 'full') {
            stage = 'full_screenshot';
            // Chromium XML documents have no HTML full-page scrolling surface.
            // Preserve the complete wire body and capture their native viewport;
            // HTML pages still require the full-page screenshot.
            await measure('normal_screenshot_ms', () => page.screenshot({ path: prefix + '-full.png', fullPage: !protocolXmlDocument, timeout: 15000 }));
          }
          for (const target of item.element_screenshots || []) {
            stage = 'element_screenshot';
            const locator = page.locator(target.selector);
            const count = await locator.count();
            const visible = count === 1 && await locator.isVisible();
            const filename = `${prefix}-${safeName(target.name)}.png`;
            if (visible) await measure('normal_screenshot_ms', () => locator.screenshot({ path: filename, timeout: 15000 }));
            elementScreenshots.push({ selector: target.selector, count, visible, file: visible ? filename : null });
          }
        } catch (error) { navigationError = error.message; failureStage = stage; if (heroInteraction && stage === 'hero_controls_interaction') Object.assign(heroInteraction, { result: 'fail', error: error.message }); if (stage.includes('screenshot')) screenshotError = error.message; }
        finally {
          dom = await measure('dom_collection_ms', () => boundedRead(() => collectDom(page, item.geometry_selectors || [], contract.page?.request_case_assertions || [], contract.page?.browser_assertions?.checkout_summary_order)));
          const moduleAcceptance = item.verify_helppay_module ? await require('./theme-phtml-module-acceptance').verifyHelpPayModule(page) : null;
          if (item.capture_console_diagnostics) await Promise.allSettled(consoleDiagnosticTasks);
          const visibilityCss = item.capture_visibility_css_selector ? await boundedRead(() => collectMatchedVisibilityCss(
            page, client, item.capture_visibility_css_selector, stylesheetHeaders, stylesheetResponses), 15000) : null;
          if (visibilityCss) writeJson(prefix + '-visibility-css.json', visibilityCss);
          const documentChain = await measure('document_chain_collection_ms', () => Promise.all(documentRequests.map(async request => {
            const result = await boundedRead(() => request.response(), 5000);
            const resultHeaders = result?.headers ? result.headers() : {};
            return { url: publicUrl(request.url()), method: request.method(), status: result?.status ? result.status() : null,
              request_headers: await boundedRead(() => request.allHeaders(), 5000), request_id: resultHeaders['x-weline-request-id'],
              location: resultHeaders.location ? publicUrl(new URL(resultHeaders.location, request.url()).toString()) : null,
              redirected_from: request.redirectedFrom() ? publicUrl(request.redirectedFrom().url()) : null,
              redirected_to: request.redirectedTo() ? publicUrl(request.redirectedTo().url()) : null };
          })));
          const comparisonStarted = Date.now();
          const observedResponse = response || documentResponse;
          if (dom?.topology && contract.page?.browser_assertions?.order?.some(order => order.stage)) {
            dom.ssr_document = await collectCheckoutSsr(page, observedResponse, contract.page.browser_assertions.checkout_summary_order || {});
          }
          let verification = dom?.topology ? verifyContract(contract, dom) : { result: 'not_evaluated', reason: 'DOM unavailable', checks: [] };
          costs.source_dom_comparison_ms = Date.now() - comparisonStarted;
          const headers = observedResponse?.headers() || {};
          let responseContract = null;
          let responseBodyEvidence = null;
          let responseOnly = false;
          if (contract.page?.response_assertions) {
            const bytes = await measure('response_body_contract_ms', () => boundedRead(() => observedResponse.body(), 15000));
            const bodyFile = prefix + '-response-private.bin';
            if (Buffer.isBuffer(bytes)) { fs.writeFileSync(bodyFile, bytes, { mode: 0o600 }); fs.chmodSync(bodyFile, 0o600); }
            responseBodyEvidence = { file: Buffer.isBuffer(bytes) ? bodyFile : null, bytes: Buffer.isBuffer(bytes) ? bytes.length : null,
              sha256: Buffer.isBuffer(bytes) ? createHash('sha256').update(bytes).digest('hex') : null, error: bytes?.observation_error };
            responseContract = verifyResponseContract(contract, { status: observedResponse?.status(), content_type: headers['content-type'],
              body: Buffer.isBuffer(bytes) ? bytes.toString('utf8') : undefined });
            const structureChecks = verification.checks || [];
            responseOnly = ['static_error_response', 'non_html_resource'].includes(contract.page.acceptance_kind) && !structureChecks.length;
            verification = responseOnly ? responseContract : { ...verification, result: verification.result === 'fail' || responseContract?.result === 'fail' ? 'fail'
              : verification.result === 'pass' && responseContract?.result === 'pass' ? 'pass' : 'not_evaluated', checks: [...structureChecks, ...(responseContract?.checks || [])] };
          }
          const resourceHashChecks = await measure('resource_body_hash_check_ms', () => Promise.all((item.resource_hash_assertions || []).map(async assertion => {
            const observations = [];
            for (const result of resourceResponses.filter(result => new URL(result.url()).pathname === assertion.path)) {
              const bytes = await boundedRead(() => result.body(), 10000);
              observations.push({ url: publicUrl(result.url()), status: result.status(), byte_length: Buffer.isBuffer(bytes) ? bytes.length : null,
                sha256: Buffer.isBuffer(bytes) ? createHash('sha256').update(bytes).digest('hex') : null,
                contains: (assertion.contains || []).map(text => ({ text, present: Buffer.isBuffer(bytes) && bytes.toString('utf8').includes(text) })), error: bytes?.observation_error });
            }
            return { type: 'resource_body_sha256', path: assertion.path, expected_sha256: assertion.sha256, observations,
              result: observations.length > 0 && observations.every(observation => observation.status === 200 && observation.sha256 === assertion.sha256 && observation.contains.every(entry => entry.present)) ? 'pass' : 'fail' };
          })));
          const resourceChecksStarted = Date.now();
          const runtimeChecks = (item.forbidden_request_paths || []).map(forbiddenPath => {
            const requests = wire.filter(entry => entry.event === 'request' && new URL(entry.url).pathname === forbiddenPath);
            return { type: 'forbidden_request_path', path: forbiddenPath, expected_count: 0, actual_count: requests.length, result: requests.length ? 'fail' : 'pass' };
          }).concat(moduleAcceptance ? [{ type: 'normal_helppay_module_workflow', ...moduleAcceptance }] : [], verifyGeometry(item.geometry_assertions, dom), resourceHashChecks,
            verifyNavigation(contract.page?.request_branch, documentChain, page.url()),
            item.verify_main_hero_controls && viewport.width <= 768 ? verifyHeroControlsGeometry(dom) : [],
            heroInteraction ? [{ type: 'hero_controls_interaction', ...heroInteraction }] : []);
          const initialResourceFailures = wire.filter(entry => (entry.event === 'response' && entry.resource_type !== 'document' && entry.status >= 400)
            || (entry.event === 'failed' && entry.failure?.errorText !== 'net::ERR_ABORTED'));
          const initialJavascriptErrors = errors.filter(entry => entry.type === 'pageerror');
          costs.resource_and_runtime_classification_ms = Date.now() - resourceChecksStarted;
          const hasFailure = navigationError || verification.result === 'fail' || dom?.runtime_error_text || runtimeChecks.some(check => check.result === 'fail')
            || initialResourceFailures.length || initialJavascriptErrors.length || wire.some(entry => entry.event === 'response' && entry.status >= 400);
          if (profile === 'full' || hasFailure) {
            const html = await boundedRead(() => page.content());
            if (typeof html === 'string') { fs.writeFileSync(prefix + '-dom-private.html', html, { mode: 0o600 }); fs.chmodSync(prefix + '-dom-private.html', 0o600); }
          }
          if (hasFailure) {
            try { await page.screenshot({ path: prefix + '-failure.png', fullPage: false, timeout: 10000 }); }
            catch (error) { screenshotError = error.message; }
          }
          const expectedHttp = item.expected_http ?? contract.page?.response_assertions?.status ?? 200;
          const serverAddress = observedResponse ? await boundedRead(() => observedResponse.serverAddr(), 5000) : null;
          const logicalTransport = require('./theme-phtml-sitewide-contract').sourceRequestUrl(item);
          const mappedHost = localHostMappings.find(mapping => mapping.host === new URL(item.url).hostname)
            || (logicalTransport.mapped ? { host: new URL(item.url).hostname, address: item.transport_proof.expected_server_address } : null);
          const websiteIdentity = require('./theme-phtml-website-identity').verifySelectedWebsite(dom, item, ROOT);
          const transportVerification = mappedHost ? {
            host: mappedHost.host, expected_address: mappedHost.address, server_address: serverAddress, logical_url: item.logical_url, actual_transport_url: item.url,
            document_http_200: observedResponse?.status() === 200,
            expected_status: expectedHttp, source_status_confirmed: observedResponse?.status() === expectedHttp,
            loopback_confirmed: ['127.0.0.1', '::1', '[::1]', '::ffff:127.0.0.1'].includes(serverAddress?.ipAddress),
            selected_website_confirmed: websiteIdentity.confirmed,
            website_identity_check: responseOnly ? 'not_exposed_by_source_response_branch' : websiteIdentity.source,
            website_identity_evidence: websiteIdentity,
          } : { mapping: 'existing local domain', server_address: serverAddress };
          const source = item.capture_source === true || manifest.capture_source === true ? actualTemplateSource(headers['x-weline-request-id']) : { available: false, reason: 'Full trace not requested for this URL; independent source matrix retained.' };
          if (profile === 'compact' && !hasFailure && dom?.topology) {
            const keep = new Set([...dom.wrappers, ...dom.slots, ...dom.markers, ...dom.source_roots,
              ...(dom.request_case_nodes || []).flatMap(group => group.nodes)].flatMap(node => [node.index, ...(node.wrapper_ancestors || []), ...(node.slot_ancestors || [])]));
            const all = new Map(dom.topology.map(node => [node.index, node]));
            for (const index of [...keep]) for (let node = all.get(index); node; node = all.get(node.parent)) keep.add(node.index);
            dom.topology_total_elements = dom.topology.length;
            dom.topology = dom.topology.filter(node => keep.has(node.index));
          }
          const queryDiagnostic = item.capture_query_bin_diagnostic === true ? {
            sequence: (await Promise.all(queryDiagnosticTasks)).map(entry => typeof entry.finalize === 'function' ? entry.finalize() : entry),
            checkout_business_state: await page.locator('[data-weline-checkout]').evaluateAll(nodes => nodes.map(node => ({ view: node.getAttribute('data-checkout-view'), visible: !!(node.getBoundingClientRect().width && node.getBoundingClientRect().height) }))),
          } : undefined;
          const failedQueryEvidence = (await Promise.all(failedQueryTasks)).map(entry => typeof entry.finalize === 'function' ? entry.finalize() : entry);
          // 最终判定与证据使用同一观察边界，涵盖异步取证期间已收到的失败响应。
          const resourceFailures = wire.filter(entry => (entry.event === 'response' && entry.resource_type !== 'document' && entry.status >= 400)
            || (entry.event === 'failed' && entry.failure?.errorText !== 'net::ERR_ABORTED'));
          const ancillaryResourceFailures = resourceFailures.filter(isAncillaryEmbeddedRequest);
          const requiredResourceFailures = resourceFailures.filter(entry => !isAncillaryEmbeddedRequest(entry));
          const javascriptErrors = errors.filter(entry => entry.type === 'pageerror');
          const runtimeFailed = !!(navigationError || dom?.runtime_error_text || runtimeChecks.some(check => check.result === 'fail')
            || requiredResourceFailures.length > 0 || javascriptErrors.length > 0
            || (item.mode === 'verify' && observedResponse?.status() !== expectedHttp)
            || (mappedHost && (!transportVerification.source_status_confirmed || !transportVerification.loopback_confirmed
              || (!responseOnly && !transportVerification.selected_website_confirmed))));
          const productResult = item.mode === 'verify' ? (runtimeFailed ? 'fail' : verification.result) : 'not_evaluated';
          // 比对仍使用完整独立源合同；通过用例引用原文件，失败用例保留完整输入。
          const contractEvidence = productResult === 'pass' && contract.path && contract.sha256
            ? { ...contract, page: undefined, page_key: `${item.identity.theme_id}/${item.layout_type}/${item.layout_option}`, page_storage: 'referenced_source_contract' }
            : contract;
          const evidence = { id, started, ended: new Date().toISOString(), runner_sha256: RUNNER_HASH, mode: item.mode || 'observe', product_result: productResult,
            intended_url: item.url, logical_url: item.logical_url, observed_url: publicUrl(page.url()), intended_identity: item.identity, request_scope: item.request_scope, target: item.target, locale: item.locale,
            family: item.family, auth_state_condition: item.auth_state_condition, transport_verification: transportVerification, layout_type: item.layout_type, layout_option: item.layout_option,
            server_cache_mode: item.server_cache_mode || 'no_cache_query_only', request_headers_requested: requestHeaders, document_chain: documentChain,
            viewport, browser_cache_disabled: true, protocol_unchanged: true, template_debug_bars_requested: url.searchParams.get('wls_tpl_perf') === '1', status: observedResponse?.status(), request_id: headers['x-weline-request-id'],
            costs_ms: { ...costs, observed_total_wall_ms: Date.now() - Date.parse(started) },
            response_headers: Object.fromEntries(Object.entries(headers).filter(([key]) => /^(content-type|content-encoding|x-weline-|x-cache|x-fpc|server|cache-control)/i.test(key))),
            evidence_profile: profile, full_screenshot_mode: protocolXmlDocument ? 'xml_viewport_with_complete_response_body' : 'full_page', evidence_prefix: prefix, observation_error: navigationError, failure_stage: failureStage, screenshot_error: screenshotError, source_contract: contractEvidence, actual_source_execution: source,
            verification, response_contract: responseContract, response_body_evidence: responseBodyEvidence, element_screenshots: elementScreenshots,
            visibility_css_diagnostic: visibilityCss ? { file: prefix + '-visibility-css.json', observation_error: visibilityCss.observation_error } : null,
            acceptance_kind: contract.page?.acceptance_kind || 'phtml_source_and_dom', runtime_checks: runtimeChecks, runtime_verification: { failed: runtimeFailed, resource_failures: resourceFailures,
              required_resource_failures: requiredResourceFailures, ancillary_embedded_request_failures: ancillaryResourceFailures, javascript_errors: javascriptErrors },
            acceptance_states: { layout_interaction_contract: navigationError || dom?.runtime_error_text || runtimeChecks.some(check => check.result === 'fail') ? 'fail' : verification.result,
              required_resources: requiredResourceFailures.length || javascriptErrors.length ? 'fail' : 'pass',
              ancillary_embedded_requests: ancillaryResourceFailures.length ? 'failure_observed' : 'no_failure_observed' },
            errors, dom, query_diagnostic: queryDiagnostic, failed_query_evidence: failedQueryEvidence, network: wire, pending: Array.from(pending.entries()).map(([request, entry]) => ({ ...entry, elapsed_ms: Date.now() - entry.started, timing: request.timing() })) };
          writeJson(prefix + '.json', evidence);
          writeJson(latestFile, evidence);
          await testInfo.attach('sitewide observation', { path: prefix + '.json', contentType: 'application/json' });
          testInfo.annotations.push({ type: item.mode === 'verify' ? 'source_contract' : 'baseline_only', description: `product_result=${evidence.product_result}; expected source is independent of DOM` });
          console.log(`SITEWIDE_OBSERVATION ${id} http=${observedResponse?.status()} product_result=${evidence.product_result} wrappers=${dom?.wrappers?.length} markers=${dom?.markers?.length}`);
          await client.detach().catch(() => {});
          expect(navigationError, 'Browser observation must finish; evidence preserves the failing boundary.').toBeUndefined();
          expect(runtimeChecks.filter(check => check.result === 'fail'), 'Concrete runtime regressions retain their own assertions alongside source comparison.').toEqual([]);
          if (mappedHost) {
            expect(transportVerification.source_status_confirmed, 'Mapped response must match its independent source status.').toBe(true);
            expect(transportVerification.loopback_confirmed, 'Mapped site must connect to loopback.').toBe(true);
            if (!responseOnly) expect(transportVerification.selected_website_confirmed, 'Server document must identify the requested catalog website.').toBe(true);
          }
          if (item.mode === 'verify') {
            expect(response?.status()).toBe(expectedHttp);
            expect(dom.runtime_error_text).toBe(false);
            expect(verification.result, 'Unresolved source conditions are not a rendering pass.').toBe('pass');
            expect(productResult, 'Source presence cannot hide a real resource, JavaScript, navigation or geometric failure.').toBe('pass');
          }
        }
      });
    }
  }
});
