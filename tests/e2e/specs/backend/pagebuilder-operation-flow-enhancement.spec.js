// @weline-e2e-runtime auto
// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

const WORKSPACE_TIMEOUT = 180000;
const DIRECT_AI_ENDPOINT_RE = /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/(?:post-ai|post-generate|post-execute|direct-ai|run-ai)\b/i;

function backendUrl(backendRoot, route) {
  return new URL(route, `${String(backendRoot).replace(/\/+$/, '')}/`).toString();
}

async function createPagebuilderWorkspace(page, backendRoot) {
  const createUrl = backendUrl(backendRoot, 'pagebuilder/backend/ai-site-agent/post-create-session');
  const payload = await page.evaluate(async (url) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error(`pagebuilder create-session: HTTP ${response.status} non-JSON body=${text.slice(0, 400)}`);
    }
    if (!response.ok || !data.success) {
      throw new Error(`pagebuilder create-session failed: HTTP ${response.status} payload=${JSON.stringify(data).slice(0, 800)}`);
    }
    return data;
  }, createUrl);

  const publicId = String(payload.public_id || '').trim();
  expect(publicId, JSON.stringify(payload)).not.toBe('');

  return {
    payload,
    publicId,
    workspaceUrl: backendUrl(
      backendRoot,
      `pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(publicId)}&expert=1`
    ),
  };
}

async function openWorkspace(page) {
  const backendRoot = await loginAsAdmin(page, { timeout: 120000 });
  const workspace = await createPagebuilderWorkspace(page, backendRoot);
  await page.goto(workspace.workspaceUrl, { waitUntil: 'domcontentloaded', timeout: WORKSPACE_TIMEOUT });
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  await expect(page.locator('.pb-ai-run-virtual-theme').first()).toBeVisible({ timeout: 30000 });
  return { backendRoot, ...workspace };
}

async function fillCurrentPlanForm(page, domain) {
  await page.locator('#pb-ai-site-title').fill('Operation Flow E2E Site');
  await page.locator('#pb-ai-target-domain').fill(domain);
  await page.locator('#pb-ai-brief-description').fill('Verify the refactored one-stage PageBuilder operation flow.');
  await expect(page.locator('.pb-ai-run-virtual-theme').first()).toBeEnabled({ timeout: 30000 });
}

test.describe('PageBuilder refactored operation flow', () => {
  test('workspace exposes one-stage plan controls and no legacy two-stage task controls', async ({ page }) => {
    await openWorkspace(page);

    await expect(page.locator('.pb-ai-run-virtual-theme').first()).toBeVisible();
    await expect(page.locator('#pb-ai-run-virtual-theme')).toHaveCount(0);
    await expect(page.locator('#pb-ai-start-task-plan, #pb-ai-confirm-task-plan, [data-stage="task_plan"]')).toHaveCount(0);
    await expect(page.locator('[data-pb-queue-state-detail="plan"]').first()).toBeVisible();
  });

  test('plan operation starts through post-start-plan queue without direct AI execution', async ({ page }) => {
    await openWorkspace(page);
    const domain = `operation-flow-${Date.now().toString(36)}.weline.local`;
    const directAiUrls = [];
    let startPlanPostData = '';

    page.on('request', (request) => {
      if (DIRECT_AI_ENDPOINT_RE.test(request.url())) {
        directAiUrls.push(request.url());
      }
    });
    await page.route(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-plan\b/i, async (route) => {
      startPlanPostData = route.request().postData() || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          start_sse: false,
          message: 'E2E mocked one-stage plan queue accepted.',
          data: { queue_id: 991001, status: 'pending' },
        }),
      });
    });

    await fillCurrentPlanForm(page, domain);
    const startRequestPromise = page.waitForRequest(
      (request) => /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-plan\b/i.test(request.url())
        && request.method() === 'POST',
      { timeout: 60000 }
    );
    await page.locator('.pb-ai-run-virtual-theme').first().click({ force: true });
    await startRequestPromise;

    expect(startPlanPostData).toContain('site_title');
    expect(startPlanPostData).toContain('target_domain');
    expect(startPlanPostData).toContain(domain);
    expect(directAiUrls).toEqual([]);
    await expect(page.locator('[data-pb-queue-state-detail="plan"]').first())
      .toContainText(/running|pending|accepted|queue/i, { timeout: 15000 });
  });

  test('asset operation remains queue-only in the refactored workspace', async ({ page }) => {
    await openWorkspace(page);
    const directAiUrls = [];
    let assetPostData = '';

    page.on('request', (request) => {
      if (DIRECT_AI_ENDPOINT_RE.test(request.url())) {
        directAiUrls.push(request.url());
      }
    });
    await page.route(/\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-asset-generation\b/i, async (route) => {
      assetPostData = route.request().postData() || '';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: 'E2E mocked asset queue accepted.',
          data: { queue_id: 991002, status: 'pending' },
        }),
      });
    });

    await page.locator('#pb-ai-image-tool-prompt').fill('Hero image for a warm storefront.');
    const requestPromise = page.waitForRequest(
      (request) => /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/post-start-asset-generation\b/i.test(request.url())
        && request.method() === 'POST',
      { timeout: 60000 }
    );
    await page.locator('#pb-ai-image-tool-generate').click({ force: true });
    await requestPromise;

    expect(assetPostData).toContain('prompt_brief');
    expect(directAiUrls).toEqual([]);
  });
});
