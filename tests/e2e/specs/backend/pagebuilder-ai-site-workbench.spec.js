// @weline-e2e-runtime fallback
// @ts-check
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { buildBackendUrl, getRuntimeInfo, moduleDescribe, moduleCase } = require('../../framework');
const {
  buildWorkbenchUrl,
  consumeSseStream,
  createWorkspace,
  loginAsAdmin,
  resolveSiteBuilderBackendRoot,
  triggerFakeDomainPurchase,
} = require('./helpers/ai-workbench');

const WORKSPACE_TIMEOUT = 300000;
/** 路由表可能输出 ai-site-agent，兼容历史 aiSiteAgent 形式 */
const PAGEBUILDER_AI_WORKSPACE_PATH_RE = /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/workspace\b/i;

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 */
async function gotoStable(page, url) {
  await page.goto(url, { waitUntil: 'commit', timeout: WORKSPACE_TIMEOUT });
  await page.locator('body').first().waitFor({ state: 'attached', timeout: 30000 });
  await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
}

function buildLocalDomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.local.test`;
}

/** 与 `php bin/w server:hosts:add` 一致；工作区根 = tests/e2e/specs/backend → 上四级 */
function devWorkspaceRootFromThisSpec() {
  return path.resolve(__dirname, '../../../..');
}

/**
 * 将 `*.weline.local` 子域写入本机 hosts，便于 Playwright 真实地址栏访问前台。
 * 设 `PLAYWRIGHT_SKIP_HOSTS_REGISTER=1` 则跳过写入，用例仍走 API+Host 回退断言。
 * @param {string} fqdn 例如 pb-e2e-12345678.weline.local
 * @returns {{ ok: boolean, skipped?: boolean, message?: string }}
 */
function tryRegisterWelineLocalSubdomain(fqdn) {
  if (process.env.PLAYWRIGHT_SKIP_HOSTS_REGISTER === '1') {
    return { ok: false, skipped: true, message: 'PLAYWRIGHT_SKIP_HOSTS_REGISTER=1' };
  }
  const root = devWorkspaceRootFromThisSpec();
  try {
    execFileSync('php', ['bin/w', 'server:hosts:add', fqdn], {
      cwd: root,
      stdio: 'pipe',
      encoding: 'utf8',
    });
    return { ok: true };
  } catch (e) {
    const msg = [e && e.stdout, e && e.stderr, e && e.message ? e.message : e]
      .filter(Boolean)
      .join('\n');
    return { ok: false, skipped: false, message: String(msg).slice(0, 800) };
  }
}

function buildWelineLocalSubdomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.weline.local`;
}

async function startPagebuilderBuild(page, backendRoot, scopePatch) {
  await page.evaluate(() => {
    const bridge = window.PbAiWorkspacePreview || null;
    if (bridge && typeof bridge.pauseWorkspaceStream === 'function') {
      bridge.pauseWorkspaceStream();
    }
  }).catch(() => {});
  const current = new URL(page.url());
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, '/post-start-build');
  const postUrl = normalizeToCurrentOrigin(page, current.toString());
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const res = await page.request.post(postUrl, {
    form: {
      public_id: publicId,
      scope_patch: JSON.stringify(scopePatch),
    },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`pagebuilder post-start-build: HTTP ${res.status()} non-JSON body=${text.slice(0, 400)}`);
  }

  expect(payload && payload.success, JSON.stringify(payload)).toBeTruthy();
  expect(payload.stream_url).toBeTruthy();
  await page.goto('about:blank', { waitUntil: 'load', timeout: 30000 }).catch(() => {});
  return payload;
}

async function requestPagebuilderPublish(page) {
  await page.evaluate(() => {
    const bridge = window.PbAiWorkspacePreview || null;
    if (bridge && typeof bridge.pauseWorkspaceStream === 'function') {
      bridge.pauseWorkspaceStream();
    }
  }).catch(() => {});
  const current = new URL(page.url());
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, '/post-start-publish');
  const postUrl = normalizeToCurrentOrigin(page, current.toString());
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const res = await page.request.post(postUrl, {
    form: { public_id: publicId },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`pagebuilder post-start-publish: HTTP ${res.status()} non-JSON body=${text.slice(0, 400)}`);
  }

  if (payload && payload.success) {
    await page.goto('about:blank', { waitUntil: 'load', timeout: 30000 }).catch(() => {});
  }
  return payload;
}

async function startPagebuilderPublish(page) {
  const payload = await requestPagebuilderPublish(page);
  expect(payload && payload.success, JSON.stringify(payload)).toBeTruthy();
  expect(payload.stream_url).toBeTruthy();
  return payload;
}

async function runPagebuilderPublishChecklist(page) {
  const current = new URL(page.url());
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, '/post-publish-checklist');
  const postUrl = normalizeToCurrentOrigin(page, current.toString());
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const res = await page.request.post(postUrl, {
    form: { public_id: publicId },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`pagebuilder post-publish-checklist: HTTP ${res.status()} non-JSON body=${text.slice(0, 400)}`);
  }
  expect(payload && payload.success, JSON.stringify(payload)).toBeTruthy();
  return payload;
}

async function mergePagebuilderScope(page, scopePatch) {
  const current = new URL(page.url());
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, '/post-merge-scope');
  const postUrl = normalizeToCurrentOrigin(page, current.toString());
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const res = await page.request.post(postUrl, {
    form: {
      public_id: publicId,
      scope_patch: JSON.stringify(scopePatch),
    },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`pagebuilder post-merge-scope: HTTP ${res.status()} non-JSON body=${text.slice(0, 400)}`);
  }

  expect(payload && payload.success, JSON.stringify(payload)).toBeTruthy();
  return payload;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function readJsonTextarea(page, selector) {
  const raw = await page.locator(selector).inputValue({ timeout: WORKSPACE_TIMEOUT });
  return JSON.parse(String(raw || '').trim());
}

