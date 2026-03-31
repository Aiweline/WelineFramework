// @weline-e2e-runtime auto
// @ts-check
const { test, expect } = require('@playwright/test');
const { buildWorkbenchUrl, createWorkspace, loginAsAdmin } = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

const HUB_TIMEOUT = 120000;
const WORKSPACE_TIMEOUT = 180000;
const SCREENSHOT_OPTIONS = {
  fullPage: true,
  animations: 'disabled',
  caret: 'hide',
  scale: 'css',
  maxDiffPixelRatio: 0.06,
};
const PAGE_ERROR_ALLOWLIST = [
  /ResizeObserver loop limit exceeded/i,
  /ResizeObserver loop completed with undelivered notifications/i,
];
const CONSOLE_ERROR_ALLOWLIST = [
  /favicon\.ico.*404/i,
  /Failed to load resource/i,
];

/** 路由表会把 AiSiteAgent 规范为 ai-site-agent；兼容历史/测试里的 aiSiteAgent 字面量 */
const PAGEBUILDER_AI_WORKSPACE_PATH_RE = /\/pagebuilder\/backend\/(?:ai-site-agent|aiSiteAgent)\/workspace/i;

async function openHub(page, provider = 'pagebuilder', fakeMode = false) {
  const loginTransient = /ERR_CONNECTION|ECONNRESET|net::ERR_|TimeoutError/i;
  let backendRoot;
  let lastLoginError = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      backendRoot = await loginAsAdmin(page, {
        timeout: 120000,
        refreshRuntime: attempt > 0,
      });
      lastLoginError = null;
      break;
    } catch (error) {
      lastLoginError = error;
      const msg = String(error && error.message ? error.message : error);
      if (!loginTransient.test(msg) || attempt === 2) {
        throw error;
      }
      await page.waitForTimeout(2500);
    }
  }
  if (lastLoginError) {
    throw lastLoginError;
  }

  const hubUrl = buildWorkbenchUrl(backendRoot, provider, fakeMode);
  const transientNet = /ERR_CONNECTION|ECONNRESET|net::ERR_/i;
  let lastError = null;
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      await page.goto(hubUrl, { waitUntil: 'domcontentloaded', timeout: HUB_TIMEOUT });
      lastError = null;
      break;
    } catch (error) {
      lastError = error;
      const msg = String(error && error.message ? error.message : error);
      if (!transientNet.test(msg) || attempt === 2) {
        throw error;
      }
      await page.waitForTimeout(2500);
    }
  }
  if (lastError) {
    throw lastError;
  }
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(1000);
  return { backendRoot, hubUrl };
}

async function waitForWorkspaceReload(page, urlPattern, action) {
  // 保存简报：postForm(merge-scope) 成功后再 window.location.reload()，URL 不变。
  // 先等 merge-scope 响应可快速区分「接口失败未重载」与「长时间未触发 reload」。
  const mergeResponsePromise = page.waitForResponse(
    response =>
      /site-builder-agent\/merge-scope/i.test(response.url())
      && response.request().method() === 'POST',
    { timeout: WORKSPACE_TIMEOUT }
  );
  const navigationPromise = page.waitForNavigation({
    waitUntil: 'domcontentloaded',
    timeout: WORKSPACE_TIMEOUT,
  });
  await action();
  const mergeResponse = await mergeResponsePromise;
  /** @type {any} */
  let mergeJson = {};
  try {
    mergeJson = await mergeResponse.json();
  } catch {
    mergeJson = {};
  }
  expect(mergeJson && mergeJson.success, JSON.stringify(mergeJson)).toBeTruthy();
  await navigationPromise;
  await expect(page).toHaveURL(urlPattern);
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
}

async function gotoStable(page, url) {
  await page.goto(url, {
    waitUntil: 'commit',
    timeout: WORKSPACE_TIMEOUT,
  });
  await page.locator('body').first().waitFor({
    state: 'attached',
    timeout: 30000,
  });
  await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
}

async function readJsonTextarea(page, selector) {
  const raw = await page.locator(selector).inputValue({ timeout: WORKSPACE_TIMEOUT });
  return JSON.parse(raw.trim());
}

function buildLocalDomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.local.test`;
}

function bindPageAnomalies(page) {
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', error => {
    pageErrors.push(String(error && error.message ? error.message : error));
  });
  page.on('console', msg => {
    if (msg.type() === 'error') {
      consoleErrors.push(String(msg.text() || '').trim());
    }
  });
  return { pageErrors, consoleErrors };
}

function isAllowedAnomaly(text, allowlist) {
  return allowlist.some(rule => rule.test(String(text || '')));
}

function filterUnexpectedAnomalies(items, allowlist) {
  return items.filter(item => !isAllowedAnomaly(item, allowlist));
}

async function expectWorkbenchScreenshot(page, screenshotName) {
  await expect(page.locator('body')).toHaveScreenshot(screenshotName, {
    ...SCREENSHOT_OPTIONS,
    mask: [
      page.locator('#site-agent-description'),
      page.locator('#site-builder-domain'),
      page.locator('#site-builder-scope-full'),
      page.locator('#site-builder-workspace-terminal'),
      page.locator('#site-builder-session-events'),
      page.locator('#site-builder-session-events-table'),
      page.locator('#site-builder-visual-preview-frame'),
      page.locator('#pb-ai-scope-full'),
      page.locator('#pb-ai-visual-preview-frame'),
    ],
  });
}

async function expectWorkspaceStreamHealthy(page) {
  const probe = await page.evaluate(async () => {
    const terminal = window.WelineSseTerminal && window.WelineSseTerminal['site-builder-workspace-terminal'];
    const streamUrl = terminal && typeof terminal.getUrl === 'function' ? terminal.getUrl() : '';
    if (!streamUrl) {
      return { ok: false, reason: 'missing-stream-url', buffer: '' };
    }

    const response = await fetch(streamUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'text/event-stream' },
    });

    if (!response.ok || !response.body) {
      return {
        ok: false,
        reason: 'request-failed',
        status: response.status,
        buffer: await response.text(),
      };
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    const deadline = Date.now() + 7000;

    while (Date.now() < deadline && buffer.length < 8000) {
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

      buffer += decoder.decode(chunk.value, { stream: true });
      if (buffer.includes('event: snapshot')) {
        break;
      }
    }

    await reader.cancel().catch(() => {});
    return { ok: true, status: response.status, buffer };
  });

  expect(probe.ok, JSON.stringify(probe)).toBeTruthy();
  expect(probe.buffer).toContain('event: start');
  expect(probe.buffer).toContain('已连接工作区事件流');
  expect(probe.buffer).toContain('event: snapshot');
  expect(probe.buffer).not.toContain('event: error');
  expect(probe.buffer).not.toContain('参数无效');
  expect(probe.buffer).not.toContain('会话不存在或无访问权限');
}

async function expectWorkspaceTerminalBootstrapHealthy(page) {
  await expect(page.locator('#site-builder-workspace-terminal .weline-sse-terminal-title-text'))
    .not.toHaveText('site_builder_sse_title', { timeout: 30000 });
  // 终端内 EventSource 与 fetch 的 Cookie/重连时序在代理下可能不一致；流是否健康以 expectWorkspaceStreamHealthy 的 fetch 探测为准。
}

test.describe('AI Site Workbench', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  test('pagebuilder provider closes the loop through native virtual theme orchestration and mirrored visual editing', async ({ page }) => {
    test.slow();
    test.setTimeout(420000);
    const anomalies = bindPageAnomalies(page);

    const { backendRoot } = await openHub(page, 'pagebuilder', false);
    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });
    await expectWorkbenchScreenshot(page, 'ai-site-workbench-hub.png');

    await page.fill('#site-agent-description', 'Build a coffee brand site with story pages, contact page, and a clear home page hero.');
    const createWorkspacePayload = await createWorkspace(
      page,
      backendRoot,
      'pagebuilder',
      'Build a coffee brand site with story pages, contact page, and a clear home page hero.'
    );
    expect(createWorkspacePayload.success).toBeTruthy();
    expect(createWorkspacePayload.workspace_url).toBeTruthy();

    await gotoStable(page, new URL(createWorkspacePayload.workspace_url, page.url()).toString());
    await expectWorkspaceTerminalBootstrapHealthy(page);
    await expectWorkspaceStreamHealthy(page);
    await expectWorkbenchScreenshot(page, 'ai-site-workbench-workspace.png');

    const localDomain = buildLocalDomain('pb-e2e');
    await page.fill('#site-builder-title', 'PageBuilder E2E Coffee');
    await page.fill('#site-builder-tagline', 'Roasting story and product showcase');
    await page.fill('#site-builder-domain', localDomain);
    await page.fill('#site-builder-brief', 'Need a home page, about page, and contact page with strong brand storytelling.');
    await waitForWorkspaceReload(
      page,
      /site-builder-agent\/workspace\?public_id=/,
      () => page.click('#site-builder-save-summary', { force: true })
    );

    const websitesWorkspaceUrl = page.url();
    await expect(page.locator('#site-builder-domain')).toHaveValue(localDomain);

    const handoffLink = page.locator('a[href*="/site-builder-agent/pagebuilder-handoff"]').first();
    await expect(handoffLink).toBeVisible({ timeout: 15000 });
    await Promise.all([
      page.waitForURL(PAGEBUILDER_AI_WORKSPACE_PATH_RE, { timeout: WORKSPACE_TIMEOUT }),
      handoffLink.click(),
    ]);
    await page.goto(websitesWorkspaceUrl, { waitUntil: 'domcontentloaded', timeout: WORKSPACE_TIMEOUT });
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

    await expect
      .poll(async () => {
        const scope = await readJsonTextarea(page, '#site-builder-scope-full');
        return String(scope.pagebuilder_workspace_url || '');
      }, { timeout: WORKSPACE_TIMEOUT })
      .toMatch(PAGEBUILDER_AI_WORKSPACE_PATH_RE);

    const websitesScopeAfterHandoff = await readJsonTextarea(page, '#site-builder-scope-full');
    const pagebuilderWorkspaceUrl = String(websitesScopeAfterHandoff.pagebuilder_workspace_url || '');
    expect(pagebuilderWorkspaceUrl).toMatch(PAGEBUILDER_AI_WORKSPACE_PATH_RE);

    // 专家模式 (?expert=1) 下才渲染 #pb-ai-draft-website-id / #pb-ai-scope-full 等元素
    const pbExpertUrl = pagebuilderWorkspaceUrl + (pagebuilderWorkspaceUrl.includes('?') ? '&expert=1' : '?expert=1');
    await gotoStable(page, new URL(pbExpertUrl, page.url()).toString());

    const landedOnLogin = await page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
      .isVisible({ timeout: 3000 })
      .catch(() => false);
    if (landedOnLogin) {
      await loginAsAdmin(page);
      await gotoStable(page, new URL(pagebuilderWorkspaceUrl, page.url()).toString());
    }
    await expect(page.locator('#pb-ai-run-virtual-theme')).toBeVisible({ timeout: 30000 });
    await expectWorkbenchScreenshot(page, 'ai-site-workbench-pagebuilder-expert.png');

    await page.click('#pb-ai-run-virtual-theme', { force: true });

    await expect
      .poll(async () => {
        const text = await page.locator('#pb-ai-draft-website-id').textContent();
        return Number(String(text || '0').trim() || '0');
      }, { timeout: WORKSPACE_TIMEOUT })
      .toBeGreaterThan(0);

    /** @type {any} */
    let pageBuilderState = await readJsonTextarea(page, '#pb-ai-scope-full');
    pageBuilderState.draft_website_id = Number(pageBuilderState.draft_website_id || 0);
    pageBuilderState.virtual_theme_id = Number(pageBuilderState.virtual_theme_id || 0);
    pageBuilderState.preview_page_id = Number(pageBuilderState.preview_page_id || 0);
    expect(Number(pageBuilderState.draft_website_id)).toBeGreaterThan(0);
    expect(Number(pageBuilderState.virtual_theme_id)).toBeGreaterThan(0);
    expect(Number(pageBuilderState.preview_page_id)).toBeGreaterThan(0);
    expect(Object.keys(pageBuilderState.pagebuilder_pages_by_type || {})).not.toHaveLength(0);

    for (const pageInfo of Object.values(pageBuilderState.pagebuilder_pages_by_type || {})) {
      expect(Number(pageInfo.website_id)).toBe(Number(pageBuilderState.draft_website_id));
    }

    const previewOptions = Array.isArray(pageBuilderState.preview_page_options)
      ? pageBuilderState.preview_page_options
      : [];
    if (previewOptions.length > 1) {
      const nextOption = previewOptions.find(option => Number(option.page_id || option.value || 0) !== Number(pageBuilderState.preview_page_id));
      expect(nextOption).toBeTruthy();

      await page.selectOption('#pb-ai-preview-page-select', String(nextOption.page_id || nextOption.value));
      await page.click('#pb-ai-switch-preview-page', { force: true });

      await expect
        .poll(async () => {
          const scope = await readJsonTextarea(page, '#pb-ai-scope-full');
          return Number(scope.preview_page_id || 0);
        }, { timeout: WORKSPACE_TIMEOUT })
        .toBe(Number(nextOption.page_id || nextOption.value));

      pageBuilderState = await readJsonTextarea(page, '#pb-ai-scope-full');
    }

    await gotoStable(page, websitesWorkspaceUrl);
    await expect(page.locator('#site-builder-visual-preview-frame')).toBeVisible({ timeout: 30000 });
    await expectWorkbenchScreenshot(page, 'ai-site-workbench-websites-mirror.png');

    await expect
      .poll(async () => {
        const state = await readJsonTextarea(page, '#site-builder-scope-full');
        return {
          draftWebsiteId: Number(state.draft_website_id || 0),
          previewPageId: Number(state.preview_page_id || 0),
          visualPreviewUrl: String(state.visual_preview_url || ''),
          visualEditUrl: String(state.visual_edit_url || ''),
        };
      }, { timeout: WORKSPACE_TIMEOUT })
      .toEqual({
        draftWebsiteId: Number(pageBuilderState.draft_website_id),
        previewPageId: Number(pageBuilderState.preview_page_id),
        visualPreviewUrl: String(pageBuilderState.visual_preview_url || ''),
        visualEditUrl: String(pageBuilderState.visual_edit_url || ''),
      });

    const websitesState = await readJsonTextarea(page, '#site-builder-scope-full');
    const mirrorFrameSrc = await page.locator('#site-builder-visual-preview-frame').getAttribute('src');
    expect(mirrorFrameSrc).toContain('/pagebuilder/backend/preview/full');
    expect(mirrorFrameSrc).toContain('visual_editor=1');
    expect(mirrorFrameSrc).toContain(`virtual_theme_id=${pageBuilderState.virtual_theme_id}`);
    expect(mirrorFrameSrc).toContain(`page_id=${pageBuilderState.preview_page_id}`);

    const editorHref = await page.locator('a[href*="/pagebuilder/backend/page/edit"]').first().getAttribute('href');
    expect(editorHref).toContain('/pagebuilder/backend/page/edit');
    expect(editorHref).toContain(`id=${pageBuilderState.preview_page_id}`);
    expect(editorHref).toContain(`virtual_theme_id=${pageBuilderState.virtual_theme_id}`);
    expect(websitesState.visual_edit_url).toBe(editorHref);

    const editorPage = await page.context().newPage();
    const editorAnomalies = bindPageAnomalies(editorPage);
    await gotoStable(editorPage, new URL(editorHref, page.url()).toString());
    await expect(editorPage.locator('#previewIframe')).toBeVisible({ timeout: 30000 });

    const editorPreviewSrc = await editorPage.locator('#previewIframe').getAttribute('src');
    expect(editorPreviewSrc).toContain('/pagebuilder/backend/preview/full');
    expect(editorPreviewSrc).toContain('visual_editor=1');
    expect(editorPreviewSrc).toContain(`page_id=${pageBuilderState.preview_page_id}`);
    expect(editorPreviewSrc).toContain(`virtual_theme_id=${pageBuilderState.virtual_theme_id}`);
    const unexpectedEditorPageErrors = filterUnexpectedAnomalies(editorAnomalies.pageErrors, PAGE_ERROR_ALLOWLIST);
    const unexpectedEditorConsoleErrors = filterUnexpectedAnomalies(editorAnomalies.consoleErrors, CONSOLE_ERROR_ALLOWLIST);
    expect(unexpectedEditorPageErrors, unexpectedEditorPageErrors.join('\n')).toEqual([]);
    expect(unexpectedEditorConsoleErrors, unexpectedEditorConsoleErrors.join('\n')).toEqual([]);

    await editorPage.close();
    const unexpectedPageErrors = filterUnexpectedAnomalies(anomalies.pageErrors, PAGE_ERROR_ALLOWLIST);
    const unexpectedConsoleErrors = filterUnexpectedAnomalies(anomalies.consoleErrors, CONSOLE_ERROR_ALLOWLIST);
    expect(unexpectedPageErrors, unexpectedPageErrors.join('\n')).toEqual([]);
    expect(unexpectedConsoleErrors, unexpectedConsoleErrors.join('\n')).toEqual([]);
  });

  test('pagebuilder provider in fake mode: session scope keeps fake_mode and local_fake_demo', async ({ page }) => {
    test.setTimeout(180000);
    const anomalies = bindPageAnomalies(page);
    const { backendRoot } = await openHub(page, 'pagebuilder', true);
    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });

    const brief = 'Fake mode PageBuilder handoff smoke test.';
    await page.fill('#site-agent-description', brief);
    const payload = await createWorkspace(page, backendRoot, 'pagebuilder', brief, { fakeMode: true });
    expect(payload.success).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    await gotoStable(page, new URL(payload.workspace_url, page.url()).toString());
    await expectWorkspaceTerminalBootstrapHealthy(page);
    await expectWorkspaceStreamHealthy(page);
    await expectWorkbenchScreenshot(page, 'ai-site-workbench-fake-mode-workspace.png');
    const scope = await readJsonTextarea(page, '#site-builder-scope-full');
    expect(Number(scope.fake_mode || 0)).toBe(1);
    expect(String(scope.build_execution_mode || '')).toBe('local_fake_demo');
    const unexpectedPageErrors = filterUnexpectedAnomalies(anomalies.pageErrors, PAGE_ERROR_ALLOWLIST);
    const unexpectedConsoleErrors = filterUnexpectedAnomalies(anomalies.consoleErrors, CONSOLE_ERROR_ALLOWLIST);
    expect(unexpectedPageErrors, unexpectedPageErrors.join('\n')).toEqual([]);
    expect(unexpectedConsoleErrors, unexpectedConsoleErrors.join('\n')).toEqual([]);
  });

});
