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
  mergeWebsitesScope,
  resolveSiteBuilderBackendRoot,
  triggerFakeDomainPurchase,
} = require('./helpers/ai-workbench');

const WORKSPACE_TIMEOUT = 300000;
const LONG_WORKSPACE_TIMEOUT = 2400000;
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

async function openWebsitesSummaryDetails(page) {
  const details = page.locator('#site-builder-summary-details');
  const exists = await details.count().catch(() => 0);
  if (!exists) {
    return;
  }
  const isOpen = await details.evaluate(node => Boolean(node.open)).catch(() => false);
  if (!isOpen) {
    await details.evaluate(node => { node.open = true; });
  }
  await expect(page.locator('#site-builder-title')).toBeVisible({ timeout: 15000 });
}

function parseSseResponseText(raw) {
  const text = String(raw || '');
  const chunks = text.split(/\r?\n\r?\n/);
  const events = [];
  chunks.forEach((chunk) => {
    const lines = chunk.split(/\r?\n/);
    let eventName = 'message';
    const dataLines = [];
    lines.forEach((line) => {
      if (line.startsWith('event:')) {
        eventName = line.slice(6).trim() || 'message';
        return;
      }
      if (line.startsWith('data:')) {
        dataLines.push(line.slice(5).trim());
      }
    });
    if (!dataLines.length) {
      return;
    }
    const dataRaw = dataLines.join('\n');
    let data = dataRaw;
    try {
      data = JSON.parse(dataRaw);
    } catch (error) {
      // keep raw text data when payload is not JSON
    }
    events.push({ event: eventName, data });
  });
  let lastDone = null;
  for (let i = events.length - 1; i >= 0; i -= 1) {
    if (events[i].event === 'done') {
      lastDone = events[i].data;
      break;
    }
  }
  return { events, lastDone };
}

async function confirmPagebuilderGenerateTheme(page) {
  const confirmBtn = page.locator('#pb-ai-confirm-generate-theme');
  const modal = page.locator('#pb-ai-page-type-confirm-modal');
  const modalVisible = await modal.isVisible({ timeout: 10000 }).catch(() => false);
  if (!modalVisible) {
    return null;
  }
  await expect(confirmBtn).toBeVisible({ timeout: 15000 });
  const responsePromise = page.waitForResponse(
    (response) => response.url().includes('post-start-build') && response.request().method() === 'POST',
    { timeout: 120000 }
  );
  await confirmBtn.click({ force: true });
  const response = await responsePromise;
  expect(response.ok(), `post-start-build HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload && payload.success, JSON.stringify(payload)).toBeTruthy();
  expect(String(payload.stream_url || '').trim()).toBeTruthy();
  return payload;
}

async function fillFirstVisible(page, selectors, value) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    const visible = await locator.isVisible({ timeout: 2000 }).catch(() => false);
    if (!visible) {
      continue;
    }
    await locator.fill(value);
    return selector;
  }
  throw new Error(`No visible input found for selectors: ${selectors.join(', ')}`);
}

async function fetchPagebuilderStateData(page, stateUrl) {
  try {
    const res = await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok()) {
      return null;
    }
    const json = await res.json();
    return json && json.data ? json.data : null;
  } catch (error) {
    return page.evaluate(async ({ url }) => {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) {
        return null;
      }
      const json = await res.json();
      return json && json.data ? json.data : null;
    }, { url: stateUrl }).catch(() => null);
  }
}

async function expectSelectedDomainVisible(page, expectedDomain) {
  const legacyInput = page.locator('#site-builder-domain');
  if (await legacyInput.count().catch(() => 0)) {
    await expect(legacyInput).toHaveValue(expectedDomain, { timeout: 15000 });
    return;
  }

  const scopeRaw = await page.locator('#site-builder-scope-full').inputValue().catch(() => '');
  if (scopeRaw) {
    try {
      const scope = JSON.parse(scopeRaw);
      const actual = String(scope.target_domain || scope.selected_domain || '').trim();
      expect(actual).toBe(expectedDomain);
      return;
    } catch (error) {
      // fall through to visible text assertion
    }
  }

  await expect(page.locator(`text=${expectedDomain}`)).toBeVisible({ timeout: 15000 });
}

async function waitForPagebuilderStateData(page, stateUrl, predicate, timeoutMs = WORKSPACE_TIMEOUT) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const data = await fetchPagebuilderStateData(page, stateUrl);
    if (data && predicate(data)) {
      return data;
    }
    await page.waitForTimeout(1000);
  }
  throw new Error(`waitForPagebuilderStateData timed out: ${stateUrl}`);
}

function buildLocalDomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.local.test`;
}

/** 与 `php bin/w server:hosts:add` 一致；工作区根 = tests/e2e/specs/backend → 上四级 */
function devWorkspaceRootFromThisSpec() {
  return path.resolve(__dirname, '../../../..');
}

function createPagebuilderSessionViaPhp(initialScope = {}) {
  const root = devWorkspaceRootFromThisSpec();
  const phpScope = JSON.stringify(initialScope || {})
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpCode = `
require 'app/bootstrap.php';
$service = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteAgentSessionService::class);
$scope = json_decode('${phpScope}', true);
$session = $service->createSession(1, is_array($scope) ? $scope : []);
echo json_encode([
  'success' => true,
  'public_id' => $session->getPublicId(),
], JSON_UNESCAPED_UNICODE);
`;
  const stdout = execFileSync('php', ['-r', phpCode], {
    cwd: root,
    stdio: 'pipe',
    encoding: 'utf8',
  });
  return JSON.parse(stdout);
}

function preparePagebuilderPlanDraftViaPhp(publicId) {
  const root = devWorkspaceRootFromThisSpec();
  const phpPublicId = JSON.stringify(String(publicId || ''))
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpCode = `
require 'app/bootstrap.php';
$sessionService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteAgentSessionService::class);
$scopeCompatibilityService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteScopeCompatibilityService::class);
$profileService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteProfileGenerationService::class);
$executionService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteExecutionBlueprintService::class);
$publicId = json_decode('${phpPublicId}', true);
$session = $sessionService->loadByPublicId(is_string($publicId) ? $publicId : '', 1);
if (!$session) {
    throw new RuntimeException('PageBuilder session not found for plan draft seed.');
}
$scope = $scopeCompatibilityService->normalizeScope($session->getScopeArray());
$websiteProfile = $profileService->generate($scope, false);
$artifacts = $executionService->buildPlanArtifacts($scope, is_array($websiteProfile) ? $websiteProfile : []);
$pageTypes = is_array($scope['page_types'] ?? null) ? array_values(array_map('strval', $scope['page_types'])) : [];
$planLocale = (string)($scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? '');
$scopePatch = array_replace(
    is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [],
    [
        'website_profile' => is_array($websiteProfile) ? $websiteProfile : [],
        'execution_blueprint_draft' => is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [],
        'plan_json' => is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
        'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
        'plan_structured' => is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
        'plan_locale' => $planLocale,
        'plan_ai_generated' => 0,
        'plan_ai_fallback' => 1,
        'plan_generated_at' => date('Y-m-d H:i:s'),
        'plan_generated_locale' => $planLocale,
        'plan_generated_page_types' => $pageTypes,
        'plan_confirmed' => 0,
    ]
);
$sessionService->mergeScope($session->getId(), 1, $scopePatch);
$fresh = $sessionService->loadById($session->getId(), 1) ?? $session;
$freshScope = $scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
echo json_encode([
    'success' => true,
    'public_id' => $fresh->getPublicId(),
    'plan_markdown' => (string)($scopePatch['plan_markdown'] ?? ''),
    'execution_blueprint_signature' => (string)($scopePatch['execution_blueprint_draft']['signature'] ?? ''),
    'has_build_tasks' => is_array($freshScope['build_tasks'] ?? null) && $freshScope['build_tasks'] !== [],
], JSON_UNESCAPED_UNICODE);
`;
  const stdout = execFileSync('php', ['-r', phpCode], {
    cwd: root,
    stdio: 'pipe',
    encoding: 'utf8',
  });
  return JSON.parse(stdout);
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

function fetchStorefrontHtmlViaCurl(url) {
  const curlCmd = process.platform === 'win32' ? 'curl.exe' : 'curl';
  return execFileSync(curlCmd, ['-k', '-s', url], {
    cwd: devWorkspaceRootFromThisSpec(),
    stdio: 'pipe',
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
  });
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
  const postUrl = buildPagebuilderWorkspacePostUrl(page, 'post-start-build');
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  await ensurePagebuilderPlanAndTaskPlanConfirmed(page, scopePatch);

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

  const resumable = payload
    && payload.success === false
    && String(payload.operation || '') === 'build'
    && String(payload.execution_token || '').trim() !== ''
    && String(payload.stream_url || '').trim() !== '';
  expect(payload && (payload.success || resumable), JSON.stringify(payload)).toBeTruthy();
  expect(payload.stream_url).toBeTruthy();
  return resumable ? { ...payload, success: true, resumed_existing: true } : payload;
}

function buildPagebuilderWorkspacePostUrl(page, action) {
  const current = new URL(page.url());
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, `/${action}`);
  return normalizeToCurrentOrigin(page, current.toString());
}

function buildPagebuilderWorkspacePostUrlFromWorkspaceUrl(workspaceUrl, action) {
  const current = new URL(workspaceUrl);
  current.search = '';
  current.hash = '';
  current.pathname = current.pathname.replace(/\/workspace$/i, `/${action}`);
  return `${current.pathname}${current.search}${current.hash}`;
}

async function ensureWorkspaceSameOriginPage(page, workspaceUrl) {
  try {
    const current = new URL(page.url());
    if (/^https?:$/i.test(current.protocol)) {
      return;
    }
  } catch (error) {
    // fall through to navigation
  }
  const candidateUrls = [
    buildBackendUrl('admin'),
    buildBackendUrl('admin/login'),
    workspaceUrl,
  ].filter((value, index, list) => value && list.indexOf(value) === index);
  for (const candidateUrl of candidateUrls) {
    try {
      await gotoStable(page, candidateUrl);
      return;
    } catch (error) {
      // try the next stable browser origin
    }
  }
  throw new Error(`Unable to establish browser http(s) page context for ${workspaceUrl}`);
}

async function postPagebuilderWorkspaceJson(page, action, form) {
  const postUrl = buildPagebuilderWorkspacePostUrl(page, action);
  const res = await page.request.post(postUrl, {
    form,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const text = await res.text();
  let payload;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`pagebuilder ${action}: HTTP ${res.status()} non-JSON body=${text.slice(0, 400)}`);
  }
  return { response: res, payload };
}

async function postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, action, form) {
  await ensureWorkspaceSameOriginPage(page, workspaceUrl);
  const postUrl = buildPagebuilderWorkspacePostUrlFromWorkspaceUrl(workspaceUrl, action);
  const result = await page.evaluate(async ({ url, payload }) => {
    const formData = new FormData();
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value === undefined || value === null) {
        return;
      }
      formData.append(key, String(value));
    });
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData,
    });
    const text = await response.text();
    try {
      return {
        ok: response.ok,
        status: response.status,
        payload: JSON.parse(text),
        rawHead: text.slice(0, 400),
      };
    } catch (error) {
      return {
        ok: response.ok,
        status: response.status,
        payload: null,
        rawHead: text.slice(0, 400),
      };
    }
  }, { url: postUrl, payload: form });

  if (!result || result.payload === null) {
    throw new Error(`pagebuilder ${action}: HTTP ${(result && result.status) || 0} non-JSON body=${(result && result.rawHead) || ''}`);
  }
  return {
    response: {
      ok: () => Boolean(result.ok),
      status: () => Number(result.status || 0),
    },
    payload: result.payload,
  };
}

