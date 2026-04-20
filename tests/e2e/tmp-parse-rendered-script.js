const vm = require('vm');
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

function buildWorkspaceUrl(publicId) {
  const runtime = getRuntimeInfo({ refresh: true });
  const origin = String(runtime.runtime && runtime.runtime.target_origin ? runtime.runtime.target_origin : '');
  return new URL(
    `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(publicId || ''))}&expert=1`,
    `${origin.replace(/\/+$/, '')}/`
  ).toString();
}

function buildProxyWorkspaceUrl(publicId) {
  const runtime = getRuntimeInfo({ refresh: true });
  const proxyOrigin = String(runtime.proxy && runtime.proxy.origin ? runtime.proxy.origin : '');
  const backendPrefix = String(runtime.paths && runtime.paths.backend_prefix_path ? runtime.paths.backend_prefix_path : '').replace(/\/+$/, '');
  return new URL(
    `${backendPrefix}/pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(String(publicId || ''))}&expert=1`,
    `${proxyOrigin.replace(/\/+$/, '')}/`
  ).toString();
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  try {
    await loginAsAdmin(page, {
      bootstrapOnly: true,
      bootstrapModes: ['wls'],
    });
    const payload = createSessionViaPhp({ workspace_status: 'preparing', fake_mode: 1 });
    const scopePatch = {
      site_title: 'E2E Phase UI Seed',
      site_tagline: 'phase ui hover actions',
      target_domain: 'pb-phase-ui-seed.local.test',
      brief_description: 'Seeded inline preview coverage.',
      user_description: 'Seeded inline preview coverage.',
      page_types: ['home_page', 'about_page'],
      fake_mode: 1,
      workspace_status: 'stage1_draft_ready',
      website_profile: {
        site_name: 'E2E Phase UI Seed',
        positioning: 'Frontend interaction coverage',
        audience: 'E2E verification',
      },
      execution_blueprint_draft: {
        signature: 'seed-signature',
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
      plan_markdown: '# E2E Phase UI Seed\\n\\n## Shared Direction\\n- Theme: helpful\\n- Header: CTA\\n\\n## Page Coverage\\n1. home_page: editable blocks\\n2. about_page: trust block',
      plan_ai_generated: 0,
      plan_ai_fallback: 1,
      plan_generated_page_types: ['home_page', 'about_page'],
      plan_confirmed: 0,
    };
    mergeScopeViaPhp(payload.public_id, scopePatch);
    const urls = [buildWorkspaceUrl(payload.public_id), buildProxyWorkspaceUrl(payload.public_id)];
    for (const workspaceUrl of urls) {
      await page.goto(workspaceUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await page.waitForTimeout(3000);
      const result = await page.evaluate(() => ({
        url: location.href,
        scripts: Array.from(document.scripts).map((s, i) => ({ i, text: s.textContent || '' })),
      }));
      console.log('URL', result.url);
      let failed = false;
      for (const entry of result.scripts) {
        const text = String(entry.text || '');
        if (!text.trim()) {
          continue;
        }
        try {
          new vm.Script(text, { filename: `script-${entry.i}.js` });
        } catch (e) {
          failed = true;
          console.log('SCRIPT_FAIL', entry.i, e && e.message);
          const lines = text.split(/\r?\n/);
          const stackLine = String(e && e.stack || '').split(/\r?\n/).find((line) => /script-\d+\.js:\d+/.test(line));
          const match = stackLine ? stackLine.match(/script-\d+\.js:(\d+):(\d+)/) : null;
          const lineNo = match ? Number(match[1]) : null;
          const colNo = match ? Number(match[2]) : null;
          console.log('LINE', lineNo, 'COL', colNo);
          if (lineNo) {
            const start = Math.max(0, lineNo - 4);
            const end = Math.min(lines.length, lineNo + 3);
            for (let i = start; i < end; i += 1) {
              console.log(String(i + 1).padStart(5, ' ') + ': ' + lines[i]);
            }
          }
          break;
        }
      }
      if (!failed) {
        console.log('ALL_OK');
      }
    }
  } catch (err) {
    console.error(err);
    process.exitCode = 1;
  } finally {
    await context.close();
    await browser.close();
  }
})();
