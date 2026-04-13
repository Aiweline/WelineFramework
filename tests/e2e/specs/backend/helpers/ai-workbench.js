const {
  buildBackendUrl,
  getBackendRoot,
  getBaseUrl,
  getRuntimeInfo,
  loginAsAdmin,
} = require('../../../framework');

/**
 * Hub 页模板输出的服务端 URL（与 getBackendUrl 一致）；无则回退。
 * @param {import('@playwright/test').Page} page
 * @param {string} inputId
 * @param {string} fallbackAbsoluteUrl
 */
async function readHubApiUrlFromDom(page, inputId, fallbackAbsoluteUrl) {
  const loc = page.locator(`#${inputId}`);
  const exists = await loc.count().catch(() => 0);
  if (!exists) {
    return fallbackAbsoluteUrl;
  }
  const raw = await loc.getAttribute('value').catch(() => null);
  const s = raw != null ? String(raw).trim() : '';
  return s !== '' ? s : fallbackAbsoluteUrl;
}

function resolveSiteBuilderBackendRoot(page, fallbackBackendRoot) {
  const fb = String(fallbackBackendRoot || getBackendRoot()).replace(/\/+$/, '');
  try {
    const u = new URL(page.url());
    const m = u.pathname.match(/^(\/[^/]+\/[^/]+\/[^/]+)(?=\/)/);
    if (m) {
      return `${u.origin}${m[1]}`;
    }
  } catch (e) {
    // ignore
  }
  return fb;
}

/**
 * 兼容代理环境：当模板回传 target-origin 绝对 URL 时，强制回落到当前页面 origin。
 * @param {import('@playwright/test').Page} page
 * @param {string} absoluteOrRelativeUrl
 */
function normalizeApiUrlForPage(page, absoluteOrRelativeUrl) {
  const base = new URL(page.url());
  const target = new URL(String(absoluteOrRelativeUrl || ''), base.toString());
  if (target.origin === base.origin) {
    return target.toString();
  }
  return new URL(`${target.pathname}${target.search}${target.hash}`, base.toString()).toString();
}

/**
 * Resolve Websites workspace public_id from URL first, then from state_json_url or scope textarea when the URL is unstable.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function resolveWorkspacePublicId(page) {
  const pageUrl = String(page.url() || '');
  const publicMatch = pageUrl.match(/[?&]public_id=([^&]+)/);
  if (publicMatch && publicMatch[1]) {
    return decodeURIComponent(publicMatch[1]);
  }

  const fromStateJsonUrl = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a[href]'));
    for (const link of links) {
      const href = String(link.getAttribute('href') || '').trim();
      if (!href || href.indexOf('get-state-json') < 0) {
        continue;
      }
      try {
        const url = new URL(href, window.location.origin);
        const publicId = String(url.searchParams.get('public_id') || '').trim();
        if (publicId) {
          return publicId;
        }
      } catch (error) {
        continue;
      }
    }

    const scopeEl = document.querySelector('#site-builder-scope-full');
    const raw = scopeEl && 'value' in scopeEl ? String(scopeEl.value || '').trim() : '';
    if (!raw) {
      return '';
    }
    try {
      const parsed = JSON.parse(raw);
      return String(parsed.public_id || parsed.session_public_id || '').trim();
    } catch (error) {
      return '';
    }
  }).catch(() => '');

  return String(fromStateJsonUrl || '').trim();
}

/**
 * Merge Websites workspace scope through the same backend API the page uses, then reload the page.
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {Record<string, any>} scopePatch
 */
async function mergeWebsitesScope(page, backendRoot, scopePatch) {
  const publicId = await resolveWorkspacePublicId(page);
  if (!publicId) {
    throw new Error('mergeWebsitesScope: public_id not found in workspace URL or DOM');
  }

  const root = String(backendRoot || resolveSiteBuilderBackendRoot(page, backendRoot)).replace(/\/+$/, '');
  const mergeUrl = normalizeApiUrlForPage(
    page,
    new URL('websites/backend/site-builder-agent/merge-scope', `${root}/`).toString()
  );

  const result = await page.evaluate(
    async ({ url, pid, patch }) => {
      const fd = new FormData();
      fd.append('public_id', pid);
      fd.append('scope_patch', JSON.stringify(patch || {}));
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        return { success: false, message: text.slice(0, 400), raw_status: res.status };
      }
    },
    { url: mergeUrl, pid: publicId, patch: scopePatch }
  );

  if (!result || !result.success) {
    throw new Error((result && result.message) ? String(result.message) : `mergeWebsitesScope failed: ${JSON.stringify(result)}`);
  }

  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  return result;
}