async function postPagebuilderWorkspaceSse(page, action, form, timeoutMs = WORKSPACE_TIMEOUT) {
  const postUrl = buildPagebuilderWorkspacePostUrl(page, action);
  const res = await page.request.post(postUrl, {
    form,
    timeout: timeoutMs,
    headers: {
      Accept: 'text/event-stream',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });
  const raw = await res.text();
  const { events, lastDone } = parseSseResponseText(raw);
  return {
    response: res,
    events,
    eventNames: events.map((event) => event.event),
    lastDone,
    rawHead: raw.slice(0, 400),
  };
}

async function postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, action, form, timeoutMs = WORKSPACE_TIMEOUT) {
  await ensureWorkspaceSameOriginPage(page, workspaceUrl);
  const postUrl = buildPagebuilderWorkspacePostUrlFromWorkspaceUrl(workspaceUrl, action);
  const result = await page.evaluate(async ({ url, payload }) => {
    const formData = new FormData();
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value === undefined || value === null) {
        return;
      }
      formData.append(key, String(value));
    });
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'text/event-stream',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: formData,
    });
    const text = await response.text();
    return {
      ok: response.ok,
      status: response.status,
      text,
    };
  }, { url: postUrl, payload: form });
  const raw = String((result && result.text) || '');
  const { events, lastDone } = parseSseResponseText(raw);
  return {
    response: {
      ok: () => Boolean(result && result.ok),
      status: () => Number((result && result.status) || 0),
    },
    events,
    eventNames: events.map((event) => event.event),
    lastDone,
    rawHead: raw.slice(0, 400),
  };
}

async function waitForWorkspaceFieldMutation(page, workspaceUrl, selector, predicate, timeoutMs = WORKSPACE_TIMEOUT) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    await ensureWorkspaceSameOriginPage(page, workspaceUrl);
    const value = await page.locator(selector).inputValue().catch(() => '');
    if (predicate(String(value || ''))) {
      return String(value || '');
    }
    await page.waitForTimeout(1500);
  }
  throw new Error(`waitForWorkspaceFieldMutation timed out for ${selector}`);
}

async function ensurePagebuilderPlanAndTaskPlanConfirmed(page, scopePatch) {
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const phase1StartSse = await postPagebuilderWorkspaceSse(page, 'post-plan-sse', {
    public_id: publicId,
    prompt_mode: 'rebuild',
    instruction: String((scopePatch && (scopePatch.user_description || scopePatch.brief_description)) || '').trim(),
    round: '1',
  }, WORKSPACE_TIMEOUT);
  expect(phase1StartSse.response.ok(), phase1StartSse.rawHead).toBeTruthy();
  expect((phase1StartSse.eventNames || []).length, JSON.stringify(phase1StartSse)).toBeGreaterThan(0);
  let phase1Confirm = null;
  const confirmStartedAt = Date.now();
  while ((Date.now() - confirmStartedAt) < WORKSPACE_TIMEOUT) {
    phase1Confirm = await postPagebuilderWorkspaceJson(page, 'post-confirm-plan', {
      public_id: publicId,
    });
    if (phase1Confirm.payload && phase1Confirm.payload.success) {
      break;
    }
    if (String((phase1Confirm.payload && phase1Confirm.payload.code) || '') !== 'PLAN_NOT_READY') {
      break;
    }
    await page.waitForTimeout(2000);
  }
  expect(phase1Confirm && phase1Confirm.payload && phase1Confirm.payload.success, JSON.stringify(phase1Confirm && phase1Confirm.payload)).toBeTruthy();

  const phase2Start = await postPagebuilderWorkspaceJson(page, 'post-start-task-plan', {
    public_id: publicId,
    scope_patch: JSON.stringify(scopePatch || {}),
  });
  expect(phase2Start.payload && phase2Start.payload.success, JSON.stringify(phase2Start.payload)).toBeTruthy();

  const phase2Confirm = await postPagebuilderWorkspaceJson(page, 'post-confirm-task-plan', {
    public_id: publicId,
  });
  expect(phase2Confirm.payload && phase2Confirm.payload.success, JSON.stringify(phase2Confirm.payload)).toBeTruthy();

  return {
    phase1Start: phase1StartSse.lastDone || {},
    phase1Confirm: phase1Confirm.payload,
    phase2Start: phase2Start.payload,
    phase2Confirm: phase2Confirm.payload,
  };
}

async function mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  const res = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-merge-scope', {
    public_id: publicId,
    scope_patch: JSON.stringify(scopePatch),
  });
  expect(res.payload && res.payload.success, JSON.stringify(res.payload)).toBeTruthy();
  return res.payload;
}

