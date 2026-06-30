// @weline-e2e-runtime auto
// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

const WORKSPACE_TIMEOUT = 180000;

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

async function installEventSourceProbe(page) {
  await page.evaluate(() => {
    window.__pbE2eEventSources = [];
    window.EventSource = class PbAiE2eEventSource {
      constructor(url) {
        this.url = String(url || '');
        this.readyState = 0;
        this.closed = false;
        this.listeners = {};
        window.__pbE2eEventSources.push(this);
      }

      addEventListener(type, handler) {
        this.listeners[String(type || '')] = handler;
      }

      close() {
        this.closed = true;
        this.readyState = 2;
      }
    };
  });
}

async function readEventSourceProbe(page) {
  return page.evaluate(() => (window.__pbE2eEventSources || []).map((source) => ({
    url: source.url,
    closed: !!source.closed,
    readyState: source.readyState,
  })));
}

test.describe('PageBuilder refactored operation stream governance', () => {
  test('workspace exposes current operation runner and no legacy SSE governance globals', async ({ page }) => {
    await installEventSourceProbe(page);
    await openWorkspace(page);

    const state = await page.evaluate(() => ({
      hasOperationRunner: !!(window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function'),
      hasPlanQueueDetail: !!document.querySelector('[data-pb-queue-state-detail="plan"]'),
      hasLegacyGovernor: !!window.pbAiSseGovernor,
      hasLegacyConnectionGovernor: !!window.pbAiSseConnectionGovernor,
      hasLegacyLowercaseManager: !!window.pbAiSseConnectionManager,
      hasLegacyUppercaseManager: !!window.PbAiSseConnectionManager,
      hasLegacyDebugPanel: !!document.getElementById('pb-ai-sse-debug-panel'),
      eventSourceCount: (window.__pbE2eEventSources || []).length,
    }));

    expect(state.hasOperationRunner).toBe(true);
    expect(state.hasPlanQueueDetail).toBe(true);
    expect(state.hasLegacyGovernor).toBe(false);
    expect(state.hasLegacyConnectionGovernor).toBe(false);
    expect(state.hasLegacyLowercaseManager).toBe(false);
    expect(state.hasLegacyUppercaseManager).toBe(false);
    expect(state.hasLegacyDebugPanel).toBe(false);
    expect(state.eventSourceCount).toBe(0);
  });

  test('same operation token opens only one observable stream', async ({ page }) => {
    await openWorkspace(page);
    await installEventSourceProbe(page);

    const result = await page.evaluate(() => {
      const payload = {
        success: true,
        data: {
          operation: 'build',
          execution_token: 'e2e-build-token',
          stream_url: '/pagebuilder/backend/ai-site-agent/operation-sse?operation=build&execution_token=e2e-build-token',
        },
      };
      return {
        first: window.PbAiOperationRunner.startFromResponse(payload, 'build'),
        second: window.PbAiOperationRunner.startFromResponse(payload, 'build'),
      };
    });

    expect(result.first).toBe(true);
    expect(result.second).toBe(false);
    await page.waitForTimeout(5600);

    const sources = await readEventSourceProbe(page);
    expect(sources).toHaveLength(1);
    expect(sources[0].url).toContain('/operation-sse');
    expect(sources[0].url).toContain('execution_token=e2e-build-token');
  });

  test('scheduler-deferred queue response updates UI without opening a stream', async ({ page }) => {
    await openWorkspace(page);
    await installEventSourceProbe(page);

    const accepted = await page.evaluate(() => window.PbAiOperationRunner.startFromResponse({
      success: true,
      data: {
        operation: 'plan',
        deferred_queue_progress: true,
        queue_waiting_for_scheduler: true,
        can_close_stream: true,
        message: 'The plan queue was accepted and is waiting for scheduler dispatch.',
        active_operation: {
          operation: 'plan',
          status: 'pending',
          queue_waiting_for_scheduler: true,
          can_close_stream: true,
        },
      },
    }, 'plan'));

    expect(accepted).toBe(true);
    const sources = await readEventSourceProbe(page);
    expect(sources).toHaveLength(0);
    await expect(page.locator('[data-pb-queue-state-detail="plan"]').first())
      .toContainText(/pending|waiting|scheduler|queue/i, { timeout: 15000 });
  });
});
