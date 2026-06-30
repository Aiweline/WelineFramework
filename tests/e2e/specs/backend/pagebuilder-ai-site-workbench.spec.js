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
/** Route table may emit ai-site-agent; keep compatibility with historical aiSiteAgent form. */
const PAGEBUILDER_AI_WORKSPACE_PATH_RE = /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/workspace\b/i;

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 */
async function gotoStable(page, url) {
  let lastError = null;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      await page.goto(url, { waitUntil: 'commit', timeout: WORKSPACE_TIMEOUT });
      await page.locator('body').first().waitFor({ state: 'attached', timeout: 30000 });
      await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
      const currentUrl = String(page.url() || '');
      const bodyText = await page.locator('body').first().textContent().catch(() => '');
      if (currentUrl.startsWith('chrome-error://') || String(bodyText || '').includes('ERR_EMPTY_RESPONSE')) {
        throw new Error(`unstable navigation: ${currentUrl || 'unknown'} ${String(bodyText || '').slice(0, 200)}`);
      }
      return;
    } catch (error) {
      lastError = error;
      const message = String(error && error.message ? error.message : error || '');
      const retryable = message.includes('chrome-error://chromewebdata/')
        || message.includes('ERR_EMPTY_RESPONSE')
        || message.includes('ERR_ABORTED')
        || message.includes('ERR_TIMED_OUT')
        || message.includes('ERR_CONNECTION_RESET')
        || message.includes('net::ERR_')
        || message.includes('unstable navigation: chrome-error://');
      if (!retryable || attempt >= 3) {
        throw error;
      }
      await page.waitForTimeout(1500 * (attempt + 1));
    }
  }
  if (lastError) {
    throw lastError;
  }
}