async function ensurePagebuilderPlanAndTaskPlanConfirmedByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();

  const phase1StartSse = await postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, 'post-plan-sse', {
    public_id: publicId,
    prompt_mode: 'rebuild',
    instruction: String((scopePatch && (scopePatch.user_description || scopePatch.brief_description)) || '').trim(),
    round: '1',
  }, WORKSPACE_TIMEOUT);
  expect(phase1StartSse.response.ok(), phase1StartSse.rawHead).toBeTruthy();
  expect((phase1StartSse.eventNames || []).length, JSON.stringify(phase1StartSse)).toBeGreaterThan(0);
  let phase1Confirm = null;
  const confirmStartedAt = Date.now();
  while ((Date.now() - confirmStartedAt) < WORKSPACE_TIMEOUT) {
    phase1Confirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
      public_id: publicId,
    });
    if (phase1Confirm.payload && phase1Confirm.payload.success) {
      break;
    }
    if (String((phase1Confirm.payload && phase1Confirm.payload.code) || '') !== 'PLAN_NOT_READY') {
      break;
    }
    await page.waitForTimeout(2000);
  }
  expect(phase1Confirm && phase1Confirm.payload && phase1Confirm.payload.success, JSON.stringify(phase1Confirm && phase1Confirm.payload)).toBeTruthy();

  const phase2Start = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-task-plan', {
    public_id: publicId,
    scope_patch: JSON.stringify(scopePatch || {}),
  });
  expect(phase2Start.payload && phase2Start.payload.success, JSON.stringify(phase2Start.payload)).toBeTruthy();

  const phase2Confirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-task-plan', {
    public_id: publicId,
  });
  expect(phase2Confirm.payload && phase2Confirm.payload.success, JSON.stringify(phase2Confirm.payload)).toBeTruthy();

  return {
    phase1Start: phase1StartSse.lastDone || {},
    phase1Confirm: phase1Confirm.payload,
    phase2Start: phase2Start.payload,
    phase2Confirm: phase2Confirm.payload,
  };
}

async function startPagebuilderBuildByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  const res = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-build', {
    public_id: publicId,
    scope_patch: JSON.stringify(scopePatch || {}),
  });
  const payload = res.payload;
  const resumable = payload
    && payload.success === false
    && String(payload.operation || '') === 'build'
    && String(payload.execution_token || '').trim() !== ''
    && String(payload.stream_url || '').trim() !== '';
  expect(payload && (payload.success || resumable), JSON.stringify(payload)).toBeTruthy();
  expect(String(payload.stream_url || '').trim()).toBeTruthy();
  return resumable ? { ...payload, success: true, resumed_existing: true } : payload;
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
  const donePayload = buildStream && buildStream.lastDone && typeof buildStream.lastDone === 'object'
    ? buildStream.lastDone
    : null;
  if (donePayload && donePayload.duplicate_stream === true) {
    expect(donePayload.success !== false, JSON.stringify(donePayload)).toBeTruthy();
    return;
  }

  const generatedTypes = collectPageGeneratedTypes(buildStream);
  expect(generatedTypes.size, `expected at least ${selectedPageTypes.length} page_generated events`).toBeGreaterThanOrEqual(
    selectedPageTypes.length
  );
  for (const pageType of selectedPageTypes) {
    expect(generatedTypes.has(pageType), `missing SSE page_generated marker for page type: ${pageType}`).toBeTruthy();
  }
  expect(buildStream.lastDone && buildStream.lastDone.success !== false, JSON.stringify(buildStream)).toBeTruthy();
}

function expectFinishedOrResumedStream(stream, label = 'stream') {
  expect(stream && stream.ok, JSON.stringify(stream)).toBeTruthy();
  expect(stream.eventNames || [], JSON.stringify(stream)).toContain('done');
  const donePayload = stream && stream.lastDone && typeof stream.lastDone === 'object'
    ? stream.lastDone
    : null;
  if (!donePayload) {
    return;
  }
  expect(
    donePayload.success !== false || donePayload.duplicate_stream === true,
    `${label} done payload should be successful or duplicate-stream resumable: ${JSON.stringify(donePayload)}`
  ).toBeTruthy();
}

async function observeInitialSseEvents(page, absoluteUrl, expectedEventNames, timeoutMs = 120000) {
  return page.evaluate(async ({ url, expected, timeout }) => {
    const targetEvents = Array.isArray(expected) ? expected.map((item) => String(item || '').trim()).filter(Boolean) : [];
    return await new Promise((resolve) => {
      const seen = [];
      const payloads = {};
      let settled = false;
      const finish = (result) => {
        if (settled) {
          return;
        }
        settled = true;
        try {
          if (source) {
            source.close();
          }
        } catch (error) {
          // ignore
        }
        clearTimeout(timer);
        resolve(result);
      };
      const source = new EventSource(url, { withCredentials: true });
      const timer = setTimeout(() => {
        finish({ ok: false, seen, payloads, reason: 'timeout' });
      }, timeout);
      const checkDone = () => {
        const ok = targetEvents.every((name) => seen.includes(name));
        if (ok) {
          finish({ ok: true, seen, payloads });
        }
      };
      const register = (name) => {
        source.addEventListener(name, (event) => {
          if (!seen.includes(name)) {
            seen.push(name);
          }
          if (typeof event.data === 'string' && event.data !== '') {
            try {
              payloads[name] = JSON.parse(event.data);
            } catch (error) {
              payloads[name] = event.data;
            }
          }
          checkDone();
        });
      };
      targetEvents.forEach(register);
      source.addEventListener('error', () => {
        finish({ ok: false, seen, payloads, reason: 'error' });
      });
    });
  }, { url: absoluteUrl, expected: expectedEventNames, timeout: timeoutMs });
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
  let runtimeOrigin = null;
  try {
    runtimeOrigin = new URL(String(getRuntimeInfo().runtime?.target_origin || ''));
  } catch (error) {
    runtimeOrigin = null;
  }
  const baseHost = String(base.hostname || '').toLowerCase();
  const targetHost = String(target.hostname || '').toLowerCase();
  const runtimeHost = runtimeOrigin ? String(runtimeOrigin.hostname || '').toLowerCase() : '';
  const baseLooksLocal = /^(127\.0\.0\.1|localhost)$/i.test(baseHost);
  const targetLooksWeline = /\.weline\.local$/i.test(targetHost) || (runtimeHost !== '' && targetHost === runtimeHost);
  if (targetLooksWeline && baseLooksLocal) {
    return target.toString();
  }
  if (runtimeOrigin && target.origin === runtimeOrigin.origin) {
    return target.toString();
  }
  if (target.origin === base.origin) {
    return target.toString();
  }
  return new URL(`${target.pathname}${target.search}${target.hash}`, base.toString()).toString();
}

function buildDirectRuntimeBackendUrl(route) {
  const runtime = getRuntimeInfo();
  const targetOrigin = String(runtime.runtime?.target_origin || '').replace(/\/+$/, '');
  const backendPrefix = String(runtime.paths?.backend_prefix_path || '').replace(/\/+$/, '');
  const normalizedRoute = String(route || '').replace(/^\/+/, '');
  if (!targetOrigin || !backendPrefix || !normalizedRoute) {
    throw new Error(`buildDirectRuntimeBackendUrl missing runtime pieces: origin=${targetOrigin} prefix=${backendPrefix} route=${normalizedRoute}`);
  }
  return `${targetOrigin}${backendPrefix}/${normalizedRoute}`;
}

function buildSameOriginBackendUrl(page, route) {
  const runtime = getRuntimeInfo();
  const backendPrefix = String(runtime.paths?.backend_prefix_path || '').replace(/\/+$/, '');
  const normalizedRoute = String(route || '').replace(/^\/+/, '');
  const base = new URL(page.url());
  return new URL(`${backendPrefix}/${normalizedRoute}`, `${base.origin}/`).toString();
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
    const href = await advancedLink.getAttribute('href');
    const targetUrl = href ? normalizeToCurrentOrigin(page, String(href)) : '';
    await advancedLink.click().catch(async () => {
      if (targetUrl) {
        await gotoStable(page, targetUrl);
      }
    });
    await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
    const landedOnLogin = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
      .isVisible({ timeout: 3000 })
      .catch(() => false);
    if (landedOnLogin) {
      await loginAsAdmin(page, { refreshRuntime: true });
      if (targetUrl) {
        await gotoStable(page, targetUrl);
      }
    }
    if (!/[?&]expert=1\b/i.test(page.url()) && targetUrl) {
      await gotoStable(page, targetUrl);
    } else {
      await gotoStable(page, page.url());
    }
    return;
  }

  const u = new URL(page.url());
  u.searchParams.set('expert', '1');
  await gotoStable(page, u.toString());
  const landedOnLogin = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
    .isVisible({ timeout: 3000 })
    .catch(() => false);
  if (landedOnLogin) {
    await loginAsAdmin(page, { refreshRuntime: true });
    await gotoStable(page, u.toString());
  }

  await expect(page.locator('#pb-ai-draft-website-id')).toBeVisible({ timeout: 30000 });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 */