function buildWorkbenchUrl(backendRoot = getBackendRoot(), provider = 'pagebuilder', fakeMode = false) {
  const normalizedBackendRoot = String(backendRoot || getBackendRoot()).replace(/\/+$/, '');
  const base = new URL(`${normalizedBackendRoot}/`);
  const runtime = getRuntimeInfo();
  const backendKey = String(runtime.paths?.backend_prefix_path || '')
    .replace(/^\/+|\/+$/g, '');
  const segments = base.pathname.split('/').filter(Boolean);
  const currency = process.env.PLAYWRIGHT_BACKEND_CURRENCY || 'CNY';
  const lang = process.env.PLAYWRIGHT_BACKEND_LANG || 'zh_Hans_CN';

  let pathPrefix = base.pathname.replace(/\/+$/, '') || '/';
  // loginAsAdmin → deriveBackendRoot：若后台首页为 /{backendKey}/admin/{ccy}/{lang}/...，
  // lastIndexOf('/admin') 会截成仅 /{backendKey}，此处补全货币/语言段，否则会出现
  // /{backendKey}/websites/... 被路由拒绝，Hub 无 #site-agent-description。
  const isBareBackendKeyOnly = backendKey !== '' && segments.length === 1 && segments[0] === backendKey;
  if (isBareBackendKeyOnly) {
    pathPrefix = `/${backendKey}/${currency}/${lang}`;
  }

  const url = new URL(`${base.origin}${pathPrefix}/websites/backend/site-builder-agent/index`);
  url.searchParams.set('provider', provider);
  if (fakeMode) {
    url.searchParams.set('fake_mode', '1');
  }
  return url.toString();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {string} providerCode
 * @param {string} description
 * @param {{ fakeMode?: boolean }} [options]
 */
async function createWorkspace(page, backendRoot, providerCode, description, options = {}) {
  const fakeMode = Boolean(options.fakeMode);
  const root = resolveSiteBuilderBackendRoot(page, backendRoot);
  const fallbackCreate = new URL('websites/backend/site-builder-agent/create-session', `${root}/`).toString();
  const createSessionUrl = normalizeApiUrlForPage(
    page,
    await readHubApiUrlFromDom(page, 'site-agent-api-create-session', fallbackCreate)
  );
  /** 与 index.phtml 中 postCreateWorkspace 一致；避免 page.request（Node 侧）与浏览器命中不同路由或 404。 */
  return page.evaluate(
    async ({ url, providerCode: provider, description: brief, fakeMode: isFake }) => {
      const fd = new FormData();
      fd.append('provider_code', provider);
      fd.append('description', brief);
      fd.append('domain', '');
      fd.append('account_id', '0');
      fd.append('use_ai', '1');
      if (isFake) {
        fd.append('fake_mode', '1');
      }
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error(
          `create-session: HTTP ${res.status} non-JSON: ${String(e && e.message ? e.message : e)} body=${text.slice(0, 500)}`
        );
      }
      if (!res.ok) {
        throw new Error(`create-session: HTTP ${res.status} body=${text.slice(0, 500)}`);
      }
      return data;
    },
    { url: createSessionUrl, providerCode, description, fakeMode }
  );
}

/**
 * Build trigger-sse URL (local fake AI site build timeline).
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {Record<string, string|number|boolean>} query
 */
function buildTriggerSseUrl(page, backendRoot, query) {
  const root = String(resolveSiteBuilderBackendRoot(page, backendRoot)).replace(/\/+$/, '');
  const url = new URL('websites/backend/site-builder-agent/trigger-sse', `${root}/`);
  Object.entries(query).forEach(([k, v]) => {
    url.searchParams.set(k, String(v));
  });
  return url.toString();
}

/**
 * Parse a full SSE response body (WLS may close browser streams early; Playwright request drains fully).
 * @param {string} raw
 * @returns {{ events: Array<{ event: string, data: any }>, lastDone: any }}
 */
