// @weline-e2e-runtime fallback
// @ts-check
const { test, expect } = require('@playwright/test');
const {
  buildTriggerSseUrl,
  buildWorkbenchUrl,
  consumeSseStream,
  createWorkspace,
  loginAsAdmin,
  postRecommendDomain,
  postSetStageFromWorkspace,
  triggerFakeDomainPurchase,
} = require('./helpers/ai-workbench');

test.use({ ignoreHTTPSErrors: true });

const HUB_TIMEOUT = 120000;
const WORKSPACE_TIMEOUT = 300000;
const SCREENSHOT_OPTIONS = {
  fullPage: true,
  animations: 'disabled',
  caret: 'hide',
  scale: 'css',
};

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} [provider]
 * @param {boolean} [fakeMode]
 */
async function openHub(page, provider = 'websites_default', fakeMode = true) {
  const backendRoot = await loginAsAdmin(page);
  const hubUrl = buildWorkbenchUrl(backendRoot, provider, fakeMode);
  await page.goto(hubUrl, { waitUntil: 'domcontentloaded', timeout: HUB_TIMEOUT });
  await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(1000);
  return { backendRoot, hubUrl };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 */
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

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function readJsonTextarea(page, selector) {
  const raw = await page.locator(selector).inputValue({ timeout: WORKSPACE_TIMEOUT });
  return JSON.parse(raw.trim());
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {RegExp} urlPattern
 * @param {() => Promise<void>} action
 */
async function waitForWorkspaceReload(page, urlPattern, action) {
  const waitForUrl = page.waitForURL(urlPattern, {
    waitUntil: 'domcontentloaded',
    timeout: WORKSPACE_TIMEOUT,
  }).catch((error) => {
    if (/ERR_ABORTED|frame was detached/i.test(String(error && error.message))) {
      return null;
    }
    throw error;
  });

  await Promise.allSettled([waitForUrl, action()]);
  await page.waitForLoadState('domcontentloaded', { timeout: 20000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
}

function buildLocalDomain(prefix) {
  const suffix = Date.now().toString().slice(-8);
  return `${prefix}-${suffix}.local.test`;
}

/**
 * 手动切换阶段在「高级设置与原始 Scope」的 details 折叠块内，未展开时 Playwright 视为 hidden。
 * @param {import('@playwright/test').Page} page
 */
async function openManualStagePanel(page) {
  const details = page.locator('details').filter({ has: page.locator('#site-builder-stage') });
  await details.evaluate((el) => {
    /** @type {HTMLDetailsElement} */
    const d = el;
    d.open = true;
  });
  await expect(page.locator('#site-builder-stage')).toBeVisible({ timeout: 15000 });
}

/**
 * 统一截图参数，屏蔽每次运行都会变化的数据区域（domain、scope、SSE输出、动态时间戳等）
 * @param {import('@playwright/test').Page} page
 * @param {string} screenshotName
 */
async function expectWorkbenchScreenshot(page, screenshotName) {
  await expect(page.locator('body')).toHaveScreenshot(screenshotName, {
    ...SCREENSHOT_OPTIONS,
    maxDiffPixelRatio: 0.015,
    mask: [
      page.locator('#site-builder-domain'),
      page.locator('#site-builder-scope-full'),
      page.locator('#site-builder-stage-notes'),
      page.locator('#site-builder-workspace-terminal'),
      page.locator('#site-builder-session-events'),
      page.locator('#site-builder-session-events-table'),
    ],
  });
}

test.describe('Websites default AI workbench (fake_mode)', () => {
  test.describe.configure({ mode: 'serial', retries: 1 });

  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('websites_default provider in fake mode: hub → workspace → domain purchase → trigger-sse preview', async ({ page }) => {
    test.slow();
    test.setTimeout(WORKSPACE_TIMEOUT);

    const { backendRoot } = await openHub(page, 'websites_default', true);
    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });
    await expectWorkbenchScreenshot(page, 'websites-default-workbench-hub.png');

    const brief = 'Build a fashion boutique online store';
    await page.fill('#site-agent-description', brief);
    const payload = await createWorkspace(page, backendRoot, 'websites_default', brief, { fakeMode: true });
    expect(payload.success).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    await gotoStable(page, new URL(payload.workspace_url, page.url()).toString());
    await expectWorkbenchScreenshot(page, 'websites-default-workbench-workspace.png');

    const localDomain = buildLocalDomain('wb-fashion');
    await page.fill('#site-builder-title', 'Fashion Boutique');
    await page.fill('#site-builder-domain', localDomain);
    await waitForWorkspaceReload(page, /site-builder-agent\/workspace\?public_id=/, () =>
      page.click('#site-builder-save-summary', { force: true })
    );

    const scopeAfterSave = await readJsonTextarea(page, '#site-builder-scope-full');
    expect(Number(scopeAfterSave.fake_mode || 0)).toBe(1);

    const purchase = await triggerFakeDomainPurchase(page, backendRoot, { timeoutMs: 120000 });
    expect(purchase.order_id).toBeGreaterThan(0);

    const triggerUrl = buildTriggerSseUrl(page, backendRoot, {
      fake_mode: 1,
      description: brief,
      domain: localDomain,
      account_id: 900001,
      use_ai: 1,
    });
    const sse = await consumeSseStream(page, triggerUrl, { timeoutMs: 120000 });
    expect(sse.ok, `SSE failed: ${sse.error || sse.status}`).toBeTruthy();
    expect(
      sse.lastDone,
      `trigger-sse parse diagnostics: ${JSON.stringify({
        contentType: sse.contentType,
        eventNames: sse.eventNames,
        eventCount: (sse.events || []).length,
      })}`
    ).toBeTruthy();
    expect(sse.lastDone.fake_mode).toBeTruthy();
    expect(Number(sse.lastDone.website_id || 0)).toBeGreaterThanOrEqual(800000);
    expect(Number(sse.lastDone.theme_id || 0)).toBeGreaterThanOrEqual(600000);
    expect(String(sse.lastDone.preview_url || '')).not.toBe('');

    const names = (sse.events || []).map((e) => e.event);
    expect(names).toContain('start');
    expect(names).toContain('done');
    const progressLike = (sse.events || []).filter((e) => e.event === 'progress' || e.event === 'info');
    expect(progressLike.length).toBeGreaterThanOrEqual(6);
  });

  test('three-stage manual switch: prepare → generate → complete (fake workspace)', async ({ page }) => {
    test.setTimeout(WORKSPACE_TIMEOUT);

    const { backendRoot } = await openHub(page, 'websites_default', true);
    await expect(page.locator('#site-agent-description')).toBeVisible({ timeout: 30000 });

    const brief = 'Stage navigation smoke in fake mode.';
    await page.fill('#site-agent-description', brief);
    const payload = await createWorkspace(page, backendRoot, 'websites_default', brief, { fakeMode: true });
    expect(payload.success).toBeTruthy();

    await gotoStable(page, new URL(payload.workspace_url, page.url()).toString());
    await openManualStagePanel(page);

    const initial = await page.locator('#site-builder-stage').inputValue();
    expect(['prepare', 'generate', 'complete']).toContain(initial);

    await postSetStageFromWorkspace(page, backendRoot, 'generate');
    await page.reload({ waitUntil: 'load', timeout: WORKSPACE_TIMEOUT });
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await openManualStagePanel(page);
    await expect(page.locator('#site-builder-stage')).toHaveValue('generate', { timeout: 30000 });

    await postSetStageFromWorkspace(page, backendRoot, 'complete');
    await page.reload({ waitUntil: 'load', timeout: WORKSPACE_TIMEOUT });
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await openManualStagePanel(page);
    await expect(page.locator('#site-builder-stage')).toHaveValue('complete', { timeout: 30000 });
  });

  test('postRecommendDomain in fake_mode returns candidate_domains', async ({ page }) => {
    test.setTimeout(120000);
    const { backendRoot } = await openHub(page, 'websites_default', true);
    await expect(page.locator('#site-agent-api-recommend-domain')).toHaveCount(1);
    const data = await postRecommendDomain(page, backendRoot, {
      description: 'Coffee roastery with subscription box',
      domain: '',
      accountId: 900001,
      fakeMode: true,
    });
    expect(data.success).toBeTruthy();
    expect(data.fake_mode).toBeTruthy();
    expect(Array.isArray(data.candidate_domains)).toBeTruthy();
    expect(data.candidate_domains.length).toBeGreaterThan(0);
    expect(typeof data.domain).toBe('string');
    expect(data.domain.length).toBeGreaterThan(0);
    expect(Array.isArray(data.checked_results)).toBeTruthy();
    expect(data.checked_results.length).toBeGreaterThan(0);
    expect(data.checked_results[0].available).toBeTruthy();
  });
});
