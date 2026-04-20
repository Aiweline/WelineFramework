const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('./node_modules/playwright');
const { loginAsAdmin, getRuntimeInfo } = require('./framework');

function rootDir() {
  return path.resolve(__dirname, '..', '..');
}

function createSessionViaPhp(initialScope = {}) {
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
  return JSON.parse(execFileSync('php', ['-r', phpCode], {
    cwd: rootDir(),
    stdio: 'pipe',
    encoding: 'utf8',
  }));
}

function mergeScopeViaPhp(publicId, scopePatch = {}) {
  const phpPublicId = JSON.stringify(String(publicId || ''))
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpScopePatch = JSON.stringify(scopePatch || {})
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'");
  const phpCode = `
require 'app/bootstrap.php';
$sessionService = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\GuoLaiRen\\PageBuilder\\Service\\AiSiteAgentSessionService::class);
$publicId = json_decode('${phpPublicId}', true);
$scopePatch = json_decode('${phpScopePatch}', true);
$session = $sessionService->loadByPublicId(is_string($publicId) ? $publicId : '', 1);
if (!$session) { throw new RuntimeException('session not found'); }
$sessionService->mergeScope($session->getId(), 1, is_array($scopePatch) ? $scopePatch : []);
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
`;
  return JSON.parse(execFileSync('php', ['-r', phpCode], {
    cwd: rootDir(),
    stdio: 'pipe',
    encoding: 'utf8',
  }));
}

function buildDirectWorkspaceUrl(publicId) {
  const runtime = getRuntimeInfo({ refresh: true });
  const origin = String(runtime.runtime?.target_origin || '').replace(/\/+$/, '');
  return `${origin}/pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(publicId || ''))}&expert=1`;
}

function buildPrefixedTargetWorkspaceUrl(publicId) {
  const runtime = getRuntimeInfo({ refresh: true });
  const origin = String(runtime.runtime?.target_origin || '').replace(/\/+$/, '');
  const prefix = String(runtime.paths?.backend_prefix_path || '').replace(/\/+$/, '');
  return `${origin}${prefix}/pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(publicId || ''))}&expert=1`;
}

function buildProxyWorkspaceUrl(publicId) {
  const runtime = getRuntimeInfo({ refresh: true });
  const proxyOrigin = String(runtime.proxy?.origin || '').replace(/\/+$/, '');
  const prefix = String(runtime.paths?.backend_prefix_path || '').replace(/\/+$/, '');
  return `${proxyOrigin}${prefix}/pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(publicId || ''))}&expert=1`;
}

function buildSmallPlanPatch() {
  return {
    site_title: 'E2E Phase UI Debug',
    site_tagline: 'phase ui hover actions',
    target_domain: 'pb-phase-ui-debug.local.test',
    brief_description: 'Use inline frontend controls to exercise phase previews.',
    user_description: 'Use inline frontend controls to exercise phase previews.',
    page_types: ['home_page', 'about_page'],
    fake_mode: 1,
    workspace_status: 'stage1_draft_ready',
    website_profile: {
      site_name: 'E2E Phase UI Debug',
      positioning: 'Frontend interaction coverage',
      audience: 'E2E verification',
    },
    execution_blueprint_draft: {
      signature: `sig-${Date.now()}`,
      tasks: [{ task_key: 'shared:header', task_type: 'shared_component', region: 'header', title: 'Build shared header' }],
    },
    plan_json: {
      theme_design: { visual_direction: 'Clean light business layout', typography: 'Sans', tone: 'Helpful' },
      navigation_plan: { header: ['Home', 'About'], cta: 'Start Consultation' },
      footer_plan: { groups: ['Contact', 'Legal'] },
      pages: {
        home_page: {
          title: 'Home',
          page_goal: 'Explain core value.',
          blocks: [{ block_key: 'hero', goal: 'Lead block', content: 'Compact seeded block', keywords: ['editable'] }],
        },
      },
    },
    plan_structured: {
      theme_design: { visual_direction: 'Clean light business layout', typography: 'Sans', tone: 'Helpful' },
      navigation_plan: { header: ['Home', 'About'], cta: 'Start Consultation' },
      footer_plan: { groups: ['Contact', 'Legal'] },
      pages: {
        home_page: {
          title: 'Home',
          page_goal: 'Explain core value.',
          blocks: [{ block_key: 'hero', goal: 'Lead block', content: 'Compact seeded block', keywords: ['editable'] }],
        },
      },
    },
    plan_markdown: '# E2E Phase UI Debug\n\n## Shared Direction\n- Theme: helpful\n- Header: CTA\n\n## Page Coverage\n1. home_page: editable blocks\n2. about_page: trust block',
    plan_ai_generated: 0,
    plan_ai_fallback: 1,
    plan_generated_page_types: ['home_page', 'about_page'],
    plan_confirmed: 0,
  };
}

async function waitForInputValue(page, selector, predicate, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const value = await page.locator(selector).inputValue().catch(() => '');
    if (predicate(String(value || ''))) {
      return String(value || '');
    }
    await page.waitForTimeout(1000);
  }
  throw new Error(`timeout waiting for ${selector}`);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  page.on('pageerror', (error) => console.log('PAGEERROR', String(error && error.message ? error.message : error)));
  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      console.log('CONSOLE', msg.type(), msg.text());
    }
  });
  try {
    await loginAsAdmin(page, { useProxy: false, bootstrapOnly: true, bootstrapModes: ['wls'] });
    const createPayload = createSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
    mergeScopeViaPhp(createPayload.public_id, buildSmallPlanPatch());
    const candidates = [
      buildDirectWorkspaceUrl(createPayload.public_id),
      buildPrefixedTargetWorkspaceUrl(createPayload.public_id),
      buildProxyWorkspaceUrl(createPayload.public_id),
    ];
    let workspaceUrl = '';
    for (const candidate of candidates) {
      console.log('TRY', candidate);
      try {
        await page.goto(candidate, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
        const bodyText = String(await page.locator('body').first().textContent().catch(() => '')).trim();
        console.log('BODY', JSON.stringify(bodyText.slice(0, 50)));
        if (/^4\d{0,2}$/.test(bodyText) && bodyText.length <= 3) {
          continue;
        }
        workspaceUrl = candidate;
        break;
      } catch (error) {
        console.log('OPEN_FAIL', error && error.message ? error.message : String(error));
      }
    }
    if (!workspaceUrl) {
      throw new Error('no usable workspace candidate');
    }
    console.log('OPEN', workspaceUrl);
    await page.locator('#pb-ai-plan-inline-panel').waitFor({ state: 'visible', timeout: 30000 });
    console.log('PLAN_PANEL_VISIBLE');
    const initialPlan = await waitForInputValue(page, '#pb-ai-plan-markdown', (value) => value.length > 0, 120000);
    console.log('PLAN_MARKDOWN_LEN', initialPlan.length);
    await page.locator('#pb-ai-plan-rendered-content .pb-ai-plan-preview-block').first().hover();
    console.log('PHASE1_HOVER');
    await page.locator('#pb-ai-plan-rendered-content .pb-ai-plan-preview-block .pb-ai-preview-action-btn[data-pb-preview-action=\"refine\"]').first().click({ force: true });
    console.log('PHASE1_REFINE_CLICK');
    const refinedPlan = await waitForInputValue(page, '#pb-ai-plan-markdown', (value) => value.length > 0 && value !== initialPlan, 180000);
    console.log('PHASE1_REFINED_LEN', refinedPlan.length);
  } catch (error) {
    console.error(error);
    process.exitCode = 1;
  } finally {
    await context.close();
    await browser.close();
  }
})();