/**
 * 从 SSE 事件中提取 page_generated 的页面类型集合。
 * @param {{ events?: Array<{event:string, data:any}> }} stream
 * @returns {Set<string>}
 */
function collectPageGeneratedTypes(stream) {
  const generated = new Set();
  const events = Array.isArray(stream && stream.events) ? stream.events : [];
  for (const evt of events) {
    if (!evt || evt.event !== 'page_generated') {
      continue;
    }
    const data = evt.data && typeof evt.data === 'object' ? evt.data : {};
    const pageType = String(data.page_type || '').trim();
    if (pageType) {
      generated.add(pageType);
    }
  }
  return generated;
}

/**
 * 验证构建流里每个页面类型都完成了 page_generated 事件。
 * @param {{ events?: Array<{event:string, data:any}>, lastDone?: any }} buildStream
 * @param {string[]} selectedPageTypes
 */
function expectBuildSseCoveredAllPageTypes(buildStream, selectedPageTypes) {
  const generatedTypes = collectPageGeneratedTypes(buildStream);
  expect(generatedTypes.size, `expected at least ${selectedPageTypes.length} page_generated events`).toBeGreaterThanOrEqual(
    selectedPageTypes.length
  );
  for (const pageType of selectedPageTypes) {
    expect(generatedTypes.has(pageType), `missing SSE page_generated marker for page type: ${pageType}`).toBeTruthy();
  }
  expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();
}

/**
 * 后端有时返回 target-origin 的绝对链接；在 e2e 代理下需要强制回当前 origin。
 * @param {import('@playwright/test').Page} page
 * @param {string} href
 */
function normalizeToCurrentOrigin(page, href) {
  let base;
  try {
    base = new URL(page.url());
    if (!/^https?:$/i.test(base.protocol)) {
      throw new Error('non-http page url');
    }
  } catch (error) {
    const runtime = getRuntimeInfo();
    base = new URL(String(runtime.runtime?.target_origin || 'https://127.0.0.1'));
  }
  const target = new URL(href, base.toString());
  if (target.origin === base.origin) {
    return target.toString();
  }
  return new URL(`${target.pathname}${target.search}${target.hash}`, base.toString()).toString();
}

/**
 * 指标卡（draft website / theme id）仅在专家布局渲染；Guided 默认只有主按钮。
 * @param {import('@playwright/test').Page} page
 */