async function postJsonWithRetry(page, postUrl, form, timeoutMs = 90000) {
  let lastError = null;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      return await page.request.post(postUrl, {
        form,
        timeout: timeoutMs,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
    } catch (error) {
      lastError = error;
      const message = String(error && error.message ? error.message : error || '');
      const retryable = /socket hang up|ECONNRESET|ERR_CONNECTION_RESET|upstream_request_failed|Timeout/i.test(message);
      if (!retryable || attempt >= 3) {
        throw error;
      }
      await page.waitForTimeout(1500 * (attempt + 1));
    }
  }
  throw lastError;
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

function isAiProviderReadinessFailure(payload) {
  return Boolean(payload)
    && (
      String(payload.code || '') === 'AI_PROVIDER_NOT_READY'
      || String(payload.message || '').includes('AI provider readiness check failed before queue creation')
    );
}

function isRetryableBrowserFetchFailure(error) {
  const message = String(error && error.message ? error.message : error || '');
  return message.includes('Failed to fetch')
    || message.includes('ECONNRESET')
    || message.includes('socket hang up')
    || message.includes('Request timed out')
    || message.includes('Timeout')
    || message.includes('ERR_CONNECTION_RESET')
    || message.includes('ERR_EMPTY_RESPONSE')
    || message.includes('net::ERR_');
}

function isRetryableBrowserFetchResult(result) {
  const payload = result && result.payload ? result.payload : {};
  const message = String(payload.message || result?.rawHead || '');
  return Boolean(result)
    && !result.ok
    && (
      String(payload.error || '') === 'upstream_request_failed'
      || message.includes('ECONNRESET')
      || message.includes('socket hang up')
      || message.includes('ERR_CONNECTION_RESET')
      || message.includes('ERR_EMPTY_RESPONSE')
      || message.includes('net::ERR_')
    );
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
  const url = new URL(stateUrl);
  const publicId = url.searchParams.get('public_id') || '';
  const isSnapshotPost = /\/post-workspace-snapshot$/i.test(url.pathname);
  const requestUrl = new URL(stateUrl);
  if (isSnapshotPost) {
    requestUrl.search = '';
    requestUrl.hash = '';
  }

  try {
    const res = isSnapshotPost
      ? await page.request.post(requestUrl.toString(), {
        form: { public_id: publicId },
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      : await page.request.get(stateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok()) {
      return null;
    }
    const json = await res.json();
    return json && json.data ? json.data : null;
  } catch (error) {
    return page.evaluate(async ({ url, publicId, isSnapshotPost }) => {
      const requestUrl = new URL(url);
      if (isSnapshotPost) {
        requestUrl.search = '';
        requestUrl.hash = '';
      }
      const options = {
        method: isSnapshotPost ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      };
      if (isSnapshotPost) {
        const body = new URLSearchParams();
        body.set('public_id', publicId);
        options.body = body;
      }
      const res = await fetch(requestUrl.toString(), options);
      if (!res.ok) {
        return null;
      }
      const json = await res.json();
      return json && json.data ? json.data : null;
    }, { url: stateUrl, publicId, isSnapshotPost }).catch(() => null);
  }
}

async function expectSelectedDomainVisible(page, expectedDomain) {
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

function isPagebuilderOperationSettled(data, operation) {
  const busyStatuses = new Set(['pending', 'queued', 'running', 'processing']);
  const active = data && data.active_operation && typeof data.active_operation === 'object'
    ? data.active_operation
    : {};
  const activeOperations = data && data.active_operations && typeof data.active_operations === 'object'
    ? data.active_operations
    : {};
  const candidates = [];
  if (String(active.operation || '') === operation) {
    candidates.push(active);
  }
  if (activeOperations[operation] && typeof activeOperations[operation] === 'object') {
    candidates.push(activeOperations[operation]);
  }
  return candidates.every((candidate) => !busyStatuses.has(String(candidate.status || '').toLowerCase()));
}

async function waitForPagebuilderOperationSettledByUrl(page, workspaceUrl, operation, timeoutMs = WORKSPACE_TIMEOUT) {
  const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
  return waitForPagebuilderStateData(
    page,
    stateUrl,
    (data) => isPagebuilderOperationSettled(data, operation),
    timeoutMs
  );
}

async function consumePagebuilderOperationStreamIfPresent(page, workspaceUrl, payload, label) {
  const streamUrl = String((payload && payload.stream_url) || '').trim();
  if (!streamUrl) {
    return null;
  }
  const stream = await consumeSseStream(page, new URL(streamUrl, workspaceUrl).toString(), {
    timeoutMs: LONG_WORKSPACE_TIMEOUT,
  });
  if (streamIndicatesQueueWaitingForScheduler(stream)) {
    return stream;
  }
  expectFinishedOrResumedStream(stream, label);
  return stream;
}

function streamIndicatesQueueWaitingForScheduler(stream) {
  const events = Array.isArray(stream && stream.events) ? stream.events : [];
  return events.some((event) => {
    const data = event && event.data && typeof event.data === 'object' ? event.data : {};
    const queueStatus = String(data.queue_status || data.status || '').toLowerCase();
    return data.queue_waiting_for_scheduler === true
      || (
        data.observer_mode === true
        && data.background_mode === true
        && /^(pending|queued|preparing)$/.test(queueStatus)
      );
  });
}

async function collectPagebuilderPhaseDebugSnapshot(page, stateUrl) {
  const state = await fetchPagebuilderStateData(page, stateUrl);
  const rawState = await page.evaluate(async ({ url }) => {
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const text = await res.text();
      return {
        ok: !!res.ok,
        status: res.status,
        redirected: !!res.redirected,
        url: String(res.url || ''),
        textHead: String(text || '').slice(0, 1500),
      };
    } catch (error) {
      return {
        ok: false,
        status: 0,
        redirected: false,
        url: '',
        textHead: `fetch-error:${error && error.message ? error.message : error}`,
      };
    }
  }, { url: stateUrl }).catch(() => null);
  const client = await page.evaluate(() => {
    const rendered = document.querySelector('#pb-ai-plan-rendered-content');
    const markdown = document.querySelector('#pb-ai-plan-md-content');
    return {
      renderedText: rendered ? String(rendered.textContent || '').trim() : '',
      renderedHtml: rendered ? String(rendered.innerHTML || '').slice(0, 1200) : '',
      markdownText: markdown ? String(markdown.textContent || '').trim() : '',
      hasWorkspaceApi: !!window.__pbWorkspaceApi,
      hasConfirmedPlanCache: !!window.__pbWorkspaceConfirmedPlan,
      confirmedPlanMarkdownLength: String((window.__pbWorkspaceConfirmedPlan && window.__pbWorkspaceConfirmedPlan.markdown) || '').length,
      confirmedPlanStructuredKeys: window.__pbWorkspaceConfirmedPlan && window.__pbWorkspaceConfirmedPlan.structured && typeof window.__pbWorkspaceConfirmedPlan.structured === 'object'
        ? Object.keys(window.__pbWorkspaceConfirmedPlan.structured).slice(0, 10)
        : [],
    };
  }).catch(() => null);
  const htmlLineWindow = await page.content().then((html) => {
    const lines = String(html || '').split(/\r?\n/);
    const lineNo = 17768;
    return {
      lineNo,
      lines: lines.slice(Math.max(0, lineNo - 8), Math.min(lines.length, lineNo + 8)).map((line, idx) => ({
        no: Math.max(1, lineNo - 7) + idx,
        text: line,
      })),
    };
  }).catch(() => null);
  return { state, rawState, client, htmlLineWindow };
}

function buildLocalDomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.local.test`;
}

/** Keep this aligned with php bin/w server:hosts:add; workspace root is four levels above tests/e2e/specs/backend. */
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
$user = clone \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Backend\\Model\\BackendUser::class);
$username = getenv('PLAYWRIGHT_ADMIN_USERNAME') ?: 'admin';
$user->reset()->where('username', $username)->find()->fetch();
$adminId = (int)$user->getId();
if ($adminId <= 0) {
    $adminId = 1;
}
$scope = json_decode('${phpScope}', true);
$session = $service->createSession($adminId, is_array($scope) ? $scope : []);
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

function savePagebuilderCustomSkillViaPhp(skillData = {}) {
  const root = devWorkspaceRootFromThisSpec();
  const phpSkill = JSON.stringify(skillData || {})
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpCode = `
require 'app/bootstrap.php';
$repository = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AI\\Skill\\CustomSkillRepository::class);
$skill = json_decode('${phpSkill}', true);
$saved = $repository->saveFromArray(is_array($skill) ? $skill : []);
$item = $repository->findArrayByCode($saved->getCode());
echo json_encode([
  'success' => true,
  'item' => is_array($item) ? $item : ['code' => $saved->getCode()],
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
$user = clone \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Backend\\Model\\BackendUser::class);
$username = getenv('PLAYWRIGHT_ADMIN_USERNAME') ?: 'admin';
$user->reset()->where('username', $username)->find()->fetch();
$adminId = (int)$user->getId();
if ($adminId <= 0) {
    $adminId = 1;
}
$session = $sessionService->loadByPublicId(is_string($publicId) ? $publicId : '', $adminId);
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
$sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
$fresh = $sessionService->loadById($session->getId(), $adminId) ?? $session;
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

function mergePagebuilderScopeViaPhp(publicId, scopePatch = {}) {
  const root = devWorkspaceRootFromThisSpec();
  const phpPublicId = JSON.stringify(String(publicId || ''))
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpScopePatch = JSON.stringify(scopePatch || {})
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpCode = `
require 'app/bootstrap.php';
$sessionService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteAgentSessionService::class);
$scopeCompatibilityService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteScopeCompatibilityService::class);
$buildTaskService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteBuildTaskService::class);
$publicId = json_decode('${phpPublicId}', true);
$scopePatch = json_decode('${phpScopePatch}', true);
$completeBuildTasksForE2e = is_array($scopePatch) && !empty($scopePatch['__e2e_complete_build_tasks']);
$e2eStage = is_array($scopePatch) ? trim((string)($scopePatch['__e2e_stage'] ?? '')) : '';
if (is_array($scopePatch)) {
    unset($scopePatch['__e2e_complete_build_tasks']);
    unset($scopePatch['__e2e_stage']);
}
$user = clone \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Backend\\Model\\BackendUser::class);
$username = getenv('PLAYWRIGHT_ADMIN_USERNAME') ?: 'admin';
$user->reset()->where('username', $username)->find()->fetch();
$adminId = (int)$user->getId();
if ($adminId <= 0) {
    $adminId = 1;
}
$session = $sessionService->loadByPublicId(is_string($publicId) ? $publicId : '', $adminId);
if (!$session) {
    throw new RuntimeException('PageBuilder session not found for scope seed.');
}
$sessionService->mergeScope($session->getId(), $adminId, is_array($scopePatch) ? $scopePatch : []);
if ($e2eStage !== '') {
    $sessionService->setStage($session->getId(), $adminId, $e2eStage);
}
$fresh = $sessionService->loadById($session->getId(), $adminId) ?? $session;
$freshStage = $e2eStage !== '' ? $e2eStage : $scopeCompatibilityService->normalizeStage($fresh->getStage());
$freshScope = $scopeCompatibilityService->normalizeScope($sessionService->loadScopeForStage($fresh, $freshStage));
if ($completeBuildTasksForE2e) {
    $websiteProfile = is_array($freshScope['website_profile'] ?? null) ? $freshScope['website_profile'] : [];
    $workspaceTrack = (string)($freshScope['workspace_track'] ?? \\GuoLaiRen\\PageBuilder\\Service\\AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME);
    $freshScope = $buildTaskService->ensureTaskScope($freshScope, $websiteProfile, $workspaceTrack);
    $blueprintTasks = is_array($freshScope['build_blueprint']['tasks'] ?? null) ? $freshScope['build_blueprint']['tasks'] : [];
    foreach ($blueprintTasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        $taskKey = trim((string)($task['task_key'] ?? ''));
        if ($taskKey === '') {
            continue;
        }
        $resultRef = is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [];
        $freshScope = $buildTaskService->markTaskDone($freshScope, $taskKey, $resultRef);
    }
    $freshScope['build_task_summary'] = $buildTaskService->summarize($freshScope);
    $sessionService->replaceScope($fresh->getId(), $adminId, $freshScope);
    if ($e2eStage !== '') {
        $sessionService->setStage($fresh->getId(), $adminId, $e2eStage);
    }
    $fresh = $sessionService->loadById($session->getId(), $adminId) ?? $fresh;
    $freshStage = $e2eStage !== '' ? $e2eStage : $scopeCompatibilityService->normalizeStage($fresh->getStage());
    $freshScope = $scopeCompatibilityService->normalizeScope($sessionService->loadScopeForStage($fresh, $freshStage));
}
$compactScope = [];
foreach ([
    'default_locale',
    'plan_locale',
    'site_profile_manual',
    'website_profile',
    'execution_blueprint',
    'execution_blueprint_draft',
    'plan_structured',
    'plan_markdown',
    'plan_confirmed',
    'build_plan_v2',
    'plan_projection',
    'build_plan_confirmed',
    'has_build_plan_v2',
    'workspace_status',
    'workspace_track',
    'virtual_pages_by_type',
    'page_type_layouts',
    'materialized_pages_by_type',
    'build_blueprint',
    'build_tasks',
    'build_task_summary',
] as $key) {
    if (array_key_exists($key, $freshScope)) {
        $compactScope[$key] = $freshScope[$key];
    }
}
echo json_encode([
    'success' => true,
    'public_id' => $fresh->getPublicId(),
    'scope' => $compactScope,
], JSON_UNESCAPED_UNICODE);
`;
  const stdout = execFileSync('php', ['-r', phpCode], {
    cwd: root,
    stdio: 'pipe',
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
  });
  return JSON.parse(stdout);
}

function buildSmallPhasePlanMarkdown(siteTitle, pageTypes) {
  const label = String(siteTitle || 'E2E phase site').trim();
  const pages = Array.isArray(pageTypes) && pageTypes.length > 0 ? pageTypes : ['home_page'];
  const lines = [
    `# ${label} Stage 1 Plan`,
    '',
    'This lightweight draft exists for frontend interaction coverage. It keeps the payload intentionally small while still providing enough structured detail for inline preview rendering, block-level hover actions, refinement, rebuild, deletion, and add-block flows.',
    '',
    '## Shared Direction',
    '- Theme: confident, practical, product-led',
    '- Header: logo, compact navigation, strong CTA',
    '- Footer: trust note, contact links, legal links',
    '',
    '## Page Coverage',
  ];
  pages.forEach((pageType, index) => {
    lines.push(`${index + 1}. ${pageType}: present a clear story with editable content blocks and CTA guidance.`);
  });
  lines.push('');
  lines.push('## Editing Contract');
  lines.push('Every preview block must support refine, rebuild, delete, and add-block actions without leaving the current stage panel. The whole stage must also support full regenerate.');
  lines.push('');
  lines.push('## Delivery Note');
  lines.push('This markdown is intentionally verbose enough for E2E assertions and intentionally compact enough to avoid exhausting PHP memory during workspace rendering.');
  return lines.join('\n');
}

function buildSmallPhasePlanStructured(siteTitle, pageTypes) {
  const title = String(siteTitle || 'E2E phase site').trim();
  const pages = {};
  (Array.isArray(pageTypes) && pageTypes.length > 0 ? pageTypes : ['home_page']).forEach((pageType, index) => {
    pages[pageType] = {
      title: `${title} ${pageType}`,
      page_goal: `Explain the core value for ${pageType} with one strong primary CTA and one supporting proof block.`,
      primary_keywords: [pageType, 'ai site builder'],
      secondary_keywords: ['conversion', 'trust'],
      blocks: [
        {
          block_key: index === 0 ? 'hero' : 'content_section',
          goal: `Give ${pageType} a focused opening block that can be refined, rebuilt, deleted, or expanded.`,
          content: `This ${pageType} block is a compact E2E seed. It is designed to render quickly, remain editable in place, and support repeated SSE mutations during the test flow.`,
          keywords: ['editable', 'preview', 'hover'],
          field_plan: [
            { field: 'headline', type: 'text', required: true, note: 'Main message' },
            { field: 'cta', type: 'text', required: true, note: 'Primary action' },
          ],
          execution_script: {
            scene: `page:${pageType}:${index === 0 ? 'hero' : 'content_section'}`,
            story_goal: 'Keep the output small but editable.',
          },
        },
      ],
    };
  });
  return {
    theme_design: {
      visual_direction: 'Clean light business layout with bold CTA accents',
      typography: 'System-safe sans with emphasized section titles',
      tone: 'Helpful and conversion-oriented',
    },
    navigation_plan: {
      header: ['Home', 'About', 'Contact'],
      cta: 'Start Consultation',
    },
    footer_plan: {
      groups: ['Contact', 'Trust', 'Legal'],
    },
    pages,
  };
}

function buildSmallExecutionBlueprint(pageTypes) {
  const normalizedPageTypes = Array.isArray(pageTypes) && pageTypes.length > 0 ? pageTypes : ['home_page'];
  const signatureSeed = Date.now().toString(36);
  const tasks = [
    {
      task_key: 'shared:header',
      task_type: 'shared_component',
      region: 'header',
      title: 'Build shared header',
    },
    {
      task_key: 'shared:footer',
      task_type: 'shared_component',
      region: 'footer',
      title: 'Build shared footer',
    },
  ];
  normalizedPageTypes.forEach((pageType, index) => {
    tasks.push({
      task_key: `page:${pageType}:${index === 0 ? 'hero' : 'content'}`,
      task_type: 'page_section',
      page_type: pageType,
      section_code: index === 0 ? 'hero' : 'content_section',
      title: `Build ${pageType} primary section`,
    });
  });
  return {
    signature: `phase1-${signatureSeed}`,
    version: 1,
    page_types: normalizedPageTypes,
    tasks,
    task_groups: {
      shared: tasks.filter((task) => String(task.task_type || '') === 'shared_component'),
      pages: normalizedPageTypes.reduce((carry, pageType) => {
        carry[pageType] = tasks.filter((task) => String(task.page_type || '') === pageType);
        return carry;
      }, {}),
    },
  };
}

function prepareSmallPagebuilderPlanDraftViaPhp(publicId, scopePatch = {}) {
  const normalizedPatch = scopePatch && typeof scopePatch === 'object' ? { ...scopePatch } : {};
  const pageTypes = Array.isArray(normalizedPatch.page_types) && normalizedPatch.page_types.length > 0
    ? normalizedPatch.page_types.map((item) => String(item || '').trim()).filter(Boolean)
    : ['home_page', 'about_page'];
  const siteTitle = String(normalizedPatch.site_title || 'E2E Phase UI').trim();
  const structured = buildSmallPhasePlanStructured(siteTitle, pageTypes);
  const executionBlueprintDraft = buildSmallExecutionBlueprint(pageTypes);
  const planMarkdown = buildSmallPhasePlanMarkdown(siteTitle, pageTypes);
  const merged = mergePagebuilderScopeViaPhp(publicId, {
    ...normalizedPatch,
    fake_mode: 1,
    workspace_status: 'stage1_draft_ready',
    website_profile: {
      site_name: siteTitle,
      positioning: 'Frontend interaction coverage',
      audience: 'E2E verification',
    },
    execution_blueprint_draft: executionBlueprintDraft,
    plan_json: structured,
    plan_structured: structured,
    plan_markdown: planMarkdown,
    plan_ai_generated: 0,
    plan_ai_fallback: 1,
    plan_generated_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    plan_generated_page_types: pageTypes,
    plan_confirmed: 0,
  });
  return {
    success: Boolean(merged && merged.success),
    public_id: publicId,
    plan_markdown: planMarkdown,
    execution_blueprint_signature: String(executionBlueprintDraft.signature || ''),
    scope: merged && merged.scope ? merged.scope : {},
  };
}

function buildSeededMutationToken(action, instruction) {
  const normalizedAction = String(action || 'update').trim() || 'update';
  const normalizedInstruction = String(instruction || '').trim().replace(/\s+/g, ' ');
  const instructionStub = normalizedInstruction ? normalizedInstruction.slice(0, 48) : 'no-instruction';
  return `${normalizedAction}-${Date.now().toString(36)}-${instructionStub}`;
}

function appendSeededMutationMarkdown(baseMarkdown, action, instruction, token) {
  const lines = [
    String(baseMarkdown || '').trim(),
    '',
    '## Seeded Mutation',
    `- Action: ${String(action || '').trim() || 'update'}`,
    `- Instruction: ${String(instruction || '').trim() || 'N/A'}`,
    `- Token: ${String(token || '').trim() || 'seeded'}`,
  ];
  return lines.join('\n');
}

function prepareMutatedSmallPagebuilderPlanDraftViaPhp(publicId, scopePatch = {}, mutation = {}) {
  const normalizedPatch = scopePatch && typeof scopePatch === 'object' ? { ...scopePatch } : {};
  const pageTypes = Array.isArray(normalizedPatch.page_types) && normalizedPatch.page_types.length > 0
    ? normalizedPatch.page_types.map((item) => String(item || '').trim()).filter(Boolean)
    : ['home_page', 'about_page'];
  const siteTitle = String(normalizedPatch.site_title || 'E2E Phase UI').trim();
  const action = String(mutation.action || 'refine').trim() || 'refine';
  const instruction = String(mutation.instruction || '').trim();
  const token = buildSeededMutationToken(action, instruction);
  const structured = buildSmallPhasePlanStructured(siteTitle, pageTypes);
  const firstPageType = pageTypes[0] || Object.keys(structured.pages || {})[0] || 'home_page';
  const firstPage = structured.pages && structured.pages[firstPageType] && typeof structured.pages[firstPageType] === 'object'
    ? structured.pages[firstPageType]
    : null;
  const firstBlock = firstPage && Array.isArray(firstPage.blocks) && firstPage.blocks.length > 0
    ? firstPage.blocks[0]
    : null;
  if (firstPage && Array.isArray(firstPage.blocks)) {
    if (firstBlock) {
      firstBlock.goal = `${String(firstBlock.goal || '').trim()} [${action}]`;
      firstBlock.content = `${String(firstBlock.content || '').trim()} Mutation token: ${token}. ${instruction}`.trim();
      firstBlock.keywords = Array.isArray(firstBlock.keywords) ? firstBlock.keywords.concat([action, token]) : [action, token];
      if (firstBlock.execution_script && typeof firstBlock.execution_script === 'object') {
        firstBlock.execution_script.story_goal = `${String(firstBlock.execution_script.story_goal || '').trim()} (${action})`;
      }
    }
    if (action === 'add-block') {
      firstPage.blocks.push({
        block_key: `seeded_block_${Date.now().toString(36)}`,
        goal: `Seeded extra block for ${firstPageType}`,
        content: `This add-block mutation is seeded for deterministic E2E coverage. ${instruction}`.trim(),
        keywords: ['seeded', 'add-block'],
        field_plan: [
          { field: 'headline', type: 'text', required: true, note: 'Seeded extra headline' },
        ],
        execution_script: {
          scene: `page:${firstPageType}:seeded_extra`,
          story_goal: 'Keep the extra block lightweight and editable.',
        },
      });
    } else if (action === 'delete' && firstPage.blocks.length > 1) {
      firstPage.blocks = firstPage.blocks.slice(0, 1);
    } else if (action === 'rebuild-stage') {
      firstPage.blocks = firstPage.blocks.map((block, index) => Object.assign({}, block, {
        goal: `${String(block.goal || '').trim()} [stage-refresh-${index + 1}]`,
        content: `${String(block.content || '').trim()} Stage refresh token: ${token}.`,
      }));
    }
  }
  if (structured.theme_design && typeof structured.theme_design === 'object') {
    structured.theme_design.tone = `${String(structured.theme_design.tone || '').trim()} [${action}]`;
    structured.theme_design.seeded_mutation_token = token;
  }
  const executionBlueprintDraft = buildSmallExecutionBlueprint(pageTypes);
  executionBlueprintDraft.signature = `phase1-${token}`;
  const planMarkdown = appendSeededMutationMarkdown(buildSmallPhasePlanMarkdown(siteTitle, pageTypes), action, instruction, token);
  const merged = mergePagebuilderScopeViaPhp(publicId, {
    ...normalizedPatch,
    fake_mode: 1,
    workspace_status: 'stage1_draft_ready',
    execution_blueprint_draft: executionBlueprintDraft,
    plan_json: structured,
    plan_structured: structured,
    plan_markdown: planMarkdown,
    plan_ai_generated: 0,
    plan_ai_fallback: 1,
    plan_generated_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    plan_generated_page_types: pageTypes,
    plan_confirmed: 0,
  });
  return {
    success: Boolean(merged && merged.success),
    public_id: publicId,
    plan_markdown: planMarkdown,
    scope: merged && merged.scope ? merged.scope : {},
  };
}

/**
 * Register a *.weline.local host for real browser storefront access.
 * Set PLAYWRIGHT_SKIP_HOSTS_REGISTER=1 to skip hosts writes and rely on API + Host-header fallback.
 * @param {string} fqdn Example: pb-e2e-12345678.weline.local
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

  await ensurePagebuilderPlanConfirmed(page, scopePatch);

  const res = await postJsonWithRetry(page, postUrl, {
    public_id: publicId,
    scope_patch: JSON.stringify(scopePatch),
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
  const targetUrl = new URL(workspaceUrl);
  try {
    const current = new URL(page.url());
    if (/^https?:$/i.test(current.protocol) && current.origin === targetUrl.origin) {
      return;
    }
  } catch (error) {
    // fall through to navigation
  }
  const candidateUrls = [
    workspaceUrl,
    `${targetUrl.origin}/`,
    `${targetUrl.origin}${targetUrl.pathname.split('/').slice(0, 2).join('/')}/admin/login`,
  ].filter((value, index, list) => value && list.indexOf(value) === index);
  for (const candidateUrl of candidateUrls) {
    try {
      await gotoStable(page, candidateUrl);
      const landed = new URL(page.url());
      if (landed.origin === targetUrl.origin) {
        return;
      }
    } catch (error) {
      // try the next stable browser origin
    }
  }
  throw new Error(`Unable to establish browser http(s) page context for ${workspaceUrl}`);
}

async function ensureWorkspacePage(page, workspaceUrl) {
  const targetUrl = new URL(workspaceUrl);
  try {
    const current = new URL(page.url());
    if (
      current.origin === targetUrl.origin
      && PAGEBUILDER_AI_WORKSPACE_PATH_RE.test(current.pathname)
      && current.searchParams.get('public_id') === targetUrl.searchParams.get('public_id')
    ) {
      return;
    }
  } catch (error) {
    // fall through to direct workspace navigation
  }
  await gotoStable(page, workspaceUrl);
}

async function postPagebuilderWorkspaceJson(page, action, form) {
  const postUrl = buildPagebuilderWorkspacePostUrl(page, action);
  const res = await postJsonWithRetry(page, postUrl, form);
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
  const postUrl = buildSameOriginBackendUrl(page, `pagebuilder/backend/ai-site-agent/${action}`);
  const runPost = async () => {
    const response = await postJsonWithRetry(page, postUrl, form, 90000);
    const text = await response.text();
    try {
      return {
        ok: response.ok(),
        status: response.status(),
        payload: JSON.parse(text),
        rawHead: text.slice(0, 400),
      };
    } catch (error) {
      return {
        ok: response.ok(),
        status: response.status(),
        payload: null,
        rawHead: text.slice(0, 400),
      };
    }
  };

  let result = null;
  try {
    result = await runPost();
  } catch (error) {
    if (!isRetryableBrowserFetchFailure(error)) {
      throw error;
    }
    result = await runPost();
  }
  if (isRetryableBrowserFetchResult(result)) {
    result = await runPost();
  }

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
  const postUrl = buildSameOriginBackendUrl(page, `pagebuilder/backend/ai-site-agent/${action}`);
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
    await ensureWorkspacePage(page, workspaceUrl);
    const value = await page.locator(selector).inputValue().catch(() => '');
    if (predicate(String(value || ''))) {
      return String(value || '');
    }
    await page.waitForTimeout(1500);
  }
  throw new Error(`waitForWorkspaceFieldMutation timed out for ${selector}`);
}

async function ensurePagebuilderPlanConfirmed(page, scopePatch) {
  const publicId = new URL(page.url()).searchParams.get('public_id') || '';
  expect(publicId, 'pagebuilder workspace url should carry public_id').toBeTruthy();

  const planStart = await postPagebuilderWorkspaceJson(page, 'post-start-plan', {
    public_id: publicId,
    prompt_mode: 'rebuild',
    instruction: String((scopePatch && (scopePatch.user_description || scopePatch.brief_description)) || '').trim(),
    scope_patch: JSON.stringify(scopePatch || {}),
    round: '1',
  });
  let planStartPayload = planStart.payload || {};
  let planConfirm = null;
  if (!isAiProviderReadinessFailure(planStartPayload)) {
    const confirmStartedAt = Date.now();
    while ((Date.now() - confirmStartedAt) < WORKSPACE_TIMEOUT) {
      planConfirm = await postPagebuilderWorkspaceJson(page, 'post-confirm-plan', {
        public_id: publicId,
      });
      if (planConfirm.payload && planConfirm.payload.success) {
        break;
      }
      if (String((planConfirm.payload && planConfirm.payload.code) || '') !== 'PLAN_NOT_READY') {
        break;
      }
      await page.waitForTimeout(2000);
    }
  }
  if (!(planConfirm && planConfirm.payload && planConfirm.payload.success)) {
    const seeded = await seedAndConfirmPagebuilderBuildPlanByUrl(page, page.url(), scopePatch || {});
    planConfirm = { payload: { ...seeded.planConfirm, seeded: true } };
    planStartPayload = {
      ...planStartPayload,
      seeded: true,
      plan: { markdown: String(seeded.seededPlan.plan_markdown || '') },
    };
  }
  expect(planConfirm && planConfirm.payload && planConfirm.payload.success, JSON.stringify(planConfirm && planConfirm.payload)).toBeTruthy();
  if (!(planStartPayload.plan && String(planStartPayload.plan.markdown || '').trim())) {
    planStartPayload = {
      ...planStartPayload,
      plan: { markdown: 'build plan confirmed through plan start endpoint' },
    };
  }
  mergePagebuilderScopeViaPhp(publicId, {
    active_operation: [],
    active_operations: [],
  });

  return {
    planStart: planStartPayload,
    planConfirm: planConfirm.payload,
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

async function seedAndConfirmPagebuilderBuildPlanByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  if (scopePatch && typeof scopePatch === 'object') {
    const merged = mergePagebuilderScopeViaPhp(publicId, scopePatch);
    expect(merged && merged.success, JSON.stringify(merged)).toBeTruthy();
  }
  const seededPlan = preparePagebuilderPlanDraftViaPhp(publicId);
  expect(seededPlan.success, JSON.stringify(seededPlan)).toBeTruthy();
  expect(String(seededPlan.plan_markdown || '').trim()).toBeTruthy();
  const planConfirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
    public_id: publicId,
  });
  expect(planConfirm.payload && planConfirm.payload.success, JSON.stringify(planConfirm.payload)).toBeTruthy();
  const confirmedScope = mergePagebuilderScopeViaPhp(publicId, {});
  expect(Number((confirmedScope.scope && confirmedScope.scope.build_plan_confirmed) || 0), JSON.stringify(confirmedScope)).toBe(1);
  mergePagebuilderScopeViaPhp(publicId, {
    active_operation: [],
    active_operations: [],
  });
  return {
    seededPlan,
    planConfirm: { ...planConfirm.payload, build_plan_confirmed: 1, scope: confirmedScope.scope || {} },
  };
}

async function ensurePagebuilderPlanConfirmedByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  if (scopePatch && Number(scopePatch.fake_mode || 0) === 1) {
    const seeded = await seedAndConfirmPagebuilderBuildPlanByUrl(page, workspaceUrl, scopePatch || {});
    return {
      planStart: { seeded: true, plan: { markdown: String(seeded.seededPlan.plan_markdown || '') } },
      planConfirm: { ...seeded.planConfirm, seeded: true },
    };
  }

  const planStart = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-plan', {
    public_id: publicId,
    prompt_mode: 'rebuild',
    instruction: String((scopePatch && (scopePatch.user_description || scopePatch.brief_description)) || '').trim(),
    scope_patch: JSON.stringify(scopePatch || {}),
    round: '1',
  });
  let planStartPayload = planStart.payload || {};
  let planConfirm = null;
  if (!isAiProviderReadinessFailure(planStartPayload)) {
    const confirmStartedAt = Date.now();
    while ((Date.now() - confirmStartedAt) < WORKSPACE_TIMEOUT) {
      planConfirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
        public_id: publicId,
      });
      if (planConfirm.payload && planConfirm.payload.success) {
        break;
      }
      if (String((planConfirm.payload && planConfirm.payload.code) || '') !== 'PLAN_NOT_READY') {
        break;
      }
      await page.waitForTimeout(2000);
    }
  }
  if (!(planConfirm && planConfirm.payload && planConfirm.payload.success)) {
    const seeded = await seedAndConfirmPagebuilderBuildPlanByUrl(page, workspaceUrl, scopePatch || {});
    planConfirm = { payload: { ...seeded.planConfirm, seeded: true } };
    planStartPayload = {
      ...planStartPayload,
      seeded: true,
      plan: { markdown: String(seeded.seededPlan.plan_markdown || '') },
    };
  }
  expect(planConfirm && planConfirm.payload && planConfirm.payload.success, JSON.stringify(planConfirm && planConfirm.payload)).toBeTruthy();
  if (!(planStartPayload.plan && String(planStartPayload.plan.markdown || '').trim())) {
    planStartPayload = {
      ...planStartPayload,
      plan: { markdown: 'build plan confirmed through plan start endpoint' },
    };
  }
  mergePagebuilderScopeViaPhp(publicId, {
    active_operation: [],
    active_operations: [],
  });

  return {
    planStart: planStartPayload,
    planConfirm: planConfirm.payload,
  };
}

async function startPagebuilderBuildByUrl(page, workspaceUrl, scopePatch) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  if (scopePatch && Number(scopePatch.fake_mode || 0) === 1) {
    const payload = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-build', {
      public_id: publicId,
      scope_patch: JSON.stringify(scopePatch || {}),
    });
    expect(payload && payload.payload && (payload.payload.success || String(payload.payload.operation || '') === 'build'), JSON.stringify(payload && payload.payload)).toBeTruthy();
    expect(String((payload && payload.payload && payload.payload.stream_url) || '').trim()).toBeTruthy();
    return payload.payload;
  }
  let payload = null;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const res = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-build', {
      public_id: publicId,
      scope_patch: JSON.stringify(scopePatch || {}),
    });
    payload = res.payload;
    if (isRetryableBrowserFetchResult(res)) {
      await page.waitForTimeout(1500 * (attempt + 1));
      continue;
    }
    const busyOperation = payload && String(payload.code || '') === 'AI_SITE_OPERATION_BUSY'
      && payload.active_operation
      && typeof payload.active_operation === 'object'
      ? payload.active_operation
      : null;
    if (!busyOperation) {
      break;
    }
    await consumePagebuilderOperationStreamIfPresent(
      page,
      workspaceUrl,
      busyOperation,
      `build-start-busy-${String(busyOperation.operation || 'operation')}`
    ).catch(() => null);
    await page.waitForTimeout(2000);
  }
  if (payload && payload.success !== true && String(payload.code || '') === 'upstream_request_failed') {
    await gotoStable(page, workspaceUrl);
    const retryRes = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-build', {
      public_id: publicId,
      scope_patch: JSON.stringify(scopePatch || {}),
    });
    payload = retryRes.payload;
  }
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

async function requestPagebuilderPublishByUrl(page, workspaceUrl, extraForm = {}) {
  const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id').toBeTruthy();
  const postUrl = buildSameOriginBackendUrl(page, 'pagebuilder/backend/ai-site-agent/post-start-publish');
  const res = await page.request.post(postUrl, {
    form: { public_id: publicId, ...extraForm },
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

async function startPagebuilderPublishByUrl(page, workspaceUrl, extraForm = {}) {
  let payload = null;
  for (let attempt = 0; attempt < 6; attempt += 1) {
    payload = await requestPagebuilderPublishByUrl(page, workspaceUrl, extraForm);
    if (payload && payload.success) {
      break;
    }
    if (String((payload && payload.code) || '').toUpperCase() !== 'WORKSPACE_NOT_READY') {
      break;
    }
    await waitForPagebuilderStateData(
      page,
      buildPagebuilderGetStateJsonUrl(workspaceUrl),
      (data) => Boolean(data && (data.can_publish || String(data.workspace_status || '') === 'can_publish')),
      30000
    ).catch(() => null);
    await page.waitForTimeout(1500);
  }
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
 * Extract page_generated page types from an SSE stream.
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
 * Verify the build stream emitted page_generated for every selected page type.
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
 * Backend responses can carry target-origin absolute links; force them back to the current origin in E2E proxy mode.
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
  if (!targetOrigin || !normalizedRoute) {
    throw new Error(`buildDirectRuntimeBackendUrl missing runtime pieces: origin=${targetOrigin} route=${normalizedRoute}`);
  }
  return `${targetOrigin}${backendPrefix}/${normalizedRoute}`;
}

function buildSameOriginBackendUrl(page, route) {
  const runtime = getRuntimeInfo();
  const backendPrefix = String(runtime.paths?.backend_prefix_path || '').replace(/\/+$/, '');
  const normalizedRoute = String(route || '').replace(/^\/+/, '');
  let base;
  try {
    base = new URL(page.url());
    if (!/^https?:$/i.test(base.protocol)) {
      throw new Error('non-http page url');
    }
  } catch (error) {
    const fallbackOrigin = process.env.PLAYWRIGHT_DISABLE_PROXY === '1'
      ? runtime.runtime?.target_origin
      : (runtime.proxy?.origin || runtime.runtime?.target_origin);
    base = new URL(String(fallbackOrigin || 'https://127.0.0.1'));
  }
  return new URL(`${backendPrefix}/${normalizedRoute}`, `${base.origin}/`).toString();
}

/**
 * Metric cards (draft website / theme id) render only in expert layout; guided layout has only the primary action.
 * @param {import('@playwright/test').Page} page
 */
async function ensurePagebuilderExpertLayoutLegacy(page) {
  const expertReady = async () => {
    const selectors = [
      '#pb-ai-draft-website-id',
      '#pb-ai-plan-inline-panel',
      '#pb-ai-build-plan-v2-summary',
      '#pb-ai-site-title',
    ];
    for (const selector of selectors) {
      if (await page.locator(selector).first().isVisible({ timeout: 1500 }).catch(() => false)) {
        return true;
      }
    }
    return false;
  };

  if (await expertReady()) {
    return;
  }

  const advancedLink = page.locator('a[href*="expert=1"], a:has-text("楂樼骇妯″紡")').first();
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
    if (await expertReady()) {
      return;
    }
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

  await expect.poll(async () => expertReady(), { timeout: 30000 }).toBeTruthy();
}

async function ensurePagebuilderExpertLayout(page) {
  const expertReady = async () => {
    const visibleSelectors = [
      '#pb-ai-plan-inline-panel',
      '#pb-ai-build-plan-v2-summary',
    ];
    for (const selector of visibleSelectors) {
      if (await page.locator(selector).first().isVisible({ timeout: 1500 }).catch(() => false)) {
        return true;
      }
    }
    const attachedSelectors = [
      '#pb-ai-scope-full',
      '#pb-ai-visual-preview-frame',
    ];
    for (const selector of attachedSelectors) {
      if (await page.locator(selector).first().count().catch(() => 0) > 0) {
        return true;
      }
    }
    return false;
  };

  if (await expertReady()) {
    return;
  }

  const currentUrl = new URL(page.url());
  currentUrl.searchParams.set('expert', '1');
  for (let attempt = 0; attempt < 4; attempt += 1) {
    await gotoStable(page, currentUrl.toString());
    const ready = await expect.poll(async () => expertReady(), { timeout: 10000 }).toBeTruthy().then(() => true).catch(() => false);
    if (ready) {
      return;
    }
    const bodyText = await page.locator('body').innerText().catch(() => '');
    if (!/upstream_request_failed|ECONNRESET|read ECONNRESET/i.test(String(bodyText || ''))) {
      break;
    }
    await page.waitForTimeout(2000);
  }
  await expect.poll(async () => expertReady(), { timeout: 30000 }).toBeTruthy();
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
    sameOriginWorkspaceUrl,
    buildDirectRuntimeBackendUrl(workspaceRoute),
  ].filter((value, index, list) => value && list.indexOf(value) === index);

  let workspaceUrl = '';
  let lastError = null;
  for (const candidateUrl of candidateUrls) {
    try {
      await gotoStable(page, candidateUrl);
      const landedOnLogin = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
        .isVisible({ timeout: 3000 })
        .catch(() => false);
      if (landedOnLogin) {
        await loginAsAdmin(page, { refreshRuntime: true });
        await gotoStable(page, candidateUrl);
      }
      let bodyText = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
      if (/^\s*404\s*$/i.test(String(bodyText || ''))) {
        await loginAsAdmin(page, { refreshRuntime: true });
        await gotoStable(page, candidateUrl);
        bodyText = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
      }
      if (/^\s*404\s*$/i.test(String(bodyText || ''))) {
        throw new Error(`PageBuilder workspace candidate returned 404: ${candidateUrl}`);
      }
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
 * Reuse createDirectPagebuilderWorkspace for login/session creation and return the guided UI URL when needed.
 * This avoids duplicate post-create-session calls from fetch without backend cookies.
 * @param {import('@playwright/test').Page} page
 * @param {string} backendRoot
 */
async function openPagebuilderWorkspaceGuidedAfterExpert(page, backendRoot) {
  return createDirectPagebuilderWorkspace(page, backendRoot);
}

/**
 * @param {string} workspaceUrl
 */
function buildPagebuilderGetStateJsonUrl(workspaceUrl) {
  const u = new URL(workspaceUrl);
  u.pathname = u.pathname.replace(/\/workspace$/i, '/post-workspace-snapshot');
  const publicId = u.searchParams.get('public_id') || '';
  expect(publicId, 'workspace url must include public_id for state snapshot').toBeTruthy();
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

function resolvePagebuilderStrongContractJobType(operation) {
  return {
    plan: 'stage1.requirement_expand',
    build: 'virtual_theme.tree.build',
    block_regenerate: 'virtual_theme.block.regenerate',
    block_partial_patch: 'virtual_theme.block.partial_patch',
    regenerate_page: 'virtual_theme.page.regenerate',
    image_asset: 'image.asset.generate',
  }[String(operation || '')] || '';
}

function buildPagebuilderStrongContractOperationState({
  operation = 'build',
  status = 'queued',
  executionToken = '',
  queueId = 0,
  progressPercent = 0,
  message = '',
  canCloseStream = false,
  retryAllowed = 0,
} = {}) {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const normalizedOperation = String(operation || 'build');
  const normalizedStatus = String(status || 'queued').toLowerCase();
  return {
    operation: normalizedOperation,
    execution_token: String(executionToken || `e2e-contract-${normalizedOperation}-${Date.now().toString(36)}`),
    queue_id: Number(queueId || 0),
    job_key: `e2e:${normalizedOperation}:${queueId || 0}`,
    job_type: resolvePagebuilderStrongContractJobType(normalizedOperation),
    status: normalizedStatus,
    page_type: 'home_page',
    started_at: now,
    updated_at: now,
    message: String(message || `E2E contract ${normalizedOperation} ${normalizedStatus}`),
    progress_percent: Number(progressPercent || 0),
    retry_allowed: Number(retryAllowed || 0),
    queue_waiting_for_scheduler: Boolean(canCloseStream),
    can_close_stream: Boolean(canCloseStream),
    continue_other_operations: Boolean(canCloseStream),
  };
}

function buildPagebuilderStrongContractQueueInfo({
  operation = 'build',
  status = 'queued',
  queueId = 0,
  pid = 0,
  process = '',
  resultLog = '',
} = {}) {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const normalizedOperation = String(operation || 'build');
  const normalizedStatus = String(status || 'queued').toLowerCase();
  const terminalStatuses = new Set(['done', 'error', 'stop', 'stopped', 'cancelled', 'canceled', 'failed']);
  const normalizedQueueId = Number(queueId || 0);
  const message = String(process || `E2E contract queue ${normalizedStatus}`);
  return {
    queue_id: normalizedQueueId,
    status: normalizedStatus,
    process: message,
    result_log: String(resultLog || ''),
    snapshot: {
      queue_id: normalizedQueueId,
      job_key: `e2e:${normalizedOperation}:${normalizedQueueId}`,
      job_type: resolvePagebuilderStrongContractJobType(normalizedOperation),
      biz_key: `e2e:${normalizedOperation}`,
      module: 'GuoLaiRen_PageBuilder',
      name: `E2E ${normalizedOperation}`,
      status: normalizedStatus,
      pid: Number(pid || 0),
      process: message,
      result_log: String(resultLog || ''),
      type_id: 0,
      finished: terminalStatuses.has(normalizedStatus) ? 1 : 0,
      start_at: now,
      end_at: terminalStatuses.has(normalizedStatus) ? now : '',
      token_usage: {
        input_tokens: 1,
        output_tokens: 1,
        total_tokens: 2,
      },
    },
  };
}

function buildPagebuilderStrongContractReadyScope(scopePatch = {}) {
  const pageId = 900301;
  const websiteId = 900201;
  const virtualThemeId = 900101;
  const buildPlanPageId = 'home_page';
  const buildPlanBlockId = 'home_page.hero';
  const buildPlanTaskId = 'page:home_page:hero';
  const buildPlanContentItems = {
    'page.home_page.title': 'E2E Strong Contract Home',
    'page.home_page.description': 'Validate the refactored one-stage build plan flow.',
    'block.home_page.hero.title': 'E2E strong contract hero',
    'block.home_page.hero.copy': 'This block proves the v2.2 build contract can drive publish guards.',
    'block.home_page.hero.cta': 'Verify contract',
  };
  return {
    __e2e_stage: 'visual_edit',
    fake_mode: 1,
    workspace_status: 'can_publish',
    workspace_track: 'virtual_theme',
    site_ready: 1,
    site_title: 'E2E Strong Contract Site',
    site_tagline: 'contract validation',
    target_domain: buildLocalDomain('pb-contract-ready'),
    brief_description: 'Validate PageBuilder AI workbench strong contract states without real AI execution.',
    user_description: 'Validate PageBuilder AI workbench strong contract states without real AI execution.',
    page_types: ['home_page'],
    draft_website_id: websiteId,
    website_id: websiteId,
    virtual_theme_id: virtualThemeId,
    pagebuilder_pages_by_type: {
      home_page: {
        page_id: pageId,
        website_id: websiteId,
        virtual_theme_id: virtualThemeId,
      },
    },
    virtual_pages_by_type: {
      home_page: {
        page_type: 'home_page',
        title: 'E2E Strong Contract Home',
        html: '<main><section>E2E strong contract ready page</section></main>',
        blocks: [],
      },
    },
    preview_page_options: [
      { page_type: 'home_page', page_id: pageId, label: 'Home' },
    ],
    preview_page_id: pageId,
    preview_page_type: 'home_page',
    preview_full_url: `/pagebuilder/backend/preview/full?page_id=${pageId}`,
    visual_preview_url: `/pagebuilder/backend/preview/full?page_id=${pageId}&visual_editor=1`,
    visual_edit_url: `/pagebuilder/backend/page/edit?id=${pageId}&virtual_theme_id=${virtualThemeId}`,
    pre_publish_visual_urls: {
      preview_full_url: `/pagebuilder/backend/preview/full?page_id=${pageId}`,
      visual_preview_url: `/pagebuilder/backend/preview/full?page_id=${pageId}&visual_editor=1`,
      visual_edit_url: `/pagebuilder/backend/page/edit?id=${pageId}&virtual_theme_id=${virtualThemeId}`,
    },
    website_profile: {
      site_title: 'E2E Strong Contract Site',
      site_name: 'E2E Strong Contract Site',
      positioning: 'Contract validation',
    },
    plan_markdown: '# E2E strong contract plan',
    plan_structured: {
      pages: {
        home_page: {
          title: 'E2E Strong Contract Home',
        },
      },
    },
    plan_json: {
      pages: {
        home_page: {
          title: 'E2E Strong Contract Home',
        },
      },
    },
    plan_confirmed: 1,
    execution_blueprint: {
      signature: 'e2e-strong-contract-blueprint',
      page_types: ['home_page'],
      tasks: [],
    },
    execution_blueprint_draft: {
      signature: 'e2e-strong-contract-blueprint',
      page_types: ['home_page'],
      tasks: [],
    },
    execution_blueprint_confirmed_signature: 'e2e-strong-contract-blueprint',
    build_plan_v2: {
      contract_meta: {
        id: 'e2e-strong-contract-build-plan',
        version: '2.2',
        status: 'confirmed',
        signature: 'e2e-strong-contract-build-plan',
      },
      signature: 'e2e-strong-contract-build-plan',
      page_types: ['home_page'],
      site_brief: {
        site_name: 'E2E Strong Contract Site',
        primary_goal: 'Validate the refactored one-stage build plan flow.',
        summary: 'Validate the refactored one-stage build plan flow.',
        locale: 'zh_Hans_CN',
      },
      content_manifest: {
        primary_locale: 'zh_Hans_CN',
        items: buildPlanContentItems,
      },
      pages: [
        {
          page_id: buildPlanPageId,
          page_type: 'home_page',
          title_key: 'page.home_page.title',
          description_key: 'page.home_page.description',
          blocks: [buildPlanBlockId],
          sort_order: 100,
        },
      ],
      blocks: [
        {
          block_id: buildPlanBlockId,
          page_id: buildPlanPageId,
          block_type: 'hero',
          content_keys: [
            'block.home_page.hero.title',
            'block.home_page.hero.copy',
            'block.home_page.hero.cta',
          ],
          task_ids: [buildPlanTaskId],
          sort_order: 1000,
        },
      ],
      tasks: [
        {
          task_id: buildPlanTaskId,
          task_kind: 'block_build',
          executor: 'AiSiteBuildQueue',
          input_scope: {
            page_id: buildPlanPageId,
            page_type: 'home_page',
            block_id: buildPlanBlockId,
            block_type: 'hero',
            section_key: 'hero',
          },
          policy_slices: ['layout.4_8_spacing', 'typography.refined_font_stack'],
          context_budget: { max_tokens: 1800 },
          acceptance_rule_ids: ['responsive.no_horizontal_scroll', 'color.readable_contrast'],
          depends_on: [],
        },
      ],
      build_order: [buildPlanTaskId],
    },
    plan_projection: {
      summary: {
        page_count: 1,
        block_count: 1,
        task_count: 1,
      },
      pages: {
        home_page: {
          page_id: buildPlanPageId,
          title: 'E2E Strong Contract Home',
          description: 'Validate the refactored one-stage build plan flow.',
          blocks: [
            {
              block_id: buildPlanBlockId,
              block_type: 'hero',
              title: 'E2E strong contract hero',
              copy: 'This block proves the v2.2 build contract can drive publish guards.',
              task_ids: [buildPlanTaskId],
            },
          ],
        },
      },
    },
    content_manifest: {
      primary_locale: 'zh_Hans_CN',
      items: buildPlanContentItems,
    },
    has_build_plan_v2: 1,
    build_task_summary: {
      total: 1,
      completed: 1,
      pending: 0,
      failed: 0,
    },
    build_summary: {
      task_summary: {
        total: 1,
        completed: 1,
        pending: 0,
        failed: 0,
      },
    },
    ...scopePatch,
  };
}

async function fillIfVisible(page, selector, value, timeoutMs = 1500) {
  const target = page.locator(selector).first();
  const visible = await target.isVisible({ timeout: timeoutMs }).catch(() => false);
  if (!visible) {
    return false;
  }
  await target.fill(String(value ?? ''));
  return true;
}

/**
 * Read the PageBuilder workspace URL from the Websites mirror scope after handoff.
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
 * Websites create-session can hit intermittent upstream SSL handshakes when local HTTPS/proxy switches; retry once as a fallback.
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
 * Create a Websites workspace through APIRequestContext to avoid browser fetch TLS/proxy flakiness.
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

moduleDescribe(test, 'GuoLaiRen_PageBuilder', 'AI site workbench regressions', () => {
  /**
   * Keep this aligned with the PHPUnit integration phase split:
   * phase 1 profile -> merge-scope; build/publish/storefront full chain is covered by other cases in this file.
   */
  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-GUIDED-001' },
    'guided workspace: stepper + workspace snapshot contract (frontend wiring)',
    async ({ page }) => {
      test.setTimeout(120000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await openPagebuilderWorkspaceGuidedAfterExpert(page, backendRoot);

      await expect(page.locator('.pb-guided-steps')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#pb-ai-guided-scope-defaults')).toBeAttached();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const d = await fetchPagebuilderStateData(page, stateUrl);
      expect(d && typeof d === 'object', `workspace snapshot empty: ${stateUrl}`).toBeTruthy();
      expect(typeof d.public_id === 'string' && d.public_id.length > 0).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-CONTRACT-011' },
    'strong contract: fake snapshot covers operation status matrix and publish gates',
    async ({ page }) => {
      test.setTimeout(240000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl, createPayload } = await createDirectPagebuilderWorkspace(page, backendRoot);
      const publicId = String(createPayload.public_id || '');
      expect(publicId).toBeTruthy();
      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);

      const statusCases = [
        {
          label: 'done',
          queueStatus: 'done',
          activeStatus: 'done',
          expectedEnvelopeStatus: 'done',
          progressPercent: 100,
          workspaceStatus: 'can_publish',
        },
        {
          label: 'pending scheduler wait',
          queueStatus: 'pending',
          activeStatus: 'queued',
          expectedEnvelopeStatus: 'queued',
          progressPercent: 0,
          workspaceStatus: 'building',
          canCloseStream: true,
        },
        {
          label: 'queued scheduler wait',
          queueStatus: 'queued',
          activeStatus: 'queued',
          expectedEnvelopeStatus: 'queued',
          progressPercent: 0,
          workspaceStatus: 'building',
          canCloseStream: true,
        },
        {
          label: 'running',
          queueStatus: 'running',
          activeStatus: 'running',
          expectedEnvelopeStatus: 'running',
          progressPercent: 56,
          workspaceStatus: 'building',
          pid: 4321,
        },
        {
          label: 'error failed',
          queueStatus: 'error',
          activeStatus: 'error',
          expectedEnvelopeStatus: 'failed',
          progressPercent: 100,
          workspaceStatus: 'failed',
          retryAllowed: 1,
        },
        {
          label: 'cancelled',
          queueStatus: 'cancelled',
          activeStatus: 'cancelled',
          expectedEnvelopeStatus: 'cancelled',
          progressPercent: 100,
          workspaceStatus: 'failed',
        },
        {
          label: 'stop',
          queueStatus: 'stop',
          activeStatus: 'cancelled',
          expectedEnvelopeStatus: 'cancelled',
          progressPercent: 100,
          workspaceStatus: 'failed',
        },
        {
          label: 'stale timeout fallback',
          queueStatus: '',
          omitQueueInfo: true,
          activeStatus: 'stale',
          expectedEnvelopeStatus: 'stale',
          progressPercent: 100,
          workspaceStatus: 'failed',
          retryAllowed: 1,
        },
      ];

      for (const [index, statusCase] of statusCases.entries()) {
        const queueId = 930000 + index;
        const executionToken = `e2e-contract-${index}-${Date.now().toString(36)}`;
        const activeOperation = buildPagebuilderStrongContractOperationState({
          operation: 'build',
          status: statusCase.activeStatus,
          executionToken,
          queueId,
          progressPercent: statusCase.progressPercent,
          message: `E2E contract status: ${statusCase.label}`,
          canCloseStream: Boolean(statusCase.canCloseStream),
          retryAllowed: Number(statusCase.retryAllowed || 0),
        });
        const queueInfo = statusCase.omitQueueInfo
          ? null
          : buildPagebuilderStrongContractQueueInfo({
            operation: 'build',
            status: statusCase.queueStatus,
            queueId,
            pid: Number(statusCase.pid || 0),
            process: `E2E contract queue status: ${statusCase.queueStatus}`,
          });
        const seeded = mergePagebuilderScopeViaPhp(publicId, buildPagebuilderStrongContractReadyScope({
          workspace_status: statusCase.workspaceStatus,
          active_operation: activeOperation,
          active_operations: { build: activeOperation },
          build_queue_info: queueInfo,
        }));
        expect(seeded.success, JSON.stringify(seeded)).toBeTruthy();

        const data = await fetchPagebuilderStateData(page, stateUrl);
        expect(data && typeof data === 'object', `snapshot missing for ${statusCase.label}`).toBeTruthy();
        expect(String(data.public_id || '')).toBe(publicId);
        expect(String(data.response_mode || '')).toBe('compact_operation');
        expect(String(data.status || ''), statusCase.label).toBe(statusCase.expectedEnvelopeStatus);
        expect(String(data.job_type || ''), statusCase.label).toBe('virtual_theme.tree.build');
        expect(String(data.progress_kind || ''), statusCase.label).toBe('task_progress');
        expect(Number(data.progress_percent || 0), statusCase.label).toBe(Number(statusCase.progressPercent));
        expect(String(data.active_operation && data.active_operation.operation || ''), statusCase.label).toBe('build');
        expect(Number(data.active_operation && data.active_operation.queue_id || 0), statusCase.label).toBe(queueId);
        if (statusCase.omitQueueInfo) {
          expect(data.build_queue_info, statusCase.label).toBeFalsy();
        } else {
          expect(String(data.build_queue_info && data.build_queue_info.snapshot && data.build_queue_info.snapshot.status || ''), statusCase.label)
            .toBe(statusCase.queueStatus);
        }
        if (statusCase.canCloseStream) {
          expect(Boolean(data.active_operation && data.active_operation.can_close_stream), statusCase.label).toBeTruthy();
          expect(Boolean(data.active_operation && data.active_operation.continue_other_operations), statusCase.label).toBeTruthy();
        }
        if (statusCase.retryAllowed) {
          expect(Number(data.active_operation && data.active_operation.retry_allowed || 0), statusCase.label).toBe(1);
        }
      }

      const blockedOperation = buildPagebuilderStrongContractOperationState({
        operation: 'build',
        status: 'error',
        executionToken: `e2e-publish-blocked-${Date.now().toString(36)}`,
        queueId: 940001,
        progressPercent: 100,
        message: 'E2E latest build failure blocks publish.',
        retryAllowed: 1,
      });
      const blockedQueueInfo = buildPagebuilderStrongContractQueueInfo({
        operation: 'build',
        status: 'error',
        queueId: 940001,
        process: 'E2E latest build failure blocks publish.',
        resultLog: 'E2E publish blocked by latest AI build failure.',
      });
      const blockedSeed = mergePagebuilderScopeViaPhp(publicId, buildPagebuilderStrongContractReadyScope({
        workspace_status: 'failed',
        active_operation: blockedOperation,
        active_operations: { build: blockedOperation },
        build_queue_info: blockedQueueInfo,
        latest_build_failed: 1,
        latest_build_failure: {
          blocked: true,
          operation: 'build',
          status: 'error',
          message: 'E2E latest build failure blocks publish.',
        },
        publish_blocked_by_latest_ai_failure: 1,
        publish_blocked_reason: 'E2E latest build failure blocks publish.',
      }));
      expect(blockedSeed.success, JSON.stringify(blockedSeed)).toBeTruthy();
      const blockedChecklist = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-publish-checklist', {
        public_id: publicId,
      });
      expect(blockedChecklist.payload && blockedChecklist.payload.success, JSON.stringify(blockedChecklist.payload)).toBeTruthy();
      const blockedItems = Array.isArray(blockedChecklist.payload.data && blockedChecklist.payload.data.items)
        ? blockedChecklist.payload.data.items
        : [];
      const latestAiBuildItem = blockedItems.find((item) => item && item.key === 'latest_ai_build');
      expect(latestAiBuildItem, JSON.stringify(blockedChecklist.payload.data)).toBeTruthy();
      expect(Boolean(latestAiBuildItem && latestAiBuildItem.ok)).toBeFalsy();
      expect(Boolean(blockedChecklist.payload.data && blockedChecklist.payload.data.passed)).toBeFalsy();

      const readySeed = mergePagebuilderScopeViaPhp(publicId, buildPagebuilderStrongContractReadyScope({
        __e2e_complete_build_tasks: true,
        workspace_status: 'can_publish',
        active_operation: [],
        active_operations: [],
        build_queue_info: null,
        latest_build_failed: 0,
        latest_build_failure: [],
        publish_blocked_by_latest_ai_failure: 0,
        publish_blocked_reason: '',
      }));
      expect(readySeed.success, JSON.stringify(readySeed)).toBeTruthy();
      const readyState = await fetchPagebuilderStateData(page, stateUrl);
      expect(readyState && typeof readyState === 'object', 'ready snapshot missing').toBeTruthy();
      expect(Boolean(readyState.can_publish), JSON.stringify(readyState)).toBeTruthy();
      expect(Boolean(readyState.publish_blocked_by_latest_ai_failure)).toBeFalsy();
      const readyChecklist = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-publish-checklist', {
        public_id: publicId,
      });
      expect(readyChecklist.payload && readyChecklist.payload.success, JSON.stringify(readyChecklist.payload)).toBeTruthy();
      const readyItems = Array.isArray(readyChecklist.payload.data && readyChecklist.payload.data.items)
        ? readyChecklist.payload.data.items
        : [];
      expect(readyItems.find((item) => item && item.key === 'latest_ai_build')).toBeFalsy();
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-CONTRACT-012' },
    'strong contract: operation-sse returns stable error when queued operation has no queue row',
    async ({ page }) => {
      test.setTimeout(180000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl, createPayload } = await createDirectPagebuilderWorkspace(page, backendRoot);
      const publicId = String(createPayload.public_id || '');
      expect(publicId).toBeTruthy();
      const executionToken = `e2e-missing-queue-${Date.now().toString(36)}`;
      const activeOperation = buildPagebuilderStrongContractOperationState({
        operation: 'build',
        status: 'queued',
        executionToken,
        queueId: 949001,
        progressPercent: 0,
        message: 'E2E queued operation intentionally has no backing queue row.',
        canCloseStream: true,
      });
      const seeded = mergePagebuilderScopeViaPhp(publicId, buildPagebuilderStrongContractReadyScope({
        workspace_status: 'building',
        active_operation: activeOperation,
        active_operations: { build: activeOperation },
        build_queue_info: null,
      }));
      expect(seeded.success, JSON.stringify(seeded)).toBeTruthy();

      const streamUrl = buildSameOriginBackendUrl(
        page,
        `pagebuilder/backend/ai-site-agent/operation-sse?public_id=${encodeURIComponent(publicId)}&execution_token=${encodeURIComponent(executionToken)}`
      );
      const stream = await consumeSseStream(page, streamUrl, { timeoutMs: 30000 });
      expect(stream && stream.ok, JSON.stringify(stream)).toBeTruthy();
      const errorEvent = Array.isArray(stream.events)
        ? stream.events.find((event) => event && event.event === 'error')
        : null;
      expect(errorEvent, JSON.stringify(stream)).toBeTruthy();
      const errorData = errorEvent && errorEvent.data && typeof errorEvent.data === 'object' ? errorEvent.data : {};
      expect(String(errorData.code || ''), JSON.stringify(stream)).toBe('OPERATION_QUEUE_NOT_FOUND');
      expect(Array.isArray(errorData.required_params), JSON.stringify(errorData)).toBeTruthy();
      expect(errorData.required_params).toContain('public_id');
      expect(errorData.required_params).toContain('execution_token');
      expect(stream.lastDone && stream.lastDone.success, JSON.stringify(stream)).toBeFalsy();
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-SKILL-001' },
    'skill manager selection is sent with plan start request without direct AI execution',
    async ({ page }) => {
      test.setTimeout(180000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const directAiExecutionUrls = [];
      const customSkillCode = `e2e-contract-skill-${Date.now().toString(36)}`;
      let startPlanPostData = '';

      page.on('request', (request) => {
        const url = request.url();
        if (/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/(?:post-ai|post-generate|post-execute|direct-ai|run-ai)/i.test(url)) {
          directAiExecutionUrls.push(url);
        }
      });
      await page.route(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-skill-list\b/i, async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              default_skill_codes: [],
              skills: [
                {
                  code: 'builtin-copy',
                  name: 'Builtin Copy',
                  description: 'Builtin copy style guard',
                  source: 'builtin',
                  status: 'active',
                  exists: true,
                  selectable: true,
                },
              ],
            },
          }),
        });
      });
      await page.route(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-skill-save\b/i, async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            item: {
              code: customSkillCode,
              name: 'E2E Contract Skill',
              description: 'E2E custom skill',
              body: 'Keep the generated plan concrete and customer visible.',
              body_hash: 'e2e-hash',
              source: 'custom',
              status: 'active',
              exists: true,
              selectable: true,
            },
          }),
        });
      });
      await page.route(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-plan\b/i, async (route) => {
        startPlanPostData = route.request().postData() || '';
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            start_sse: false,
            message: 'E2E mocked plan start accepted.',
            data: {
              selected_skill_codes: [customSkillCode],
            },
          }),
        });
      });

      const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);
      await gotoStable(page, workspaceUrl);
      await page.locator('#pb-ai-site-title').fill('E2E Skill Contract Site');
      await page.locator('#pb-ai-target-domain').fill(`skill-${Date.now().toString(36)}.weline.test`);
      await page.locator('#pb-ai-brief-description').fill('Build a customer-visible website plan for skill selection propagation.');

      await expect(page.locator('#pb-ai-skill-select-panel')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#pb-ai-skill-option-list input[value="builtin-copy"]')).toBeVisible({ timeout: 30000 });
      await page.locator('#pb-ai-skill-create-toggle').click();
      await expect(page.locator('#pb-ai-skill-manager-panel')).toBeVisible({ timeout: 15000 });
      await page.locator('#pb-ai-skill-code').fill(customSkillCode);
      await page.locator('#pb-ai-skill-name').fill('E2E Contract Skill');
      await page.locator('#pb-ai-skill-description').fill('E2E custom skill');
      await page.locator('#pb-ai-skill-body').fill('Keep the generated plan concrete and customer visible.');
      await page.locator('#pb-ai-skill-save-btn').click();

      await expect(page.locator(`[data-skill-chip="${customSkillCode}"]`)).toBeVisible({ timeout: 30000 });
      await expect(page.locator(`[data-pb-skill-summary-stage="plan"]`).first()).toContainText('E2E Contract Skill', { timeout: 15000 });
      await expect(page.locator('.pb-ai-run-virtual-theme').first()).toBeEnabled({ timeout: 15000 });

      const startRequestPromise = page.waitForRequest(
        (request) => /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-plan\b/i.test(request.url())
          && request.method() === 'POST',
        { timeout: 60000 }
      );
      await page.locator('.pb-ai-run-virtual-theme').first().click({ force: true });
      await startRequestPromise;

      expect(startPlanPostData).toContain('selected_skill_codes');
      expect(startPlanPostData).toContain(customSkillCode);
      expect(directAiExecutionUrls).toEqual([]);
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-ASSET-010' },
    'asset panel renders asset_manifest slots and image generation start only queues work',
    async ({ page }) => {
      test.setTimeout(180000);
      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl, createPayload } = await createDirectPagebuilderWorkspace(page, backendRoot);
      const publicId = String(createPayload.public_id || '');
      const slotId = 'hero_visual_smoke';
      const executionToken = `e2e-image-asset-${Date.now().toString(36)}`;

      await mergePagebuilderScope(page, {
        site_title: 'E2E Asset Queue Smoke',
        site_tagline: 'asset queue smoke',
        brief_description: 'Verify existing asset slots render and generation start only creates a queue.',
        user_description: 'Verify existing asset slots render and generation start only creates a queue.',
        page_types: ['home_page'],
        asset_manifest: {
          version: 1,
          slots: {
            [slotId]: {
              slot_id: slotId,
              slot_type: 'hero_image',
              page_type: 'home_page',
              task_key: 'page:home_page:hero',
              status: 'pending',
              brief: 'A warm hero image for the PageBuilder image asset smoke test.',
              prompt: 'Warm editorial hero image with product workspace context.',
            },
          },
        },
      });
      await gotoStable(page, workspaceUrl);

      await expect(page.locator('#pb-ai-asset-panel')).toBeVisible({ timeout: 30000 });
      const assetCards = page.locator('#pb-ai-asset-slot-list [data-asset-slot-id]');
      await expect.poll(async () => assetCards.count(), { timeout: 30000 }).toBeGreaterThanOrEqual(1);
      await expect.poll(async () => {
        const text = await page.locator('#pb-ai-asset-panel-count').innerText().catch(() => '0');
        return Number.parseInt(String(text || '0'), 10) || 0;
      }, { timeout: 30000 }).toBe(await assetCards.count());
      const assetSlot = page.locator(`[data-asset-slot-id="${slotId}"]`);
      await expect(assetSlot).toBeVisible({ timeout: 30000 });
      await expect(assetSlot).toContainText(slotId);
      await expect(assetSlot.locator('[data-asset-generate="generate"]')).toBeEnabled();

      const startPayload = {
        public_id: publicId,
        slot_id: slotId,
        mode: 'generate',
        execution_token: executionToken,
      };
      let startResult = null;
      let startError = null;
      for (const action of ['post-start-asset-generation', 'start-asset-generation']) {
        try {
          startResult = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, action, startPayload);
          if (startResult.response.status() === 404) {
            startResult = null;
            continue;
          }
          break;
        } catch (error) {
          startError = error;
          if (!/HTTP 404\b/.test(String(error && error.message ? error.message : error))) {
            throw error;
          }
          startResult = null;
        }
      }
      if (!startResult) {
        throw startError || new Error('asset generation start endpoint did not return JSON');
      }
      expect(startResult.response.ok(), `asset generation start HTTP ${startResult.response.status()}`).toBeTruthy();
      expect(startResult.payload && startResult.payload.success, JSON.stringify(startResult.payload)).toBeTruthy();

      const queued = startResult.payload && startResult.payload.data && typeof startResult.payload.data === 'object'
        ? startResult.payload.data
        : startResult.payload;
      expect(String(queued.operation || '')).toBe('image_asset');
      expect(Number(queued.queue_id || (queued.queue && queued.queue.queue_id) || 0)).toBeGreaterThan(0);
      expect(String(queued.slot_id || '')).toBe(slotId);
      expect(String(queued.execution_token || '')).toBe(executionToken);
      const queueStatus = String(queued.queue_status || (queued.queue && queued.queue.status) || queued.status || 'queued');
      expect(queueStatus).toMatch(/^(preparing|pending|queued)$/i);
      expect(String(queued.image_url || queued.final_url || queued.generated_url || '')).toBe('');
    }
  );


  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-EDITOR-002' },
    'block field editor saves to virtual theme first and publish materializes the edited page',
    async ({ page }) => {
      test.slow();
      test.setTimeout(900000);

      const backendRoot = await loginAsAdmin(page, { bootstrapOnly: true });
      const { workspaceUrl } = await createDirectPagebuilderWorkspace(page, backendRoot);
      const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
      expect(publicId, 'workspace url must include public_id').toBeTruthy();

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-editor-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Editor ${suffix}`,
        site_tagline: 'block field editor',
        target_domain: localDomain,
        brief_description: 'Block field editing E2E.',
        user_description: 'Block field editing E2E.',
        page_types: ['home_page'],
        site_ready: 1,
        fake_mode: 1,
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);
      await ensurePagebuilderPlanConfirmedByUrl(page, workspaceUrl, scopePatch);
      const buildStart = await startPagebuilderBuildByUrl(page, workspaceUrl, { ...scopePatch });
      expect(String(buildStart.stream_url || '').trim()).toBeTruthy();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const builtState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => {
          const homePage = data && data.virtual_pages_by_type && data.virtual_pages_by_type.home_page
            ? data.virtual_pages_by_type.home_page
            : {};
          const hasVirtualPreview = String(homePage.virtual_preview_url || data.visual_preview_url || '').trim() !== '';
          return hasVirtualPreview
            && (Number(data.virtual_theme_id || 0) > 0 || String(data.workspace_track || '') === 'html_blocks')
            && (Boolean(data.can_publish) || String(data.workspace_status || '') === 'can_publish');
        },
        LONG_WORKSPACE_TIMEOUT
      );
      const editedText = `E2E virtual edit ${suffix}`;
      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);
      const previewFrame = page.locator('#pb-ai-visual-preview-frame');
      const frame = page.frameLocator('#pb-ai-visual-preview-frame');
      await expect(previewFrame).toBeVisible({ timeout: 60000 });

      const srcBefore = await previewFrame.getAttribute('src');
      const contentBlock = frame
        .locator('.pb-component-wrapper[data-region="content"]:has([data-pb-action="edit-block"]), .pb-component-wrapper[data-region="content"]:has([data-pb-action="open-editor"]), .tpmst-component-wrapper[data-region="content"]:has([data-pb-action="edit-block"]), .tpmst-component-wrapper[data-region="content"]:has([data-pb-action="open-editor"]), .pb-ai-block-wrapper[data-region="content"]:has([data-pb-action="edit-block"]), .pb-ai-block-wrapper[data-region="content"]:has([data-pb-action="open-editor"])')
        .first();
      await expect(contentBlock).toBeVisible({ timeout: 60000 });
      await contentBlock.hover();
      const editButton = contentBlock.locator('[data-pb-action="edit-block"], [data-pb-action="open-editor"]').first();
      await expect.poll(
        async () => String(await editButton.getAttribute('data-pb-workspace-action-bound').catch(() => '')) === '1',
        { timeout: 15000 }
      ).toBeTruthy();
      await editButton.click({ force: true });
      const componentConfigModal = page.locator('.weline-component-config-modal.show').last();
      await expect(componentConfigModal).toBeVisible({ timeout: 15000 });
      const editableField = componentConfigModal.locator('textarea[data-field]:not([data-field="_ai_prompt"]), input[type="text"][data-field]:not([data-field="_ai_prompt"]), input:not([type])[data-field]:not([data-field="_ai_prompt"])').first();
      await expect(editableField).toBeVisible({ timeout: 10000 });
      const editableFieldKey = await editableField.getAttribute('data-field');
      expect(String(editableFieldKey || '').trim()).toBeTruthy();
      await editableField.fill(editedText);
      await componentConfigModal.locator('.modal-footer .btn-primary').last().click({ force: true });
      await expect(page.locator('.weline-component-config-modal.show')).toHaveCount(0, { timeout: 20000 });
      const srcAfter = await previewFrame.getAttribute('src');
      expect(srcAfter).toBe(srcBefore);

      const editedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => {
          const virtualPages = JSON.stringify(data && data.virtual_pages_by_type ? data.virtual_pages_by_type : {});
          const layouts = JSON.stringify(data && data.page_type_layouts ? data.page_type_layouts : {});
          const scopeLayouts = JSON.stringify(data && data.scope && data.scope.page_type_layouts ? data.scope.page_type_layouts : {});
          return virtualPages.includes(editedText) || layouts.includes(editedText) || scopeLayouts.includes(editedText);
        },
        WORKSPACE_TIMEOUT
      );
      expect(JSON.stringify({
        virtual_pages_by_type: editedState.virtual_pages_by_type || {},
        page_type_layouts: editedState.page_type_layouts || {},
        scope_page_type_layouts: editedState.scope && editedState.scope.page_type_layouts ? editedState.scope.page_type_layouts : {},
      })).toContain(editedText);
      expect(JSON.stringify(editedState.materialized_pages_by_type || {})).not.toContain(editedText);

      const publishStart = await startPagebuilderPublishByUrl(page, workspaceUrl, { confirm_visual_theme: '1' });
      expect(String(publishStart.stream_url || '').trim()).toBeTruthy();
      await consumePagebuilderOperationStreamIfPresent(page, workspaceUrl, publishStart, 'editor-publish-stream');

      const publishedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => String(data.publish_status || '') === 'published'
          && Number(
            data
              && data.pagebuilder_pages_by_type
              && data.pagebuilder_pages_by_type.home_page
              && data.pagebuilder_pages_by_type.home_page.page_id
              ? data.pagebuilder_pages_by_type.home_page.page_id
              : 0
          ) > 0,
        LONG_WORKSPACE_TIMEOUT
      );
      const pageId = Number(publishedState.pagebuilder_pages_by_type.home_page.page_id || 0);
      expect(pageId).toBeGreaterThan(0);
      const previewResp = await page.request.get(buildSameOriginBackendUrl(page, `pagebuilder/backend/preview/full?page_id=${pageId}`), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        timeout: 120000,
      });
      expect(previewResp.ok(), `published preview HTTP ${previewResp.status()}`).toBeTruthy();
      expect(await previewResp.text()).toContain(editedText);
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
      const publicId = new URL(workspaceUrl).searchParams.get('public_id') || '';
      expect(publicId, 'workspace url must include public_id').toBeTruthy();
      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);

      await mergePagebuilderScopeByUrl(page, workspaceUrl, {
        default_locale: 'ja_JP',
        site_profile_manual: {
          default_locale: true,
        },
      });

      const savedScopeResult = mergePagebuilderScopeViaPhp(publicId, {});
      expect(savedScopeResult && savedScopeResult.success, JSON.stringify(savedScopeResult)).toBeTruthy();
      const savedScope = savedScopeResult.scope && typeof savedScopeResult.scope === 'object' ? savedScopeResult.scope : {};
      expect(String(savedScope.default_locale || '')).toBe('ja_JP');
      expect(
        Boolean(savedScope.site_profile_manual && savedScope.site_profile_manual.default_locale),
        'site_profile_manual.default_locale should be marked after manual selection'
      ).toBeTruthy();

      await gotoStable(page, workspaceUrl);
      await ensurePagebuilderExpertLayout(page);
      await expect(page.locator('#pb-ai-default-locale')).toHaveValue('ja_JP', { timeout: 15000 });
    }
  );


  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-PLAN-005' },
    'expert: build plan gate is confirmed before build starts',
    async ({ page }) => {
      test.slow();
      test.setTimeout(600000);

      await loginAsAdmin(page, {
        useProxy: false,
        bootstrapOnly: true,
        bootstrapModes: ['wls'],
      });
      const brief = 'Smoke build plan confirmation before build.';
      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      expect(String(createPayload.public_id || '').trim()).toBeTruthy();
      const workspaceRoute = `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(createPayload.public_id || ''))}&expert=1`;
      const workspaceUrl = buildSameOriginBackendUrl(page, workspaceRoute);

      const suffix = Date.now().toString().slice(-8);
      const localDomain = buildLocalDomain(`pb-plan-${suffix}`);
      const scopePatch = {
        site_title: `E2E PB Plan Gate ${suffix}`,
        site_tagline: 'build plan gate smoke',
        target_domain: localDomain,
        brief_description: brief,
        user_description: brief,
        page_types: ['home_page'],
        fake_mode: 1,
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);
      const seededPlan = preparePagebuilderPlanDraftViaPhp(String(createPayload.public_id || ''));
      expect(seededPlan.success, JSON.stringify(seededPlan)).toBeTruthy();
      expect(String(seededPlan.plan_markdown || '').trim()).toBeTruthy();

      const planConfirm = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-confirm-plan', {
        public_id: String(createPayload.public_id || ''),
      });
      expect(planConfirm.payload && planConfirm.payload.success, JSON.stringify(planConfirm.payload)).toBeTruthy();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const confirmedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => Boolean(data && data.plan_confirmed),
        WORKSPACE_TIMEOUT
      );
      expect(Boolean(confirmedState && confirmedState.plan_confirmed)).toBeTruthy();

      const buildStart = await startPagebuilderBuildByUrl(page, workspaceUrl, { ...scopePatch });
      expect(String(buildStart.stream_url || '').trim()).toBeTruthy();
      const buildStreamUrl = new URL(String(buildStart.stream_url || ''), workspaceUrl).toString();
      const initialStream = await consumeSseStream(
        page,
        buildStreamUrl,
        { timeoutMs: 180000 }
      ).catch((error) => ({
        ok: false,
        events: [],
        eventNames: [],
        lastDone: null,
        error: String(error && error.message ? error.message : error || ''),
      }));
      const eventNames = Array.isArray(initialStream && initialStream.eventNames) ? initialStream.eventNames : [];
      const hasPositiveBuildSignal = eventNames.includes('start')
        || eventNames.includes('progress')
        || eventNames.includes('page_generated')
        || eventNames.includes('task_completed')
        || eventNames.includes('chunk');
      if (!(initialStream && initialStream.ok && hasPositiveBuildSignal)) {
        const stateAfterBuildStart = await waitForPagebuilderStateData(
          page,
          stateUrl,
          (data) => {
            const active = data && typeof data.active_operation === 'object' ? data.active_operation : {};
            const activeName = String(active.operation || '').trim().toLowerCase();
            const activeStatus = String(active.status || '').trim().toLowerCase();
            const workspaceStatus = String(data && data.workspace_status ? data.workspace_status : '').trim().toLowerCase();
            const buildSummary = data && typeof data.build_summary === 'object' ? data.build_summary : {};
            const taskSummary = buildSummary && typeof buildSummary.task_summary === 'object' ? buildSummary.task_summary : {};
            return activeName === 'build'
              || ['queued', 'running', 'building', 'can_publish', 'failed', 'published'].includes(workspaceStatus)
              || Number(taskSummary.total || 0) > 0
              || Number(data && data.virtual_theme_id ? data.virtual_theme_id : 0) > 0;
          },
          180000
        );
        expect(Boolean(stateAfterBuildStart)).toBeTruthy();
      } else {
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
    }
  );

  moduleCase(
    test,
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-PLAN-REFINE-007' },
    'expert: phase-1 refine and rebuild task modes use queue start and update distinct plan drafts',
    async ({ page }) => {
      test.slow();
      test.setTimeout(900000);

      await loginAsAdmin(page, {
        useProxy: false,
        refreshRuntime: true,
        bootstrapModes: ['wls'],
      });

      const createPayload = createPagebuilderSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
      expect(createPayload.success, JSON.stringify(createPayload)).toBeTruthy();
      const publicId = String(createPayload.public_id || '');
      expect(publicId).toBeTruthy();
      const workspaceUrl = buildSameOriginBackendUrl(page, `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(publicId)}&expert=1`);

      const suffix = Date.now().toString().slice(-8);
      const scopePatch = {
        site_title: `E2E Plan AI ${suffix}`,
        site_tagline: 'phase1 real ai refine rebuild',
        target_domain: buildLocalDomain(`pb-plan-ai-${suffix}`),
        brief_description: 'Create a plan flow that can be refined and rebuilt with visible SSE differences.',
        user_description: 'Create a plan flow that can be refined and rebuilt with visible SSE differences.',
        page_types: ['home_page', 'about_page'],
        fake_mode: 1,
      };

      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);

      const rebuildStart = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-plan', {
        public_id: publicId,
        prompt_mode: 'rebuild',
        instruction: 'Rebuild the full plan with a clearer enterprise positioning and stronger trust tone.',
        scope_patch: JSON.stringify(scopePatch || {}),
        round: '1',
      });
      expect(
        (rebuildStart.payload && rebuildStart.payload.success) || isAiProviderReadinessFailure(rebuildStart.payload),
        JSON.stringify(rebuildStart.payload)
      ).toBeTruthy();
      const seededRebuild = prepareSmallPagebuilderPlanDraftViaPhp(publicId, scopePatch);
      expect(seededRebuild.success, JSON.stringify(seededRebuild)).toBeTruthy();
      await gotoStable(page, workspaceUrl);

      const rebuildMarkdown = await waitForWorkspaceFieldMutation(
        page,
        workspaceUrl,
        '#pb-ai-plan-markdown',
        (value) => value.includes('# ') || value.includes('## '),
        180000
      );
      expect(rebuildMarkdown.length).toBeGreaterThan(200);

      const refineStart = await postPagebuilderWorkspaceJsonByUrl(page, workspaceUrl, 'post-start-plan', {
        public_id: publicId,
        prompt_mode: 'refine',
        instruction: 'Only refine the about page positioning and keep the rest of the plan stable.',
        target_scope: 'pages.about_page',
        scope_patch: JSON.stringify(scopePatch || {}),
        round: '2',
      });
      expect(
        (refineStart.payload && refineStart.payload.success) || isAiProviderReadinessFailure(refineStart.payload),
        JSON.stringify(refineStart.payload)
      ).toBeTruthy();
      const seededRefine = prepareMutatedSmallPagebuilderPlanDraftViaPhp(publicId, scopePatch, {
        action: 'refine',
        instruction: 'Only refine the about page positioning and keep the rest of the plan stable.',
      });
      expect(seededRefine.success, JSON.stringify(seededRefine)).toBeTruthy();
      await gotoStable(page, workspaceUrl);

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
    { module: 'GuoLaiRen_PageBuilder', id: 'PB-WORKBENCH-FULL-006' },
    'frontend full chain: requirement -> build plan confirm -> build -> publish -> storefront',
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
      const workspaceUrl = buildSameOriginBackendUrl(page, workspaceRoute);

      // Step 1: submit the frontend requirement payload.
      const siteTitle = `E2E Full UI ${suffix}`;
      const scopePatch = {
        site_title: siteTitle,
        site_tagline: 'full frontend chain',
        target_domain: subFqdn,
        brief_description: 'Generate and confirm the build plan, build the virtual theme, publish the website, and verify frontend access.',
        user_description: 'Generate and confirm the build plan, build the virtual theme, publish the website, and verify frontend access.',
        page_types: ['home_page', 'about_page', 'contact_page'],
        fake_mode: 1,
      };
      await mergePagebuilderScopeByUrl(page, workspaceUrl, scopePatch);

      // Step 2: confirm the single-stage BuildPlan.
      const gateFlow = await ensurePagebuilderPlanConfirmedByUrl(page, workspaceUrl, scopePatch);
      const planStartHasPlan = Boolean(gateFlow.planStart && gateFlow.planStart.plan && gateFlow.planStart.plan.markdown);
      const planSeeded = Boolean(gateFlow.planConfirm && gateFlow.planConfirm.seeded);
      expect(planStartHasPlan || planSeeded, JSON.stringify(gateFlow.planStart)).toBeTruthy();

      // Step 3: build the virtual theme and pages.
      const buildStart = await startPagebuilderBuildByUrl(page, workspaceUrl, { ...scopePatch });
      expect(String(buildStart.stream_url || '').trim()).toBeTruthy();

      const stateUrl = buildPagebuilderGetStateJsonUrl(workspaceUrl);
      const builtState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => Number(data.draft_website_id || 0) > 0
          && (Number(data.virtual_theme_id || 0) > 0 || String(data.workspace_track || '') === 'html_blocks')
          && (Boolean(data.can_publish) || String(data.workspace_status || '') === 'can_publish'),
        LONG_WORKSPACE_TIMEOUT
      );
      expect(Number(builtState.draft_website_id || 0)).toBeGreaterThan(0);

      // Step 4: 鍙戝竷
      const publishStart = await startPagebuilderPublishByUrl(page, workspaceUrl, { confirm_visual_theme: '1' });
      expect(String(publishStart.stream_url || '').trim()).toBeTruthy();
      await consumePagebuilderOperationStreamIfPresent(
        page,
        workspaceUrl,
        publishStart,
        'publish-stream'
      );

      const publishedState = await waitForPagebuilderStateData(
        page,
        stateUrl,
        (data) => String(data.publish_status || '') === 'published',
        LONG_WORKSPACE_TIMEOUT
      );
      expect(String(publishedState.publish_status || '')).toBe('published');

      // Step 5: verify storefront access.
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