function parseSseResponseText(raw) {
  const normalized = String(raw || '')
    .replace(/^\uFEFF/, '')
    .replace(/\r\n/g, '\n');
  const blocks = normalized.split('\n\n').filter((b) => b.trim() !== '');
  /** @type {Array<{ event: string, data: any }>} */
  const events = [];
  function tryParseNestedJson(value) {
    if (typeof value !== 'string') {
      return value;
    }
    const trimmed = value.trim();
    if (!trimmed || !/^[\[{]/.test(trimmed)) {
      return value;
    }
    try {
      return JSON.parse(trimmed);
    } catch (error) {
      return value;
    }
  }
  for (const block of blocks) {
    const lines = block.split('\n');
    let eventName = 'message';
    const dataLines = [];
    for (const line of lines) {
      const ev = line.match(/^event:\s*(.*)$/i);
      if (ev) {
        eventName = String(ev[1] || '').trim() || 'message';
        continue;
      }
      const da = line.match(/^data:\s?(.*)$/);
      if (da) {
        dataLines.push(String(da[1] ?? ''));
      }
    }
    const dataLine = dataLines.join('\n').trim();
    if (dataLine === '') {
      continue;
    }
    let parsed = dataLine;
    try {
      parsed = JSON.parse(dataLine);
    } catch (e) {
      // keep string
    }
    parsed = tryParseNestedJson(parsed);
    events.push({ event: eventName, data: parsed });
  }
  let lastDone = null;
  for (let i = events.length - 1; i >= 0; i -= 1) {
    const { event, data } = events[i];
    if (event === 'done' && data && typeof data === 'object' && !Array.isArray(data)) {
      lastDone = data;
      break;
    }
  }
  if (!lastDone) {
    for (let i = events.length - 1; i >= 0; i -= 1) {
      const data = events[i].data;
      if (
        data
        && typeof data === 'object'
        && !Array.isArray(data)
        && data.fake_mode
        && data.website_id != null
      ) {
        lastDone = data;
        break;
      }
    }
  }
  // 流偶发截断时最后一条可能是无 fake_mode 的 done；回退到带 fake_mode 的 progress/info
  if (lastDone && lastDone.fake_mode == null && lastDone.success === true) {
    for (let i = events.length - 1; i >= 0; i -= 1) {
      const data = events[i].data;
      if (
        data
        && typeof data === 'object'
        && !Array.isArray(data)
        && data.fake_mode != null
        && data.website_id != null
      ) {
        lastDone = { ...data, ...lastDone };
        break;
      }
    }
  }
  return { events, lastDone };
}

/**
 * Browser-context SSE consumer fallback for direct/WLS responses that Playwright APIRequest cannot parse.
 * @param {import('@playwright/test').Page} page
 * @param {string} absoluteUrl
 * @param {number} timeoutMs
 */
async function consumeSseStreamInPage(page, absoluteUrl, timeoutMs) {
  return page.evaluate(async ({ url, timeout }) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/event-stream' },
    });

    if (!response.ok || !response.body) {
      return {
        ok: false,
        status: response.status,
        text: await response.text().catch(() => ''),
      };
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let raw = '';
    const deadline = Date.now() + timeout;

    while (Date.now() < deadline) {
      const chunk = await Promise.race([
        reader.read(),
        new Promise(resolve => setTimeout(() => resolve({ timeout: true }), 400)),
      ]);

      if (chunk && chunk.timeout) {
        continue;
      }
      if (!chunk || chunk.done) {
        break;
      }
      raw += decoder.decode(chunk.value, { stream: true });
      if (raw.includes('\nevent: done') || raw.startsWith('event: done') || raw.includes('\n\n')) {
        if (raw.includes('event: done')) {
          break;
        }
      }
    }

    await reader.cancel().catch(() => {});
    return {
      ok: true,
      status: response.status,
      text: raw,
    };
  }, { url: absoluteUrl, timeout: timeoutMs });
}

/**
 * 在临时页用 fetch 拉满 SSE 正文，避免主页面导航打断 evaluate，也避免 Node APIRequest 对流式响应截断。
 * @param {import('@playwright/test').Page} page
 * @param {string} absoluteUrl
 * @param {{ timeoutMs?: number }} [options]
 */