async function createDirectPagebuilderWorkspace(page, backendRoot) {
  const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
  expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
  expect(String(createPayload.public_id || '').trim()).toBeTruthy();
  const workspaceRoute = `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(createPayload.public_id))}&expert=1`;
  let sameOriginWorkspaceUrl = '';
  try {
    const currentUrl = new URL(page.url());
    if (/^https?:$/i.test(currentUrl.protocol)) {
      sameOriginWorkspaceUrl = buildSameOriginBackendUrl(page, workspaceRoute);
    }
  } catch (error) {
    sameOriginWorkspaceUrl = '';
  }
  const candidateUrls = [
    buildDirectRuntimeBackendUrl(workspaceRoute),
    sameOriginWorkspaceUrl,
  ].filter((value, index, list) => value && list.indexOf(value) === index);

  let workspaceUrl = '';
  let lastError = null;
  for (const candidateUrl of candidateUrls) {
    try {
      await gotoStable(page, candidateUrl);
      workspaceUrl = candidateUrl;
      break;
    } catch (error) {
      lastError = error;
    }
  }

  if (!workspaceUrl) {
    throw lastError || new Error(`Unable to open PageBuilder workspace: ${candidateUrls.join(', ')}`);
  }

  await ensurePagebuilderExpertLayout(page);
  return { createPayload: { ...createPayload, workspace_url: workspaceUrl }, workspaceUrl };
}

/**
 * 复用 {@link createDirectPagebuilderWorkspace} 的登录与会话创建，再退回引导式 UI（去掉 expert=1），
 * 避免在未携带后台 Cookie 的 fetch 上重复 post-create-session。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 */
async function openPagebuilderWorkspaceGuidedAfterExpert(page, backendRoot) {
  const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);
  const guidedUrl = new URL(workspaceUrl);
  guidedUrl.searchParams.delete('expert');
  await gotoStable(page, guidedUrl.toString());
  return { workspaceUrl: guidedUrl.toString() };
}

/**
 * @param {string} workspaceUrl
 */
function buildPagebuilderGetStateJsonUrl(workspaceUrl) {
  const u = new URL(workspaceUrl);
  u.pathname = u.pathname.replace(/\/workspace$/i, '/get-state-json');
  const publicId = u.searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id for get-state-json').toBeTruthy();
  return u.toString();
}

function buildDirectPagebuilderWorkspaceUrl(publicId) {
  const normalizedPublicId = String(publicId || '').trim();
  if (!normalizedPublicId) {
    throw new Error('buildDirectPagebuilderWorkspaceUrl: public_id is required');
  }
  return buildDirectRuntimeBackendUrl(
    `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(normalizedPublicId)}&expert=1`
  );
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
    return page.url();
  }
  await gotoStable(page, websitesWorkspaceUrl);
  let direct = await waitForPagebuilderWorkspaceUrlFromWebsites(page, 90000);
  if (!direct) {
    await page.locator('#site-builder-reload-page').click({ force: true }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    direct = await waitForPagebuilderWorkspaceUrlFromWebsites(page, 60000);
  }
  expect(direct, 'pagebuilder_workspace_url / public_id not populated after handoff').toBeTruthy();
  const normalizedDirect = normalizeToCurrentOrigin(page, direct);
  await gotoStable(page, normalizedDirect);
  const landedOnLogin = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
    .isVisible({ timeout: 3000 })
    .catch(() => false);
  if (landedOnLogin) {
    await loginAsAdmin(page);
    await gotoStable(page, normalizedDirect);
  }
  await expect(page).toHaveURL(PAGEBUILDER_AI_WORKSPACE_PATH_RE);
  return normalizedDirect;
}

/**
 * Prefer the real browser click path for Websites -> PageBuilder handoff.
 * Falls back to direct navigation only if the click itself errors.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} handoffLink
 * @param {string} websitesWorkspaceUrl
 */