async function ensurePagebuilderExpertLayout(page) {
  const hasCard = await page.locator('#pb-ai-draft-website-id').count().catch(() => 0);
  if (hasCard > 0) {
    return;
  }

  const advancedLink = page.locator('a[href*="expert=1"], a:has-text("高级模式")').first();
  if (await advancedLink.isVisible({ timeout: 8000 }).catch(() => false)) {
    await Promise.all([
      page.waitForURL(/[?&]expert=1\b/i, { timeout: WORKSPACE_TIMEOUT }),
      advancedLink.click(),
    ]);
    await gotoStable(page, page.url());
    return;
  }

  const u = new URL(page.url());
  u.searchParams.set('expert', '1');
  await gotoStable(page, u.toString());

  await expect(page.locator('#pb-ai-draft-website-id')).toBeVisible({ timeout: 30000 });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 */
async function createDirectPagebuilderWorkspace(page, backendRoot) {
  if (!/^https?:/i.test(String(page.url() || ''))) {
    const warmUrl = normalizeToCurrentOrigin(
      page,
      new URL('/', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    await gotoStable(page, warmUrl);
  }
  const createSessionUrl = normalizeToCurrentOrigin(
    page,
    new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
  );
  const createPayload = await page.evaluate(async ({ url }) => {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
    }
  }, { url: createSessionUrl });
  expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
  expect(createPayload.workspace_url).toBeTruthy();
  const workspaceUrl = normalizeToCurrentOrigin(page, String(createPayload.workspace_url));
  await gotoStable(page, workspaceUrl);
  await ensurePagebuilderExpertLayout(page);
  return { createPayload, workspaceUrl };
}

/**
 * Handoff 控制器在异常时会回退到 legacy index；此时从 Websites 镜像工作区 scope 读取 PageBuilder workspace 直链。
 * @param {import('@playwright/test').Page} page
 * @param {number} [timeoutMs]
 */
async function waitForPagebuilderWorkspaceUrlFromWebsites(page, timeoutMs = 90000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    /** @type {{ kind: 'url', value: string }|{ kind: 'public_id', value: string }|{ kind: 'empty' }} */
    const bundle = await page.evaluate(() => {
      const el = document.querySelector('#site-builder-pagebuilder-workspace-url');
      const v = el && 'value' in el ? String(el.value || '').trim() : '';
      if (v !== '' && /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/workspace\b/i.test(v)) {
        return { kind: 'url', value: v };
      }
      const scopeEl = document.querySelector('#site-builder-scope-full');
      const raw = scopeEl && 'value' in scopeEl ? String(scopeEl.value || '').trim() : '';
      if (raw === '') {
        return { kind: 'empty' };
      }
      try {
        const obj = JSON.parse(raw);
        const fromUrl = String(obj.pagebuilder_workspace_url || '').trim();
        if (fromUrl !== '') {
          return { kind: 'url', value: fromUrl };
        }
        const pid = String(obj.pagebuilder_workspace_public_id || '').trim();
        if (pid !== '') {
          return { kind: 'public_id', value: pid };
        }
      } catch (e) {
        return { kind: 'empty' };
      }
      return { kind: 'empty' };
    });

    if (bundle.kind === 'url' && bundle.value) {
      return bundle.value;
    }
    if (bundle.kind === 'public_id' && bundle.value) {
      const root = String(resolveSiteBuilderBackendRoot(page, '')).replace(/\/+$/, '');
      const u = new URL('pagebuilder/backend/aiSiteAgent/workspace', `${root}/`);
      u.searchParams.set('public_id', bundle.value);
      return u.toString();
    }

    await new Promise((r) => {
      setTimeout(r, 400);
    });
  }
  return '';
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} websitesWorkspaceUrl
 */
async function ensurePagebuilderAiWorkspace(page, websitesWorkspaceUrl) {
  const onWorkspace = PAGEBUILDER_AI_WORKSPACE_PATH_RE.test(page.url());
  if (onWorkspace) {
    return;
  }
  await gotoStable(page, websitesWorkspaceUrl);
  let direct = await waitForPagebuilderWorkspaceUrlFromWebsites(page, 90000);
  if (!direct) {
    await page.locator('#site-builder-reload-page').click({ force: true }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    direct = await waitForPagebuilderWorkspaceUrlFromWebsites(page, 60000);
  }
  expect(direct, 'pagebuilder_workspace_url / public_id not populated after handoff').toBeTruthy();
  await gotoStable(page, normalizeToCurrentOrigin(page, direct));
  await expect(page).toHaveURL(PAGEBUILDER_AI_WORKSPACE_PATH_RE);
}

/**
 * Websites create-session 在本地 HTTPS/代理切换时偶发 upstream SSL 握手失败，做一次重试兜底。
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {string} brief
 * @param {{ provider?: string, fakeMode?: boolean, retries?: number }} [options]
 */
async function createWorkspaceWithRetry(page, backendRoot, brief, options = {}) {
  const provider = options.provider || 'pagebuilder';
  const fakeMode = options.fakeMode !== false;
  const retries = Number(options.retries || 2);
  let lastError = null;
  for (let attempt = 1; attempt <= retries; attempt += 1) {
    try {
      const payload = await createWorkspaceViaApiRequest(page, backendRoot, provider, brief, fakeMode);
      if (payload && payload.success && payload.workspace_url) {
        return payload;
      }
      lastError = new Error(`createWorkspace returned invalid payload: ${JSON.stringify(payload)}`);
    } catch (error) {
      lastError = error;
    }

    if (attempt < retries) {
      await gotoStable(page, buildWorkbenchUrl(backendRoot, provider, fakeMode));
      await page.waitForTimeout(1000);
    }
  }
  throw lastError || new Error('createWorkspaceWithRetry failed without explicit error');
}

/**
 * 用 APIRequestContext 创建 Websites 工作区，避免浏览器内 fetch 偶发 TLS/代理失败。
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 * @param {string} provider
 * @param {string} brief
 * @param {boolean} fakeMode
 */
async function createWorkspaceViaApiRequest(page, backendRoot, provider, brief, fakeMode) {
  const postUrl = buildBackendUrl('websites/backend/site-builder-agent/create-session');
  const res = await page.request.post(postUrl, {
    form: {
      provider_code: provider,
      description: brief,
      domain: '',
      account_id: '0',
      use_ai: '1',
      ...(fakeMode ? { fake_mode: '1' } : {}),
    },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`create-session(api request): HTTP ${res.status()} non-JSON body=${text.slice(0, 500)}`);
  }
  if (!res.ok || !payload || !payload.success) {
    throw new Error(`create-session(api request) failed: HTTP ${res.status()} payload=${JSON.stringify(payload).slice(0, 800)}`);
  }
  return payload;
}

test.describe('PageBuilder AI site building (websites_default provider → PageBuilder workspace)', () => {
  test.describe.configure({ mode: 'serial' });

  test('full flow: hub → handoff → pb virtual theme build', async ({ page }) => {
    test.slow();
    test.setTimeout(480000);

    const backendRoot = await loginAsAdmin(page);

    const brief = 'Fashion boutique online store with brand story, about, and contact pages.';

    const payload = await createWorkspaceWithRetry(page, backendRoot, brief, {
      provider: 'pagebuilder',
      fakeMode: true,
      retries: 3,
    });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = normalizeToCurrentOrigin(page, String(payload.workspace_url));
    await gotoStable(page, workspaceUrl);

    const localDomain = buildLocalDomain('pb-full');
    await expect(page.locator('#site-builder-title')).toBeVisible({ timeout: 15000 });
    await page.fill('#site-builder-title', 'Fashion Boutique');
    await page.fill('#site-builder-tagline', 'Style your story');
    await page.fill('#site-builder-domain', localDomain);
    await page.fill('#site-builder-brief', 'Need a stunning homepage with hero, about page with brand story, and a contact page.');
    await page.click('#site-builder-save-summary', { force: true });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await expect(page.locator('#site-builder-domain')).toHaveValue(localDomain, { timeout: 15000 });
    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'domain purchase order_id should be > 0').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const handoffHref = await handoffLink.getAttribute('href');
    expect(handoffHref, 'handoff link href should not be empty').toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(handoffHref)));
    await ensurePagebuilderAiWorkspace(page, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);
    await expect(page.locator('#pb-ai-run-virtual-theme')).toBeVisible({ timeout: 30000 });

    await page.click('#pb-ai-run-virtual-theme', { force: true });

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-draft-website-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-virtual-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });
  });

  test('workspace exposes virtual-theme pipeline controls and api endpoints', async ({ page }) => {
    test.slow();
    test.setTimeout(360000);

    const backendRoot = await loginAsAdmin(page);

    const brief = 'Landing site with a hero block and contact page for AI pipeline verification.';
    const payload = await createWorkspaceWithRetry(page, backendRoot, brief, {
      provider: 'pagebuilder',
      fakeMode: true,
      retries: 3,
    });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = normalizeToCurrentOrigin(page, String(payload.workspace_url));
    await gotoStable(page, workspaceUrl);
    await expect(page.locator('#site-builder-title')).toBeVisible({ timeout: 15000 });

    for (const selector of [
      '#site-builder-api-start-domain-purchase',
      '#site-builder-api-set-stage',
      '#site-builder-api-save-summary',
      '#site-builder-api-load-workspace',
    ]) {
      const value = await page.locator(selector).inputValue();
      expect(value, `${selector} should expose backend api url`).toMatch(/\/backend\//i);
    }

    const localDomain = buildLocalDomain('pb-virtual');
    await page.fill('#site-builder-title', 'AI Pipeline Verification');
    await page.fill('#site-builder-domain', localDomain);
    await page.fill('#site-builder-brief', 'Validate virtual theme controls and AI handoff chain.');
    await page.click('#site-builder-save-summary', { force: true });
    await expect(page.locator('#site-builder-domain')).toHaveValue(localDomain, { timeout: 15000 });

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'domain purchase should return order id').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const handoffHref = await handoffLink.getAttribute('href');
    expect(handoffHref, 'handoff link href should not be empty').toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(handoffHref)));
    await ensurePagebuilderAiWorkspace(page, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);

    await expect(page.locator('#pb-ai-run-virtual-theme')).toBeVisible({ timeout: 30000 });
    await page.click('#pb-ai-run-virtual-theme', { force: true });

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-draft-website-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-virtual-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });
  });

  test('canonical virtual_theme_id survives workspace preview and virtual editor handoff', async ({ page }) => {
    test.slow();
    test.setTimeout(480000);

    const backendRoot = await loginAsAdmin(page);
    const createSessionUrl = normalizeToCurrentOrigin(
      page,
      new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    const createPayload = await page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
    }, { url: createSessionUrl });
    expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
    expect(createPayload.workspace_url).toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(createPayload.workspace_url)));
    await ensurePagebuilderExpertLayout(page);
    await expect(page.locator('#pb-ai-site-title')).toBeVisible({ timeout: 30000 });

    const localDomain = buildLocalDomain('pb-canonical');
    const selectedPageTypes = ['home_page', 'about_page', 'contact_page'];
    await page.fill('#pb-ai-site-title', 'Canonical Virtual Theme Flow');
    await page.fill('#pb-ai-site-tagline', 'Canonical virtual_theme_id verification');
    await page.fill('#pb-ai-target-domain', localDomain);
    await page.fill('#pb-ai-brief-description', 'Build a homepage, about page, and contact page. Then verify every preview and editor link uses virtual_theme_id only.');

    const scopePatch = {
      site_title: 'Canonical Virtual Theme Flow',
      site_tagline: 'Canonical virtual_theme_id verification',
      target_domain: localDomain,
      brief_description: 'Build a homepage, about page, and contact page. Then verify every preview and editor link uses virtual_theme_id only.',
      user_description: 'Build a homepage, about page, and contact page. Then verify every preview and editor link uses virtual_theme_id only.',
      page_types: selectedPageTypes,
    };
    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, {
      ...scopePatch,
    });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(Array.isArray(buildStream.eventNames) ? buildStream.eventNames : []).toContain('done');
    expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();

    await gotoStable(page, page.url());

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-draft-website-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-virtual-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });

    const state = await readJsonTextarea(page, '#pb-ai-scope-full');
    const virtualThemeId = Number(state.virtual_theme_id || 0);
    const previewPageId = Number(state.preview_page_id || 0);
    const previewPageType = String(state.preview_page_type || '');
    const visualPreviewUrl = String(state.visual_preview_url || '');
    const visualEditUrl = String(state.visual_edit_url || '');

    expect(virtualThemeId, 'virtual_theme_id should be present after orchestration').toBeGreaterThan(0);
    expect(previewPageType, 'preview_page_type should be present after orchestration').toBeTruthy();
    expect(selectedPageTypes).toContain(previewPageType);
    expect(previewPageId).toBeGreaterThanOrEqual(0);
    expect(visualPreviewUrl).toContain(`public_id=${createPayload.public_id}`);
    expect(visualPreviewUrl).toContain(`page_type=${previewPageType}`);
    expect(visualPreviewUrl).toContain(`virtual_theme_id=${virtualThemeId}`);
    expect(visualPreviewUrl).not.toContain('weline_theme_id=');
    expect(visualEditUrl).toContain(`public_id=${createPayload.public_id}`);
    expect(visualEditUrl).toContain(`page_type=${previewPageType}`);
    expect(visualEditUrl).toContain(`virtual_theme_id=${virtualThemeId}`);
    expect(visualEditUrl).not.toContain('weline_theme_id=');

    const workspacePreviewSrc = await page.locator('#pb-ai-visual-preview-frame').getAttribute('src');
    expect(workspacePreviewSrc).toContain('/pagebuilder/backend/preview/full');
    expect(workspacePreviewSrc).toContain(`page_type=${previewPageType}`);
    expect(workspacePreviewSrc).toContain(`virtual_theme_id=${virtualThemeId}`);
    expect(workspacePreviewSrc).not.toContain('weline_theme_id=');

    const editorHref = await page.locator('#pb-ai-open-visual-editor').getAttribute('href');
    expect(editorHref).toContain('/pagebuilder/backend/page/virtual-edit');
    expect(editorHref).toContain(`public_id=${createPayload.public_id}`);
    expect(editorHref).toContain(`page_type=${previewPageType}`);
    expect(editorHref).toContain('virtual_theme_id=');
    expect(editorHref).not.toContain('weline_theme_id=');

    const editorUrl = normalizeToCurrentOrigin(page, String(editorHref));
    const editorResponse = await page.request.get(editorUrl);
    expect(editorResponse.ok(), `virtual-edit HTTP ${editorResponse.status()}`).toBeTruthy();
    const editorHtml = await editorResponse.text();
    expect(editorHtml).not.toContain('weline_theme_id=');
  });

  test('expert: preview tabs switch iframe src page_type', async ({ page }) => {
    test.slow();
    test.setTimeout(480000);

    const backendRoot = await loginAsAdmin(page);
    const createSessionUrl = normalizeToCurrentOrigin(
      page,
      new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    const createPayload = await page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
    }, { url: createSessionUrl });
    expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(createPayload.workspace_url)));
    await ensurePagebuilderExpertLayout(page);

    const suffix = Date.now().toString().slice(-8);
    const localDomain = buildLocalDomain(`pb-tab-${suffix}`);
    const selectedPageTypes = ['home_page', 'about_page', 'contact_page'];
    const scopePatch = {
      site_title: `E2E PB Preview Tab ${suffix}`,
      site_tagline: 'preview tab switch',
      target_domain: localDomain,
      brief_description: 'Multi-page site for preview tab E2E.',
      user_description: 'Multi-page site for preview tab E2E.',
      page_types: selectedPageTypes,
    };
    await page.fill('#pb-ai-site-title', scopePatch.site_title);
    await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
    await page.fill('#pb-ai-target-domain', localDomain);
    await page.fill('#pb-ai-brief-description', scopePatch.brief_description);
    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(buildStream.eventNames).toContain('done');

    await gotoStable(page, page.url());
    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });

    const aboutTab = page.locator('.pb-ai-preview-tab[data-page-type="about_page"]');
    await expect(aboutTab).toBeVisible({ timeout: 15000 });
    await aboutTab.click();
    await expect
      .poll(async () => (await page.locator('#pb-ai-visual-preview-frame').getAttribute('src')) || '', {
        timeout: 30000,
      })
      .toMatch(/page_type=about_page/);

    await page.locator('.pb-ai-preview-tab[data-page-type="contact_page"]').click();
    await expect
      .poll(async () => (await page.locator('#pb-ai-visual-preview-frame').getAttribute('src')) || '', {
        timeout: 30000,
      })
      .toMatch(/page_type=contact_page/);
  });

  test('expert: page type repick modal confirm triggers post-start-build', async ({ page }) => {
    test.slow();
    test.setTimeout(480000);

    const backendRoot = await loginAsAdmin(page);
    const createSessionUrl = normalizeToCurrentOrigin(
      page,
      new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    const createPayload = await page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
    }, { url: createSessionUrl });
    expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(createPayload.workspace_url)));
    await ensurePagebuilderExpertLayout(page);

    const suffix = Date.now().toString().slice(-8);
    const localDomain = buildLocalDomain(`pb-repick-${suffix}`);
    const scopePatch = {
      site_title: `E2E PB Repick ${suffix}`,
      site_tagline: 'modal repick',
      target_domain: localDomain,
      brief_description: 'Repick modal E2E.',
      user_description: 'Repick modal E2E.',
      page_types: ['home_page', 'about_page'],
    };
    await page.fill('#pb-ai-site-title', scopePatch.site_title);
    await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
    await page.fill('#pb-ai-target-domain', localDomain);
    await page.fill('#pb-ai-brief-description', scopePatch.brief_description);
    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(buildStream.eventNames).toContain('done');

    await gotoStable(page, page.url());
    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });

    const picker = page.locator('.pb-ai-open-page-type-picker').first();
    await expect(picker).toBeVisible({ timeout: 15000 });
    await picker.click();
    await expect(page.locator('#pb-ai-confirm-generate-theme')).toBeVisible({ timeout: 15000 });

    const respPromise = page.waitForResponse(
      (r) => r.url().includes('post-start-build') && r.request().method() === 'POST',
      { timeout: 120000 }
    );
    await page.locator('#pb-ai-confirm-generate-theme').click();
    const resp = await respPromise;
    expect(resp.ok(), `post-start-build HTTP ${resp.status()}`).toBeTruthy();
    const repickJson = await resp.json();
    expect(repickJson && repickJson.success, JSON.stringify(repickJson)).toBeTruthy();
    expect(String(repickJson.stream_url || '').trim()).toBeTruthy();
  });

  test('SSE build then SSE publish: builder index lists published home and storefront responds', async ({ page }) => {
    test.slow();
    test.setTimeout(600000);

    const backendRoot = await loginAsAdmin(page);
    const createSessionUrl = normalizeToCurrentOrigin(
      page,
      new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    const createPayload = await page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
    }, { url: createSessionUrl });
    expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
    expect(createPayload.workspace_url).toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(createPayload.workspace_url)));
    await ensurePagebuilderExpertLayout(page);

    const suffix = Date.now().toString().slice(-8);
    const uniqueSiteTitle = `E2E PB Publish ${suffix}`;
    const localDomain = `pb-pub-${suffix}.local.test`;
    const selectedPageTypes = ['home_page', 'about_page', 'contact_page'];
    const scopePatch = {
      site_title: uniqueSiteTitle,
      site_tagline: 'E2E publish pipeline',
      target_domain: localDomain,
      brief_description: 'Minimal boutique site for automated publish verification.',
      user_description: 'Minimal boutique site for automated publish verification.',
      page_types: selectedPageTypes,
    };

    await page.fill('#pb-ai-site-title', uniqueSiteTitle);
    await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
    await page.fill('#pb-ai-target-domain', localDomain);
    await page.fill('#pb-ai-brief-description', scopePatch.brief_description);

    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(buildStream.eventNames).toContain('done');
    expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();

    await gotoStable(page, page.url());

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-draft-website-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => Number((await page.locator('#pb-ai-virtual-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    const publishStart = await startPagebuilderPublish(page);
    const publishStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(publishStream.ok, JSON.stringify(publishStream)).toBeTruthy();
    expect(publishStream.eventNames).toContain('done');
    expect(publishStream.lastDone && publishStream.lastDone.success !== false, JSON.stringify(publishStream)).toBeTruthy();

    await gotoStable(page, page.url());
    const scopeAfter = await readJsonTextarea(page, '#pb-ai-scope-full');
    expect(Number(scopeAfter.virtual_theme_id || 0)).toBeGreaterThan(0);
    const materialized = scopeAfter.pagebuilder_pages_by_type || {};
    expect(Object.keys(materialized).length).toBeGreaterThan(0);

    const indexUrl = new URL(buildBackendUrl('pagebuilder/backend/page/index'));
    indexUrl.searchParams.set('search', uniqueSiteTitle);
    await gotoStable(page, indexUrl.toString());

    const homeRow = page.locator('.pagebuilder-page-item').filter({ hasText: uniqueSiteTitle });
    await expect(homeRow.first()).toBeVisible({ timeout: 60000 });
    await expect(homeRow.first()).toHaveClass(/is-published/);

    const origin = new URL(getRuntimeInfo().runtime.target_origin);
    const storefrontResp = await page.request.get(`${origin.origin}/`, {
      headers: { Host: localDomain },
      timeout: 120000,
      ignoreHTTPSErrors: true,
    });
    expect(storefrontResp.ok(), `storefront HTTP ${storefrontResp.status()}`).toBeTruthy();
    const storefrontHtml = await storefrontResp.text();
    expect(storefrontHtml.length).toBeGreaterThan(200);
    expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
  });

  test('publish gate: site_ready=0 should return friendly domain-not-ready message', async ({ page }) => {
    test.slow();
    test.setTimeout(600000);

    const backendRoot = await loginAsAdmin(page);
    const createSessionUrl = normalizeToCurrentOrigin(
      page,
      new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
    );
    const createPayload = await page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`pagebuilder create-session: HTTP ${res.status} non-JSON body=${text.slice(0, 400)}`);
      }
    }, { url: createSessionUrl });
    expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
    expect(createPayload.workspace_url).toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(createPayload.workspace_url)));
    await ensurePagebuilderExpertLayout(page);

    const suffix = Date.now().toString().slice(-8);
    const scopePatch = {
      site_title: `E2E PB Domain Gate ${suffix}`,
      site_tagline: 'E2E site_ready gate',
      target_domain: `pb-gate-${suffix}.local.test`,
      brief_description: 'Verify start publish rejects while domain is not ready.',
      user_description: 'Verify start publish rejects while domain is not ready.',
      page_types: ['home_page', 'about_page', 'contact_page'],
    };

    await mergePagebuilderScope(page, scopePatch);
    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(buildStream.eventNames).toContain('done');
    expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();

    await gotoStable(page, page.url());
    await mergePagebuilderScope(page, { site_ready: 0 });

    const checklistPayload = await runPagebuilderPublishChecklist(page);
    expect(checklistPayload.data && checklistPayload.data.passed === false, JSON.stringify(checklistPayload)).toBeTruthy();
    const checklistItems = Array.isArray(checklistPayload.data && checklistPayload.data.items)
      ? checklistPayload.data.items
      : [];
    const siteReadyItem = checklistItems.find((item) => item && item.key === 'site_ready');
    expect(siteReadyItem, 'publish checklist should contain site_ready item').toBeTruthy();
    expect(Boolean(siteReadyItem.ok)).toBeFalsy();

    const publishStart = await requestPagebuilderPublish(page);
    expect(Boolean(publishStart && publishStart.success)).toBeFalsy();
    expect(String((publishStart && publishStart.message) || '')).toContain('域名尚未就绪');
  });

  test('smoke long chain: local fake purchase → handoff → per-page SSE build markers → publish → domain storefront', async ({
    page,
  }) => {
    test.slow();
    test.setTimeout(660000);

    const suffix = Date.now().toString().slice(-8);
    const subFqdn = buildWelineLocalSubdomain('pb-e2e');
    const hostsReg = tryRegisterWelineLocalSubdomain(subFqdn);
    const canBrowserVisit = hostsReg.ok === true;
    if (!canBrowserVisit) {
      const reason = hostsReg.skipped
        ? String(hostsReg.message || '')
        : `server:hosts:add failed — run terminal as Administrator or: php bin/w server:hosts:add ${subFqdn}`;
      process.stdout.write(`[e2e] browser storefront URL will use Host fallback (${reason})\n`);
    }

    const backendRoot = await loginAsAdmin(page);
    const brief =
      'Local registrar E2E: boutique with hero home, about brand story, and contact. Subdomain on weline.local.';
    const payload = await createWorkspaceWithRetry(page, backendRoot, brief, {
      provider: 'pagebuilder',
      fakeMode: true,
      retries: 3,
    });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = normalizeToCurrentOrigin(page, String(payload.workspace_url));
    await gotoStable(page, workspaceUrl);

    const uniqueSiteTitle = `E2E Local Subdomain ${suffix}`;
    await expect(page.locator('#site-builder-title')).toBeVisible({ timeout: 15000 });
    await page.fill('#site-builder-title', uniqueSiteTitle);
    await page.fill('#site-builder-tagline', 'Local weline.local subdomain shop');
    await page.fill('#site-builder-domain', subFqdn);
    await page.fill('#site-builder-brief', brief);
    await page.click('#site-builder-save-summary', { force: true });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await expect(page.locator('#site-builder-domain')).toHaveValue(subFqdn, { timeout: 15000 });

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'fake local domain purchase should return order_id').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const handoffHref = await handoffLink.getAttribute('href');
    expect(handoffHref, 'pagebuilder handoff link').toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(handoffHref)));
    await ensurePagebuilderAiWorkspace(page, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);

    const selectedPageTypes = ['home_page', 'about_page', 'contact_page'];
    const scopePatch = {
      site_title: uniqueSiteTitle,
      site_tagline: 'Local weline.local subdomain shop',
      target_domain: subFqdn,
      brief_description: brief,
      user_description: brief,
      page_types: selectedPageTypes,
    };
    await page.fill('#pb-ai-site-title', uniqueSiteTitle);
    await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
    await page.fill('#pb-ai-target-domain', subFqdn);
    await page.fill('#pb-ai-brief-description', brief);
    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
    expect(buildStream.eventNames).toContain('done');
    expectBuildSseCoveredAllPageTypes(buildStream, selectedPageTypes);

    await gotoStable(page, page.url());
    const builtScope = await readJsonTextarea(page, '#pb-ai-scope-full');
    const virtualPagesByType = builtScope.virtual_pages_by_type || {};
    const workspaceTrack = String(builtScope.workspace_track || '');
    for (const pageType of selectedPageTypes) {
      const row = virtualPagesByType[pageType] || {};
      const hasGeneratedMarker = Boolean(String(row.last_generated_at || '').trim());
      expect(hasGeneratedMarker, `virtual page should include last_generated_at marker: ${pageType}`).toBeTruthy();
      if (workspaceTrack === 'html_blocks') {
        const blocks = Array.isArray(row.blocks) ? row.blocks : [];
        expect(blocks.length, `html_blocks track should generate blocks for: ${pageType}`).toBeGreaterThan(0);
      }
    }
    if (workspaceTrack !== 'html_blocks') {
      expect(Number(builtScope.virtual_theme_id || 0), 'virtual theme track should produce virtual_theme_id').toBeGreaterThan(0);
    }
    await expect
      .poll(async () => Number((await page.locator('#pb-ai-draft-website-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);
    await expect
      .poll(async () => Number((await page.locator('#pb-ai-virtual-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    const publishStart = await startPagebuilderPublish(page);
    const publishStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expect(publishStream.ok, JSON.stringify(publishStream)).toBeTruthy();
    expect(publishStream.eventNames).toContain('done');
    expect(publishStream.lastDone && publishStream.lastDone.success !== false, JSON.stringify(publishStream)).toBeTruthy();
    const publishDonePayload = publishStream.lastDone && typeof publishStream.lastDone === 'object'
      ? publishStream.lastDone
      : {};
    const publishedData = publishDonePayload.data && typeof publishDonePayload.data === 'object'
      ? publishDonePayload.data
      : {};
    const publishedPagesByType = publishedData.published && typeof publishedData.published === 'object'
      ? (publishedData.published.pagebuilder_pages_by_type || {})
      : {};
    for (const pageType of selectedPageTypes) {
      expect(
        Number(((publishedPagesByType[pageType] || {}).page_id) || 0),
        `publish done payload should include materialized page id for: ${pageType}`
      ).toBeGreaterThan(0);
    }

    const indexUrl = new URL(buildBackendUrl('pagebuilder/backend/page/index'));
    indexUrl.searchParams.set('search', uniqueSiteTitle);
    await gotoStable(page, indexUrl.toString());
    const homeRow = page.locator('.pagebuilder-page-item').filter({ hasText: uniqueSiteTitle });
    await expect(homeRow.first()).toBeVisible({ timeout: 60000 });
    await expect(homeRow.first()).toHaveClass(/is-published/);

    const origin = new URL(getRuntimeInfo().runtime.target_origin);
    const portSeg = origin.port ? `:${origin.port}` : '';
    const storefrontUrl = `${origin.protocol}//${subFqdn}${portSeg}/`;

    if (canBrowserVisit) {
      await page.goto(storefrontUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await expect(page.locator('body')).toBeVisible();
      const html = await page.content();
      expect(html.length).toBeGreaterThan(200);
      expect(html.toLowerCase()).not.toContain('404 not found');
    } else {
      const storefrontResp = await page.request.get(`${origin.origin}/`, {
        headers: { Host: subFqdn },
        timeout: 120000,
        ignoreHTTPSErrors: true,
      });
      expect(storefrontResp.ok(), `storefront HTTP ${storefrontResp.status()}`).toBeTruthy();
      const storefrontHtml = await storefrontResp.text();
      expect(storefrontHtml.length).toBeGreaterThan(200);
      expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
      process.stdout.write(`[e2e] open in browser after hosts: ${storefrontUrl}\n`);
    }

    // 验证前台默认首页来自本次建站（标题命中），证明默认落地为本次生成主题页面。
    const storefrontCheck = await page.request.get(`${origin.origin}/`, {
      headers: { Host: subFqdn },
      timeout: 120000,
      ignoreHTTPSErrors: true,
    });
    expect(storefrontCheck.ok(), `storefront check HTTP ${storefrontCheck.status()}`).toBeTruthy();
    const storefrontHtml = await storefrontCheck.text();
    expect(storefrontHtml).toContain(uniqueSiteTitle);
  });
});

moduleDescribe(test, 'GuoLaiRen_PageBuilder', 'AI site workbench regressions', () => {
  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-LEGACY-001' },
    'legacy single-content session auto-hydrates blocks and opens refine modal',
    async ({ page }) => {
      test.slow();
      test.setTimeout(480000);

      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-legacy-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Legacy ${suffix}`,
        site_tagline: 'legacy compatibility',
        target_domain: localDomain,
        brief_description: 'Legacy PageBuilder AI session compatibility check.',
        user_description: 'Legacy PageBuilder AI session compatibility check.',
        page_types: ['home_page'],
      };

      await page.fill('#pb-ai-site-title', scopePatch.site_title);
      await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
      await page.fill('#pb-ai-target-domain', localDomain);
      await page.fill('#pb-ai-brief-description', scopePatch.brief_description);
      await mergePagebuilderScope(page, scopePatch);

      const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
      const buildStream = await consumeSseStream(
        page,
        normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
        { timeoutMs: WORKSPACE_TIMEOUT }
      );
      expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();
      expect(buildStream.eventNames).toContain('done');
      expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();

      const legacyPatch = {
        preview_page_type: 'home_page',
        page_type_layouts: {
          home_page: {
            version: '1.0',
            page_id: 0,
            use_original_template: false,
            header: { component: 'header/ai-site-header', config: [] },
            content: [
              {
                code: 'content/home-page',
                enabled: true,
                config: [],
                instance_id: '',
                sort_order: 10,
                style_code: '',
              },
            ],
            footer: { component: 'footer/ai-site-footer', config: [] },
          },
        },
        virtual_pages_by_type: {
          home_page: {
            page_type: 'home_page',
            title: '首页',
            handle: '',
            locale: 'en_US',
            style_code: 'default',
            style_settings: [],
            ai_description: 'Legacy compatibility smoke check',
            blocks: [],
            section_refinements: [],
          },
        },
      };
      await mergePagebuilderScope(page, legacyPatch);
      await gotoStable(page, workspaceUrl);

      const frame = page.frameLocator('#pb-ai-visual-preview-frame');
      await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });
      await expect
        .poll(async () => await frame.locator('.pb-ai-block-wrapper').count(), {
          timeout: 30000,
        })
        .toBeGreaterThan(1);

      const targetBlock = frame.locator('.pb-ai-block-wrapper').nth(1);
      await targetBlock.hover();
      await expect(targetBlock.locator('.component-actions')).toBeVisible({ timeout: 10000 });
      await targetBlock.locator('.component-action-refine').click({ force: true });

      const refineModal = page.locator('#pb-ai-refine-component-modal');
      await expect(refineModal).toHaveClass(/show/, { timeout: 10000 });
      await expect(page.locator('.modal-backdrop')).toHaveCount(1, { timeout: 10000 });
      await expect(page.locator('#pb-ai-refine-component-context')).not.toHaveText(/当前还没有选中的区块。/, {
        timeout: 10000,
      });
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-EDITOR-002' },
    'block field editor updates content and header/footer without iframe reload',
    async ({ page }) => {
      test.slow();
      test.setTimeout(480000);

      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-editor-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Editor ${suffix}`,
        site_tagline: 'block field editor',
        target_domain: localDomain,
        brief_description: 'Block field editing E2E.',
        user_description: 'Block field editing E2E.',
        page_types: ['home_page'],
      };

      await page.fill('#pb-ai-site-title', scopePatch.site_title);
      await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline);
      await page.fill('#pb-ai-target-domain', localDomain);
      await page.fill('#pb-ai-brief-description', scopePatch.brief_description);
      await mergePagebuilderScope(page, scopePatch);

      const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
      const buildStream = await consumeSseStream(
        page,
        normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
        { timeoutMs: WORKSPACE_TIMEOUT }
      );
      expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();

      await gotoStable(page, workspaceUrl);
      const previewFrame = page.locator('#pb-ai-visual-preview-frame');
      const frame = page.frameLocator('#pb-ai-visual-preview-frame');
      await expect(previewFrame).toBeVisible({ timeout: 60000 });
      await expect(frame.locator('.pb-ai-block-wrapper[data-region="header"]').first()).toBeVisible({ timeout: 30000 });
      await expect(frame.locator('.pb-ai-block-wrapper[data-region="footer"]').first()).toBeVisible({ timeout: 30000 });

      const srcBefore = await previewFrame.getAttribute('src');

      const contentBlock = frame.locator('.pb-ai-block-wrapper[data-block-type="cards"]').first();
      await contentBlock.hover();
      await contentBlock.locator('.component-action-editor').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).toHaveClass(/show/, { timeout: 10000 });
      await page.locator('#pb-ai-edit-block-fields [data-field-key="section_title"]').fill('E2E Block Updated');
      await page.locator('#pb-ai-edit-block-submit').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).not.toHaveClass(/show/, { timeout: 10000 });
      await expect(contentBlock).toContainText('E2E Block Updated', { timeout: 15000 });

      const headerBlock = frame.locator('.pb-ai-block-wrapper[data-block-type="site_header"]').first();
      await headerBlock.hover();
      await headerBlock.locator('.component-action-editor').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).toHaveClass(/show/, { timeout: 10000 });
      await page.locator('#pb-ai-edit-block-fields [data-field-key="site_title"]').fill('Header E2E Title');
      await page.locator('#pb-ai-edit-block-submit').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).not.toHaveClass(/show/, { timeout: 10000 });
      await expect(headerBlock).toContainText('Header E2E Title', { timeout: 15000 });

      const footerBlock = frame.locator('.pb-ai-block-wrapper[data-block-type="site_footer"]').first();
      await footerBlock.hover();
      await footerBlock.locator('.component-action-editor').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).toHaveClass(/show/, { timeout: 10000 });
      await page.locator('#pb-ai-edit-block-fields [data-field-key="site_title"]').fill('Footer E2E Title');
      await page.locator('#pb-ai-edit-block-submit').click({ force: true });
      await expect(page.locator('#pb-ai-edit-block-modal')).not.toHaveClass(/show/, { timeout: 10000 });
      await expect(footerBlock).toContainText('Footer E2E Title', { timeout: 15000 });

      const srcAfter = await previewFrame.getAttribute('src');
      expect(srcAfter).toBe(srcBefore);
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-LANG-003' },
    'primary language selection persists in scope and hydrates after reload',
    async ({ page }) => {
      test.slow();
      test.setTimeout(240000);

      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);
      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);

      const localeSelect = page.locator('#pb-ai-default-locale-summary');
      await expect(localeSelect).toBeVisible({ timeout: 30000 });
      await localeSelect.selectOption('ja_JP');
      await page.waitForTimeout(900); // auto-save debounce

      const savedScope = await readJsonTextarea(page, '#pb-ai-scope-full');
      expect(String(savedScope.default_locale || '')).toBe('ja_JP');
      expect(
        Boolean(savedScope.site_profile_manual && savedScope.site_profile_manual.default_locale),
        'site_profile_manual.default_locale should be marked after manual selection'
      ).toBeTruthy();

      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);
      await expect(page.locator('#pb-ai-default-locale-summary')).toHaveValue('ja_JP', { timeout: 15000 });
    }
  );
});