async function consumeSseStream(page, absoluteUrl, options = {}) {
  const timeoutMs = options.timeoutMs ?? 120000;
  try {
    const response = await page.request.get(absoluteUrl, {
      timeout: timeoutMs,
      headers: { Accept: 'text/event-stream' },
    });
    const bundle = {
      ok: response.ok(),
      status: response.status(),
      text: await response.text(),
    };
    const status = Number(bundle && bundle.status ? bundle.status : 0);
    const raw = String((bundle && bundle.text) || '');
    const contentType = 'text/event-stream';
    if (!bundle || !bundle.ok) {
      return {
        ok: false,
        status,
        events: [],
        lastDone: null,
        contentType,
        eventNames: [],
        rawHead: raw.slice(0, 240),
        error: `HTTP ${status}`,
      };
    }
    const { events, lastDone } = parseSseResponseText(raw);
    return {
      ok: true,
      status,
      events,
      lastDone,
      contentType,
      eventNames: events.map((e) => e.event),
      rawHead: raw.slice(0, 240),
    };
  } catch (e) {
    try {
      const browserBundle = await consumeSseStreamInPage(page, absoluteUrl, timeoutMs);
      const status = Number(browserBundle && browserBundle.status ? browserBundle.status : 0);
      const raw = String((browserBundle && browserBundle.text) || '');
      if (!browserBundle || !browserBundle.ok) {
        return {
          ok: false,
          status,
          events: [],
          lastDone: null,
          contentType: '',
          eventNames: [],
          rawHead: raw.slice(0, 240),
          error: String(e && e.message ? e.message : e),
        };
      }
      const { events, lastDone } = parseSseResponseText(raw);
      return {
        ok: true,
        status,
        events,
        lastDone,
        contentType: 'text/event-stream',
        eventNames: events.map((evt) => evt.event),
        rawHead: raw.slice(0, 240),
      };
    } catch (inner) {
      return {
        ok: false,
        status: 0,
        events: [],
        lastDone: null,
        contentType: '',
        eventNames: [],
        rawHead: '',
        error: String(inner && inner.message ? inner.message : inner) + '\n' + String(e && e.message ? e.message : e),
      };
    }
  }
}

/**
 * Start domain purchase (POST); in fake_mode the server completes immediately (no SSE stream).
 * Prefer API JSON over DOM polling — UI may lag after reload.
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {{ timeoutMs?: number }} [options]
 * @returns {Promise<{ order_id: number }>}
 */
/**
 * 更新工作区阶段（与 workspace 内 postForm 一致），避免 Playwright 在 reload 后无法读取 response body。
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {string} stage prepare|generate|complete
 */