async function openPagebuilderHandoff(page, handoffLink, websitesWorkspaceUrl) {
  const handoffHref = await handoffLink.getAttribute('href');
  expect(handoffHref, 'pagebuilder handoff link').toBeTruthy();
  const normalizedHandoffUrl = normalizeToCurrentOrigin(page, String(handoffHref));

  try {
    await handoffLink.click();
    await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
  } catch (error) {
    await gotoStable(page, normalizedHandoffUrl);
  }

  return ensurePagebuilderAiWorkspace(page, websitesWorkspaceUrl);
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
      try {
        await loginAsAdmin(page, { refreshRuntime: attempt > 1 });
        await gotoStable(page, buildWorkbenchUrl(backendRoot, provider, fakeMode));
        const browserPayload = await createWorkspace(page, backendRoot, provider, brief, { fakeMode });
        if (browserPayload && browserPayload.success && browserPayload.workspace_url) {
          return browserPayload;
        }
        lastError = new Error(`browser createWorkspace returned invalid payload: ${JSON.stringify(browserPayload)}`);
      } catch (browserError) {
        lastError = browserError;
      }
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

    const localDomain = buildWelineLocalSubdomain('pb-full');
    await mergeWebsitesScope(page, backendRoot, {
      site_title: 'Fashion Boutique',
      site_tagline: 'Style your story',
      target_domain: localDomain,
      brief_description: 'Need a stunning homepage with hero, about page with brand story, and a contact page.',
      user_description: 'Need a stunning homepage with hero, about page with brand story, and a contact page.',
    });
    await openWebsitesSummaryDetails(page);
    await expectSelectedDomainVisible(page, localDomain);
    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'domain purchase order_id should be > 0').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const handoffHref = await handoffLink.getAttribute('href');
    expect(handoffHref, 'handoff link href should not be empty').toBeTruthy();
    await gotoStable(page, normalizeToCurrentOrigin(page, String(handoffHref)));
    const nativeWorkspaceUrl = await ensurePagebuilderAiWorkspace(page, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);
    await expect(page.locator('#pb-ai-run-virtual-theme')).toBeVisible({ timeout: 30000 });
    const pagebuilderScopePatch = {
      site_title: 'Fashion Boutique',
      site_tagline: 'Style your story',
      target_domain: localDomain,
      brief_description: 'Need a stunning homepage with hero, about page with brand story, and a contact page.',
      user_description: 'Need a stunning homepage with hero, about page with brand story, and a contact page.',
    };
    const buildStart = await startPagebuilderBuild(page, backendRoot, pagebuilderScopePatch);
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart && buildStart.stream_url ? buildStart.stream_url : '')),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(buildStream, 'buildStream');
    await gotoStable(page, page.url());

    const stateUrl = buildPagebuilderGetStateJsonUrl(page.url());
    await expect
      .poll(async () => {
        const res = await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok()) {
          return 0;
        }
        const json = await res.json();
        return Number(json && json.data && json.data.draft_website_id ? json.data.draft_website_id : 0);
      }, { timeout: WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => {
        const res = await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok()) {
          return 0;
        }
        const json = await res.json();
        return Number(json && json.data && json.data.virtual_theme_id ? json.data.virtual_theme_id : 0);
      }, { timeout: WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });
  });

  test('full flow: websites handoff publishes storefront on weline.local', async ({ page }) => {
    test.slow();
    test.setTimeout(3600000);

    const suffix = Date.now().toString().slice(-8);
    const subFqdn = buildWelineLocalSubdomain('pb-site');
    const hostsReg = tryRegisterWelineLocalSubdomain(subFqdn);
    const canBrowserVisit = hostsReg.ok === true;
    if (!canBrowserVisit) {
      const reason = hostsReg.skipped
        ? String(hostsReg.message || '')
        : `server:hosts:add failed 鈥?run terminal as Administrator or: php bin/w server:hosts:add ${subFqdn}`;
      process.stdout.write(`[e2e] browser storefront URL will use Host fallback (${reason})\n`);
    }

    const backendRoot = await loginAsAdmin(page);
    const brief = 'Build a boutique storefront with homepage, about page, and contact page, then publish it to a weline.local subdomain.';

    const payload = await createWorkspaceWithRetry(page, backendRoot, brief, {
      provider: 'pagebuilder',
      fakeMode: true,
      retries: 3,
    });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = normalizeToCurrentOrigin(page, String(payload.workspace_url));
    await gotoStable(page, workspaceUrl);

    await mergeWebsitesScope(page, backendRoot, {
      site_title: `E2E Published Site ${suffix}`,
      site_tagline: 'Published via Websites handoff',
      target_domain: subFqdn,
      brief_description: brief,
      user_description: brief,
      page_types: ['home_page', 'about_page', 'contact_page'],
    });
    await openWebsitesSummaryDetails(page);
    await expectSelectedDomainVisible(page, subFqdn);

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'fake local domain purchase should return order_id').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const nativeWorkspaceUrl = await openPagebuilderHandoff(page, handoffLink, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);

    const pagebuilderScopePatch = {
      site_title: `E2E Published Site ${suffix}`,
      site_tagline: 'Published via Websites handoff',
      target_domain: subFqdn,
      brief_description: brief,
      user_description: brief,
      page_types: ['home_page', 'about_page', 'contact_page'],
    };
    await mergePagebuilderScope(page, pagebuilderScopePatch);
    const buildStart = await startPagebuilderBuild(page, backendRoot, pagebuilderScopePatch);
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart && buildStart.stream_url ? buildStart.stream_url : '')),
      { timeoutMs: LONG_WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(buildStream, 'buildStream');

    await gotoStable(page, nativeWorkspaceUrl || page.url());
    const landedOnLoginAfterBuild = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
      .isVisible({ timeout: 3000 })
      .catch(() => false);
    if (landedOnLoginAfterBuild) {
      await loginAsAdmin(page, { refreshRuntime: true });
      await gotoStable(page, nativeWorkspaceUrl || page.url());
    }

    const stateUrl = buildPagebuilderGetStateJsonUrl(nativeWorkspaceUrl || page.url());
    const builtScope = await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => Number(data.draft_website_id || 0) > 0 && Number(data.virtual_theme_id || 0) > 0,
      LONG_WORKSPACE_TIMEOUT
    );
    expect(Number(builtScope.draft_website_id || 0)).toBeGreaterThan(0);
    expect(Number(builtScope.virtual_theme_id || 0)).toBeGreaterThan(0);

    await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => Boolean(data.can_publish) || ['can_publish', 'published'].includes(String(data.workspace_status || '')),
      LONG_WORKSPACE_TIMEOUT
    );

    await gotoStable(page, nativeWorkspaceUrl || page.url());
    const publishStart = await startPagebuilderPublish(page);
    const publishStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
      { timeoutMs: LONG_WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(publishStream, 'publishStream');

    const publishedScope = await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => String(data.publish_status || '') === 'published',
      LONG_WORKSPACE_TIMEOUT
    );
    expect(String(publishedScope.publish_status || '')).toBe('published');
    expect(Number(publishedScope.draft_website_id || 0)).toBeGreaterThan(0);

    const origin = new URL(getRuntimeInfo().runtime.target_origin);
    const portSeg = origin.port ? `:${origin.port}` : '';
    const storefrontUrl = `${origin.protocol}//${subFqdn}${portSeg}/`;

    try {
      await page.goto(storefrontUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await expect(page.locator('body')).toBeVisible();
      const html = await page.content();
      expect(html.length).toBeGreaterThan(200);
      expect(html.toLowerCase()).not.toContain('404 not found');
      expect(html).toContain(`E2E Published Site ${suffix}`);
    } catch (storefrontError) {
      const storefrontResp = await page.request.get(`${origin.origin}/`, {
        headers: { Host: subFqdn },
        timeout: 120000,
        ignoreHTTPSErrors: true,
      });
      expect(storefrontResp.ok(), `storefront HTTP ${storefrontResp.status()}`).toBeTruthy();
      const storefrontHtml = await storefrontResp.text();
      expect(storefrontHtml.length).toBeGreaterThan(200);
      expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
      expect(storefrontHtml).toContain(`E2E Published Site ${suffix}`);
      process.stdout.write(`[e2e] storefront direct-open fallback: ${String(storefrontError && storefrontError.message ? storefrontError.message : storefrontError)}\n`);
    }
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
    await openWebsitesSummaryDetails(page);
    await expect(page.locator('#site-builder-title')).toBeVisible({ timeout: 15000 });

    for (const selector of [
      '#site-builder-api-start-domain-purchase',
      '#site-builder-api-set-stage',
    ]) {
      const value = await page.locator(selector).inputValue();
      expect(value, `${selector} should expose backend api url`).toMatch(/\/backend\//i);
    }
    const stateJsonLink = page.locator('a:has-text("状态 JSON")').first();
    await expect(stateJsonLink).toBeVisible({ timeout: 15000 });

    const localDomain = buildWelineLocalSubdomain('pb-virtual');
    await mergeWebsitesScope(page, backendRoot, {
      site_title: 'AI Pipeline Verification',
      target_domain: localDomain,
      brief_description: 'Validate virtual theme controls and AI handoff chain.',
      user_description: 'Validate virtual theme controls and AI handoff chain.',
    });
    await openWebsitesSummaryDetails(page);
    await expectSelectedDomainVisible(page, localDomain);

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'domain purchase should return order id').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    await openPagebuilderHandoff(page, handoffLink, workspaceUrl);
    await ensurePagebuilderExpertLayout(page);

    await expect(page.locator('#pb-ai-run-virtual-theme')).toBeVisible({ timeout: 30000 });
    const pagebuilderScopePatch = {
      site_title: 'AI Pipeline Verification',
      site_tagline: 'Virtual theme verification',
      target_domain: localDomain,
      brief_description: 'Validate virtual theme controls and AI handoff chain.',
      user_description: 'Validate virtual theme controls and AI handoff chain.',
    };
    const buildStart = await startPagebuilderBuild(page, backendRoot, pagebuilderScopePatch);
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart && buildStart.stream_url ? buildStart.stream_url : '')),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(buildStream, 'buildStream');
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
      const fd = new FormData();
      fd.append('fake_mode', '1');
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
    const localDomain = `pb-pub-${suffix}.weline.local`;
    const selectedPageTypes = ['home_page', 'about_page', 'contact_page'];
    const scopePatch = {
      site_title: uniqueSiteTitle,
      site_tagline: 'E2E publish pipeline',
      target_domain: localDomain,
      brief_description: 'Minimal boutique site for automated publish verification.',
      user_description: 'Minimal boutique site for automated publish verification.',
      page_types: selectedPageTypes,
    };

    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: LONG_WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();

    const nativeWorkspaceUrl = page.url();
    await gotoStable(page, nativeWorkspaceUrl);
    const stateUrl = buildPagebuilderGetStateJsonUrl(nativeWorkspaceUrl);
    await expect
      .poll(async () => {
        const data = await fetchPagebuilderStateData(page, stateUrl);
        return Number(data && data.draft_website_id ? data.draft_website_id : 0);
      }, { timeout: WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    await expect
      .poll(async () => {
        const data = await fetchPagebuilderStateData(page, stateUrl);
        return Number(data && data.virtual_theme_id ? data.virtual_theme_id : 0);
      }, { timeout: WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    const publishStart = await startPagebuilderPublish(page);
    const publishStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
      { timeoutMs: WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(publishStream, 'publishStream');

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
    test.setTimeout(3600000);

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
    await mergeWebsitesScope(page, backendRoot, {
      site_title: uniqueSiteTitle,
      site_tagline: 'Local weline.local subdomain shop',
      target_domain: subFqdn,
      brief_description: brief,
      user_description: brief,
      page_types: ['home_page', 'about_page', 'contact_page'],
    });
    await openWebsitesSummaryDetails(page);
    await expectSelectedDomainVisible(page, subFqdn);

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id, 'fake local domain purchase should return order_id').toBeGreaterThan(0);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 30000 });
    const nativeWorkspaceUrl = await openPagebuilderHandoff(page, handoffLink, workspaceUrl);
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
    await mergePagebuilderScope(page, scopePatch);

    const buildStart = await startPagebuilderBuild(page, backendRoot, { ...scopePatch });
    const buildStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(buildStart.stream_url)),
      { timeoutMs: LONG_WORKSPACE_TIMEOUT }
    );
    expect(buildStream.ok, JSON.stringify(buildStream)).toBeTruthy();

    await gotoStable(page, nativeWorkspaceUrl || page.url());
    const landedOnLoginAfterBuild = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
      .isVisible({ timeout: 3000 })
      .catch(() => false);
    if (landedOnLoginAfterBuild) {
      await loginAsAdmin(page, { refreshRuntime: true });
      await gotoStable(page, nativeWorkspaceUrl || page.url());
    }
    const stateUrl = buildPagebuilderGetStateJsonUrl(nativeWorkspaceUrl || page.url());
    const builtScopeData = await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => {
        const hasDraftWebsite = Number(data.draft_website_id || 0) > 0;
        const hasVirtualTheme = Number(data.virtual_theme_id || 0) > 0 || String(data.workspace_track || '') === 'html_blocks';
        return hasDraftWebsite && hasVirtualTheme;
      },
      LONG_WORKSPACE_TIMEOUT
    );
    const virtualPagesByType = builtScopeData.virtual_pages_by_type || {};
    const workspaceTrack = String(builtScopeData.workspace_track || '');
    for (const pageType of selectedPageTypes) {
      const row = virtualPagesByType[pageType] || {};
      const hasGeneratedMarker = Boolean(String(row.last_generated_at || '').trim());
      const hasPreviewUrl = Boolean(String(row.virtual_preview_url || '').trim());
      const hasBlocks = Array.isArray(row.blocks) && row.blocks.length > 0;
      expect(
        hasGeneratedMarker || hasPreviewUrl || hasBlocks,
        `virtual page should expose generated structure or preview markers: ${pageType}`
      ).toBeTruthy();
      if (workspaceTrack === 'html_blocks') {
        const blocks = Array.isArray(row.blocks) ? row.blocks : [];
        expect(blocks.length, `html_blocks track should generate blocks for: ${pageType}`).toBeGreaterThan(0);
      }
    }
    if (workspaceTrack !== 'html_blocks') {
      expect(Number(builtScopeData.virtual_theme_id || 0), 'virtual theme track should produce virtual_theme_id').toBeGreaterThan(0);
    }
    await expect
      .poll(async () => {
        const res = await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok()) {
          return 0;
        }
        const json = await res.json();
        return Number(json && json.data && json.data.draft_website_id ? json.data.draft_website_id : 0);
      }, { timeout: LONG_WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);
    await expect
      .poll(async () => {
        const res = await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok()) {
          return 0;
        }
        const json = await res.json();
        return Number(json && json.data && json.data.virtual_theme_id ? json.data.virtual_theme_id : 0);
      }, { timeout: LONG_WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => Boolean(data.can_publish) || ['can_publish', 'published'].includes(String(data.workspace_status || '')),
      LONG_WORKSPACE_TIMEOUT
    );

    await gotoStable(page, nativeWorkspaceUrl || page.url());
    const publishStart = await startPagebuilderPublish(page);
    const publishStream = await consumeSseStream(
      page,
      normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
      { timeoutMs: LONG_WORKSPACE_TIMEOUT }
    );
    expectFinishedOrResumedStream(publishStream, 'publishStream');
    const publishedScope = await waitForPagebuilderStateData(
      page,
      stateUrl,
      (data) => String(data.publish_status || '') === 'published',
      LONG_WORKSPACE_TIMEOUT
    );
    const publishedPagesByType = publishedScope.pagebuilder_pages_by_type || {};
    for (const pageType of selectedPageTypes) {
      expect(
        Number(((publishedPagesByType[pageType] || {}).page_id) || 0),
        `published state should include materialized page id for: ${pageType}`
      ).toBeGreaterThan(0);
    }

    const indexUrl = new URL(buildBackendUrl('pagebuilder/backend/page/index'));
    indexUrl.searchParams.set('search', uniqueSiteTitle);
    await gotoStable(page, indexUrl.toString());
    const homeRow = page.locator('.pagebuilder-page-item').filter({ hasText: uniqueSiteTitle });
    const homeRowCount = await homeRow.count().catch(() => 0);
    if (homeRowCount > 0) {
      await expect(homeRow.first()).toBeVisible({ timeout: 60000 });
      await expect(homeRow.first()).toHaveClass(/is-published/);
    }

    const origin = new URL(getRuntimeInfo().runtime.target_origin);
    const portSeg = origin.port ? `:${origin.port}` : '';
    const storefrontUrl = `${origin.protocol}//${subFqdn}${portSeg}/`;

    let storefrontHtml = '';
    try {
      await page.goto(storefrontUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await expect(page.locator('body')).toBeVisible();
      storefrontHtml = await page.content();
      expect(storefrontHtml.length).toBeGreaterThan(200);
      expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
    } catch (storefrontError) {
      const storefrontResp = await page.request.get(`${origin.origin}/`, {
        headers: { Host: subFqdn },
        timeout: 120000,
        ignoreHTTPSErrors: true,
      });
      expect(storefrontResp.ok(), `storefront HTTP ${storefrontResp.status()}`).toBeTruthy();
      storefrontHtml = await storefrontResp.text();
      expect(storefrontHtml.length).toBeGreaterThan(200);
      expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
      process.stdout.write(`[e2e] storefront direct-open fallback: ${String(storefrontError && storefrontError.message ? storefrontError.message : storefrontError)}\n`);
    }

    // 验证前台默认首页来自本次建站（标题命中），证明默认落地为本次生成主题页面。
    if (!String(storefrontHtml || '').includes(uniqueSiteTitle)) {
      await expect.poll(
        () => fetchStorefrontHtmlViaCurl(storefrontUrl),
        { timeout: 60000 }
      ).toContain(uniqueSiteTitle);
      storefrontHtml = fetchStorefrontHtmlViaCurl(storefrontUrl);
      expect(storefrontHtml.length).toBeGreaterThan(200);
      expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
    }
    expect(storefrontHtml).toContain(uniqueSiteTitle);
  });
});

moduleDescribe(test, 'GuoLaiRen_PageBuilder', 'AI site workbench regressions', () => {
  /**
   * 与 PHPUnit 集成测 AiSiteWorkbenchSuccessIntegrationTest 阶段划分对齐：
   * 阶段 1 信息 → merge-scope；阶段 2/3 的完整链路见同文件内其它用例（build/publish/storefront）。
   */
  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-GUIDED-001' },
    'guided workspace: stepper + get-state-json contract (frontend wiring)',
    async ({ page }) => {
      test.setTimeout(120000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await openPagebuilderWorkspaceGuidedAfterExpert(page, backendRoot);

      await expect(page.locator('.pb-guided-steps')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#pb-ai-guided-scope-defaults')).toBeAttached();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const stateRes = await page.request.get(stateUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      expect(stateRes.ok(), `get-state-json HTTP ${stateRes.status()}`).toBeTruthy();
      const stateJson = await stateRes.json();
      expect(stateJson && stateJson.success, JSON.stringify(stateJson)).toBeTruthy();
      expect(stateJson.data && typeof stateJson.data === 'object').toBeTruthy();
      const d = stateJson.data;
      expect(typeof d.public_id === 'string' && d.public_id.length > 0).toBeTruthy();
    }
  );

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

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-ROUTE-004' },
    'legacy index route stays stable after workspace page-type switch',
    async ({ page }) => {
      test.slow();
      test.setTimeout(420000);

      const backendRoot = await loginAsAdmin(page);
      const createSessionUrl = normalizeToCurrentOrigin(
        page,
        new URL('pagebuilder/backend/ai-site-agent/post-create-session', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
      );
      const createResp = await page.request.post(createSessionUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const createText = await createResp.text();
      let createPayload;
      try {
        createPayload = JSON.parse(createText);
      } catch (error) {
        throw new Error(`pagebuilder create-session(api request): HTTP ${createResp.status()} non-JSON body=${createText.slice(0, 400)}`);
      }
      expect(createPayload && createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      expect(createPayload.workspace_url).toBeTruthy();
      const workspaceUrl = normalizeToCurrentOrigin(page, String(createPayload.workspace_url));
      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-route-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Route ${suffix}`,
        site_tagline: 'legacy route stable',
        target_domain: localDomain,
        brief_description: 'Verify legacy index route does not drift after switching preview page type.',
        user_description: 'Verify legacy index route does not drift after switching preview page type.',
        page_types: ['home_page', 'about_page', 'contact_page'],
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

      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);
      await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });

      const aboutTab = page.locator('.pb-ai-preview-tab[data-page-type="about_page"]').first();
      await expect(aboutTab).toBeVisible({ timeout: 30000 });
      await aboutTab.click();
      await expect
        .poll(async () => (await page.locator('#pb-ai-visual-preview-frame').getAttribute('src')) || '', {
          timeout: 30000,
        })
        .toMatch(/page_type=about_page/);

      const legacyIndexUrl = normalizeToCurrentOrigin(
        page,
        new URL('pagebuilder/backend/ai-site-agent/index?legacy=1', `${String(backendRoot).replace(/\/+$/, '')}/`).toString()
      );
      await gotoStable(page, legacyIndexUrl);

      await expect(page.locator('#pb-ai-site-create')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('h5.card-title', { hasText: '最近会话' })).toBeVisible({ timeout: 15000 });

      const routeSnapshot = await page.evaluate(() => ({
        pathname: window.location.pathname,
        search: window.location.search,
      }));
      expect(String(routeSnapshot.pathname || '')).toMatch(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/index$/i);
      expect(String(routeSnapshot.search || '')).toContain('legacy=1');
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-PLAN-005' },
    'expert: phase-1 plan and phase-2 task-plan gates are confirmed before build starts',
    async ({ page }) => {
      test.slow();
      test.setTimeout(600000);

      await loginAsAdmin(page, {
        useProxy: false,
        bootstrapOnly: true,
        bootstrapModes: ['wls'],
      });
      const brief = 'Smoke phase-1 plan and phase-2 task-plan confirmation before build.';
      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      expect(String(createPayload.public_id || '').trim()).toBeTruthy();
      const seededPlan = preparePagebuilderPlanDraftViaPhp(String(createPayload.public_id || ''));
      expect(seededPlan.success, JSON.stringify(seededPlan)).toBeTruthy();
      expect(String(seededPlan.plan_markdown || '').trim()).toBeTruthy();
      const workspaceUrl = buildDirectPagebuilderWorkspaceUrl(String(createPayload.public_id || ''));

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-plan-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Plan Gate ${suffix}`,
        site_tagline: 'phase1 + phase2 gate smoke',
        target_domain: localDomain,
        brief_description: brief,
        user_description: brief,
        page_types: ['home_page'],
        fake_mode: 1,
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);

      const phase1Confirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
        public_id: String(createPayload.public_id || ''),
      });
      expect(phase1Confirm.payload && phase1Confirm.payload.success, JSON.stringify(phase1Confirm.payload)).toBeTruthy();

      const phase2Start = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-task-plan', {
        public_id: String(createPayload.public_id || ''),
        scope_patch: JSON.stringify(scopePatch || {}),
      });
      expect(phase2Start.payload && phase2Start.payload.success, JSON.stringify(phase2Start.payload)).toBeTruthy();
      expect(phase2Start.payload.task_plan && phase2Start.payload.task_plan.markdown).toBeTruthy();

      const phase2Confirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-task-plan', {
        public_id: String(createPayload.public_id || ''),
      });
      expect(phase2Confirm.payload && phase2Confirm.payload.success, JSON.stringify(phase2Confirm.payload)).toBeTruthy();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const confirmedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => Boolean(data && data.plan_confirmed) && Boolean(data && data.task_plan_confirmed),
        WORKSPACE_TIMEOUT
      );
      expect(Boolean(confirmedState && confirmedState.plan_confirmed)).toBeTruthy();
      expect(Boolean(confirmedState && confirmedState.task_plan_confirmed)).toBeTruthy();
      expect(Boolean(confirmedState && confirmedState.has_virtual_theme_plan)).toBeTruthy();

      const buildStart = await startPagebuilderBuildByUrl(page, workspaceUrl, { ...scopePatch });
      expect(String(buildStart.stream_url || '').trim()).toBeTruthy();
      const buildStreamUrl = new URL(String(buildStart.stream_url || ''), workspaceUrl).toString();
      const initialStream = await consumeSseStream(
        page,
        buildStreamUrl,
        { timeoutMs: 180000 }
      );
      expect(initialStream && initialStream.ok, JSON.stringify(initialStream)).toBeTruthy();
      const eventNames = Array.isArray(initialStream.eventNames) ? initialStream.eventNames : [];
      const hasPositiveBuildSignal = eventNames.includes('start')
        || eventNames.includes('progress')
        || eventNames.includes('page_generated')
        || eventNames.includes('task_completed')
        || eventNames.includes('chunk');
      expect(hasPositiveBuildSignal, JSON.stringify(initialStream)).toBeTruthy();
      const duplicateObserverPayload = Array.isArray(initialStream.events)
        ? initialStream.events.find((event) => event && event.event === 'warning' && event.data && event.data.observer_mode === true)
        : null;
      expect(
        eventNames.includes('start')
          || eventNames.includes('progress')
          || Boolean(duplicateObserverPayload),
        JSON.stringify(initialStream)
      ).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-PLAN-REFINE-007' },
    'expert: phase-1 refine and rebuild task modes produce distinct plan SSE results',
    async ({ page }) => {
      test.slow();
      test.setTimeout(900000);

      await loginAsAdmin(page, {
        useProxy: false,
        bootstrapOnly: true,
        bootstrapModes: ['wls'],
      });

      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing' });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      const publicId = String(createPayload.public_id || '');
      expect(publicId).toBeTruthy();
      const workspaceUrl = buildDirectPagebuilderWorkspaceUrl(publicId);

      const suffix = Date.now().toString().slice(-8);
      const scopePatch = {
        site_title: `E2E Plan AI ${suffix}`,
        site_tagline: 'phase1 real ai refine rebuild',
        target_domain: buildLocalDomain(`pb-plan-ai-${suffix}`),
        brief_description: 'Create a plan flow that can be refined and rebuilt with visible SSE differences.',
        user_description: 'Create a plan flow that can be refined and rebuilt with visible SSE differences.',
        page_types: ['home_page', 'about_page'],
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);

      const rebuildStream = await postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, 'post-plan-sse', {
        public_id: publicId,
        prompt_mode: 'rebuild',
        instruction: 'Rebuild the full plan with a clearer enterprise positioning and stronger trust tone.',
        round: '1',
      }, 240000);
      expect(rebuildStream.response.ok(), rebuildStream.rawHead).toBeTruthy();
      expect((rebuildStream.eventNames || []).length, JSON.stringify(rebuildStream)).toBeGreaterThan(0);

      const rebuildMarkdown = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-plan-markdown',
        (value) => value.includes('# ') || value.includes('## '),
        180000
      );
      expect(rebuildMarkdown.length).toBeGreaterThan(200);

      const refineStream = await postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, 'post-plan-sse', {
        public_id: publicId,
        prompt_mode: 'refine',
        instruction: 'Only refine the about page positioning and keep the rest of the plan stable.',
        target_scope: 'pages.about_page',
        round: '2',
      }, 240000);
      expect(refineStream.response.ok(), refineStream.rawHead).toBeTruthy();
      expect((refineStream.eventNames || []).length, JSON.stringify(refineStream)).toBeGreaterThan(0);

      const refinedMarkdown = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-plan-markdown',
        (value) => value.length > 0 && value !== rebuildMarkdown,
        180000
      );
      expect(refinedMarkdown.length).toBeGreaterThan(200);
      expect(refinedMarkdown).not.toBe(rebuildMarkdown);
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-TASKPLAN-REFINE-008' },
    'expert: phase-2 refine and rebuild task-plan modes update task-plan draft via SSE',
    async ({ page }) => {
      test.slow();
      test.setTimeout(900000);

      await loginAsAdmin(page, {
        useProxy: false,
        bootstrapOnly: true,
        bootstrapModes: ['wls'],
      });

      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      const publicId = String(createPayload.public_id || '');
      expect(publicId).toBeTruthy();
      const seededPlan = preparePagebuilderPlanDraftViaPhp(publicId);
      expect(seededPlan.success, JSON.stringify(seededPlan)).toBeTruthy();
      const workspaceUrl = buildDirectPagebuilderWorkspaceUrl(publicId);

      const suffix = Date.now().toString().slice(-8);
      const scopePatch = {
        site_title: `E2E TaskPlan AI ${suffix}`,
        site_tagline: 'phase2 task plan refine rebuild',
        target_domain: buildLocalDomain(`pb-taskplan-ai-${suffix}`),
        brief_description: 'Create a task plan flow that can be refined and rebuilt with visible draft changes.',
        user_description: 'Create a task plan flow that can be refined and rebuilt with visible draft changes.',
        page_types: ['home_page'],
        fake_mode: 1,
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);
      const phase1Confirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
        public_id: publicId,
      });
      expect(phase1Confirm.payload && phase1Confirm.payload.success, JSON.stringify(phase1Confirm.payload)).toBeTruthy();

      const phase2Start = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-task-plan', {
        public_id: publicId,
        scope_patch: JSON.stringify(scopePatch || {}),
      });
      expect(phase2Start.payload && phase2Start.payload.success, JSON.stringify(phase2Start.payload)).toBeTruthy();

      const taskPlanBefore = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-task-plan-markdown',
        (value) => value.length > 0,
        120000
      );
      expect(taskPlanBefore.length).toBeGreaterThan(200);

      const refineStream = await postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, 'post-task-plan-sse', {
        public_id: publicId,
        prompt_mode: 'refine_task_plan',
        instruction: 'Only refine the hero task script and keep execution order stable.',
        target_scope: 'page:home_page:hero',
        round: '1',
      }, 180000);
      expect(refineStream.response.ok(), refineStream.rawHead).toBeTruthy();
      expect((refineStream.eventNames || []).length, JSON.stringify(refineStream)).toBeGreaterThan(0);

      const taskPlanAfterRefine = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-task-plan-markdown',
        (value) => value.length > 0 && value !== taskPlanBefore,
        120000
      );
      expect(taskPlanAfterRefine).not.toBe(taskPlanBefore);

      const rebuildStream = await postPagebuilderWorkspaceSseByUrl(page, workspaceUrl, 'post-task-plan-sse', {
        public_id: publicId,
        prompt_mode: 'rebuild_task_plan',
        instruction: 'Rebuild the full task plan with a stronger conversion-first execution order.',
        round: '2',
      }, 180000);
      expect(rebuildStream.response.ok(), rebuildStream.rawHead).toBeTruthy();
      expect((rebuildStream.eventNames || []).length, JSON.stringify(rebuildStream)).toBeGreaterThan(0);

      const taskPlanAfterRebuild = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-task-plan-markdown',
        (value) => value.length > 0 && value !== taskPlanAfterRefine,
        120000
      );
      expect(taskPlanAfterRebuild).not.toBe(taskPlanAfterRefine);
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-FULL-006' },
    'frontend full chain: requirement -> plan/task plan confirm -> build -> publish -> storefront',
    async ({ page }) => {
      test.slow();
      test.setTimeout(3600000);

      await loginAsAdmin(page, {
        useProxy: false,
        bootstrapOnly: true,
        bootstrapModes: ['wls'],
      });

      const suffix = Date.now().toString().slice(-8);
      const subFqdn = buildWelineLocalSubdomain('pb-full-ui');
      const hostsReg = tryRegisterWelineLocalSubdomain(subFqdn);
      if (!hostsReg.ok) {
        const reason = hostsReg.skipped
          ? String(hostsReg.message || '')
          : `server:hosts:add failed, fallback to Host header request (${subFqdn})`;
        process.stdout.write(`[e2e] storefront direct-open fallback enabled: ${reason}\n`);
      }

      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      expect(String(createPayload.public_id || '').trim()).toBeTruthy();
      const publicId = String(createPayload.public_id || '');
      const workspaceRoute = `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(publicId)}&expert=1`;
      const directWorkspaceUrl = buildDirectPagebuilderWorkspaceUrl(publicId);
      let workspaceUrl = directWorkspaceUrl;
      try {
        await gotoStable(page, workspaceUrl);
      } catch (error) {
        workspaceUrl = buildSameOriginBackendUrl(page, workspaceRoute);
        await gotoStable(page, workspaceUrl);
      }
      // 某些环境会被预览页顶层接管；强制回到 workspace 再做表单交互，避免 fill 无超时卡死
      if (!/\/workspace(\?|$)/i.test(page.url())) {
        await gotoStable(page, workspaceUrl);
      }
      await ensurePagebuilderExpertLayout(page);
      await expect(page.locator('#pb-ai-site-title')).toBeVisible({ timeout: 30000 });

      // Step 1: 前端需求输入
      const siteTitle = `E2E Full UI ${suffix}`;
      const scopePatch = {
        site_title: siteTitle,
        site_tagline: 'full frontend chain',
        target_domain: subFqdn,
        brief_description: 'Generate plan, confirm task plan, build theme, publish website with frontend checks.',
        user_description: 'Generate plan, confirm task plan, build theme, publish website with frontend checks.',
        page_types: ['home_page', 'about_page', 'contact_page'],
        fake_mode: 1,
      };
      await page.fill('#pb-ai-site-title', scopePatch.site_title, { timeout: 15000 });
      await page.fill('#pb-ai-site-tagline', scopePatch.site_tagline, { timeout: 15000 });
      const targetDomainInput = page.locator('#pb-ai-target-domain');
      const targetDomainSummaryInput = page.locator('#pb-ai-target-domain-summary');
      const hasTargetDomainInput = await targetDomainInput.count();
      if (hasTargetDomainInput > 0) {
        await targetDomainInput.fill(scopePatch.target_domain, { timeout: 15000 });
      } else {
        await targetDomainSummaryInput.fill(scopePatch.target_domain, { timeout: 15000 });
      }
      await page.fill('#pb-ai-brief-description', scopePatch.brief_description, { timeout: 15000 });
      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);

      // Step 2: 校验方案/任务方案前端弹窗组件可用
      await expect(page.locator('#pb-ai-plan-generation-modal')).toBeAttached();
      await expect(page.locator('#pb-ai-plan-mode-refine')).toBeAttached();
      await expect(page.locator('#pb-ai-plan-mode-rebuild')).toBeAttached();
      await expect(page.locator('#pb-ai-task-plan-generation-modal')).toBeAttached();
      await expect(page.locator('#pb-ai-task-plan-mode-refine')).toBeAttached();
      await expect(page.locator('#pb-ai-task-plan-mode-rebuild')).toBeAttached();

      // Step 3: 走完整两阶段确认
      const gateFlow = await ensurePagebuilderPlanAndTaskPlanConfirmedByUrl(page, workspaceUrl, scopePatch);
      expect(gateFlow.phase1Start.plan && gateFlow.phase1Start.plan.markdown, JSON.stringify(gateFlow.phase1Start)).toBeTruthy();
      expect(gateFlow.phase2Start.task_plan && gateFlow.phase2Start.task_plan.markdown, JSON.stringify(gateFlow.phase2Start)).toBeTruthy();

      // Step 4: 构建主题/页面
      const buildStart = await startPagebuilderBuildByUrl(page, workspaceUrl, { ...scopePatch });
      const buildStreamUrl = new URL(String(buildStart.stream_url || ''), workspaceUrl).toString();
      const buildStream = await consumeSseStream(page, buildStreamUrl, { timeoutMs: LONG_WORKSPACE_TIMEOUT });
      expectFinishedOrResumedStream(buildStream, 'full-chain-build-stream');

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const builtState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => Number(data.draft_website_id || 0) > 0 && (Number(data.virtual_theme_id || 0) > 0 || String(data.workspace_track || '') === 'html_blocks'),
        LONG_WORKSPACE_TIMEOUT
      );
      expect(Number(builtState.draft_website_id || 0)).toBeGreaterThan(0);

      // Step 5: 前端只读确认方案入口可打开（保证前端可用）
      await gotoStable(page, workspaceUrl);
      const stage1ConfirmedBtn = page.locator('.pb-ai-view-confirmed-plan[data-plan-type="stage1"]').first();
      const stage1BtnVisible = await stage1ConfirmedBtn.isVisible({ timeout: 8000 }).catch(() => false);
      if (stage1BtnVisible) {
        await stage1ConfirmedBtn.click({ force: true });
        await expect(page.locator('#pb-ai-confirmed-plan-view-modal')).toHaveClass(/show/, { timeout: 10000 });
        await expect(page.locator('#pb-ai-copy-plan-markdown')).toBeVisible({ timeout: 10000 });
        await page.locator('#pb-ai-confirmed-plan-view-modal .btn-close').click({ force: true });
      }

      // Step 6: 发布
      const publishStart = await startPagebuilderPublish(page);
      const publishStream = await consumeSseStream(
        page,
        normalizeToCurrentOrigin(page, String(publishStart.stream_url)),
        { timeoutMs: LONG_WORKSPACE_TIMEOUT }
      );
      expectFinishedOrResumedStream(publishStream, 'full-chain-publish-stream');

      const publishedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => String(data.publish_status || '') === 'published',
        LONG_WORKSPACE_TIMEOUT
      );
      expect(String(publishedState.publish_status || '')).toBe('published');

      // Step 7: 验证前台可访问
      const origin = new URL(getRuntimeInfo().runtime.target_origin);
      const portSeg = origin.port ? `:${origin.port}` : '';
      const storefrontUrl = `${origin.protocol}//${subFqdn}${portSeg}/`;
      try {
        await page.goto(storefrontUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await expect(page.locator('body')).toBeVisible();
        const html = await page.content();
        expect(html.length).toBeGreaterThan(200);
        expect(html.toLowerCase()).not.toContain('404 not found');
      } catch (storefrontError) {
        const storefrontResp = await page.request.get(`${origin.origin}/`, {
          headers: { Host: subFqdn },
          timeout: 120000,
          ignoreHTTPSErrors: true,
        });
        expect(storefrontResp.ok(), `storefront HTTP ${storefrontResp.status()}`).toBeTruthy();
        const storefrontHtml = await storefrontResp.text();
        expect(storefrontHtml.length).toBeGreaterThan(200);
        expect(storefrontHtml.toLowerCase()).not.toContain('404 not found');
      }
    }
  );
});