async function postSetStageFromWorkspace(page, backendRoot, stage) {
  const pageUrl = page.url();
  const publicMatch = String(pageUrl).match(/[?&]public_id=([^&]+)/);
  if (!publicMatch) {
    throw new Error('postSetStageFromWorkspace: public_id not found in workspace URL');
  }
  const publicId = decodeURIComponent(publicMatch[1]);
  const root = String(backendRoot || resolveSiteBuilderBackendRoot(page, backendRoot)).replace(/\/+$/, '');
  const fallbackUrl = new URL('websites/backend/site-builder-agent/set-stage', `${root}/`).toString();
  const fromDom = await page.locator('#site-builder-api-set-stage').inputValue().catch(() => '');
  const postUrl = normalizeApiUrlForPage(page, String(fromDom || '').trim() || fallbackUrl);
  return page.evaluate(
    async ({ url, pid, st }) => {
      const fd = new FormData();
      fd.append('public_id', pid);
      fd.append('stage', st);
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error(`set-stage: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
      if (!data || !data.success) {
        throw new Error((data && data.message) || 'set-stage failed');
      }
      return data;
    },
    { url: postUrl, pid: publicId, st: stage }
  );
}

async function triggerFakeDomainPurchase(page, backendRoot, options = {}) {
  const timeoutMs = options.timeoutMs ?? 120000;
  const allowReloginRetry = options.allowReloginRetry !== false;
  const publicId = await resolveWorkspacePublicId(page);
  if (!publicId) {
    throw new Error('triggerFakeDomainPurchase: public_id not found in workspace URL');
  }
  const root = String(backendRoot || resolveSiteBuilderBackendRoot(page, backendRoot)).replace(/\/+$/, '');
  const fallbackPost = new URL(
    'websites/backend/site-builder-agent/start-domain-purchase',
    `${root}/`
  ).toString();
  const fromDom = await page.locator('#site-builder-api-start-domain-purchase').inputValue().catch(() => '');
  const postUrl = normalizeApiUrlForPage(page, String(fromDom || '').trim() || fallbackPost);

  /** 与 workspace 内 postForm 一致；避免 page.request（Node）未带浏览器会话而被重定向到登录页 */
  const data = await page.evaluate(
    async ({ url, pid }) => {
      const fd = new FormData();
      fd.append('public_id', pid);
      fd.append('scope_patch', JSON.stringify({}));
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        return { success: false, message: text.slice(0, 400), raw_status: res.status };
      }
    },
    { url: postUrl, pid: publicId }
  );
  if (!data || !data.success) {
    const failureMessage = String((data && data.message) || '');
    const looksLikeLoginHtml = /Weline\s*登录面板|<\s*!\s*DOCTYPE\s+html/i.test(failureMessage);
    if (allowReloginRetry && looksLikeLoginHtml) {
      await loginAsAdmin(page);
      return triggerFakeDomainPurchase(page, backendRoot, { ...options, allowReloginRetry: false });
    }
    throw new Error((data && data.message) || 'start-domain-purchase failed');
  }
  const fromApi = Number(data.state && data.state.order_id ? data.state.order_id : 0);
  if (fromApi > 0) {
    return { order_id: fromApi };
  }
  await page.waitForFunction(
    () => {
      const el = document.querySelector('#site-builder-domain-order-id');
      const n = Number(String((el && el.textContent) || '').trim() || '0');
      return n > 0;
    },
    null,
    { timeout: timeoutMs }
  );
  const text = await page.locator('#site-builder-domain-order-id').textContent();
  return { order_id: Number(String(text || '').trim() || '0') };
}

/**
 * Read domain purchase UI state from visible workbench fields (not a hidden textarea in current template).
 * @param {import('@playwright/test').Page} page
 */
async function readDomainPurchaseState(page) {
  const statusBadge = await page.locator('#site-builder-domain-status-badge').textContent();
  const orderText = await page.locator('#site-builder-domain-order-id').textContent();
  const domainText = await page.locator('#site-builder-domain-progress-domain').textContent();
  const messageText = await page.locator('#site-builder-domain-message').textContent();
  return {
    status_label: String(statusBadge || '').trim(),
    order_id: Number(String(orderText || '').trim() || '0'),
    domain: String(domainText || '').trim(),
    message: String(messageText || '').trim(),
  };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} absoluteUrl
 * @param {string} eventName
 * @param {{ timeoutMs?: number }} [options]
 */
async function waitForSseEvent(page, absoluteUrl, eventName, options = {}) {
  const timeoutMs = options.timeoutMs ?? 120000;
  const result = await consumeSseStream(page, absoluteUrl, { timeoutMs });
  if (!result.ok) {
    return { ok: false, error: result.error || result.status, events: result.events || [] };
  }
  const hit = (result.events || []).find((e) => e.event === eventName);
  return { ok: Boolean(hit), event: hit || null, events: result.events || [], lastDone: result.lastDone };
}

/**
 * POST recommend-domain with optional fake_mode (body).
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {{ description?: string, domain?: string, accountId: number, fakeMode?: boolean }} body
 */
async function postRecommendDomain(page, backendRoot, body) {
  const root = String(resolveSiteBuilderBackendRoot(page, backendRoot)).replace(/\/+$/, '');
  const fallbackRec = new URL('websites/backend/site-builder-agent/recommend-domain', `${root}/`).toString();
  const postUrl = normalizeApiUrlForPage(
    page,
    await readHubApiUrlFromDom(page, 'site-agent-api-recommend-domain', fallbackRec)
  );
  return page.evaluate(
    async ({ url: u, payload }) => {
      const fd = new FormData();
      if (payload.description) {
        fd.append('description', payload.description);
      }
      if (payload.domain) {
        fd.append('domain', payload.domain);
      }
      fd.append('account_id', String(payload.accountId));
      if (payload.fakeMode) {
        fd.append('fake_mode', '1');
      }
      const res = await fetch(u, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error(`recommend-domain: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
      return data;
    },
    { url: postUrl, payload: body }
  );
}

module.exports = {
  buildWorkbenchUrl,
  buildTriggerSseUrl,
  createWorkspace,
  mergeWebsitesScope,
  consumeSseStream,
  postRecommendDomain,
  postSetStageFromWorkspace,
  resolveWorkspacePublicId,
  resolveSiteBuilderBackendRoot,
  triggerFakeDomainPurchase,
  readDomainPurchaseState,
  waitForSseEvent,
  buildBackendUrl,
  getBaseUrl,
  loginAsAdmin,
};
