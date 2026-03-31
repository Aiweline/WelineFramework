// @weline-e2e-runtime fallback
// @ts-check
const { test, expect } = require('@playwright/test');
const {
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

/**
 * 后端有时返回 target-origin 的绝对链接；在 e2e 代理下需要强制回当前 origin。
 * @param {import('@playwright/test').Page} page
 * @param {string} href
 */
function normalizeToCurrentOrigin(page, href) {
  const base = new URL(page.url());
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

test.describe('PageBuilder AI site building (websites_default provider → PageBuilder workspace)', () => {
  test.describe.configure({ mode: 'serial' });

  test('full flow: hub → handoff → pb virtual theme build', async ({ page }) => {
    test.slow();
    test.setTimeout(480000);

    const backendRoot = await loginAsAdmin(page);

    const brief = 'Fashion boutique online store with brand story, about, and contact pages.';

    const payload = await createWorkspace(page, backendRoot, 'pagebuilder', brief, { fakeMode: true });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = new URL(payload.workspace_url, page.url()).toString();
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
      .poll(async () => Number((await page.locator('#pb-ai-weline-theme-id').textContent()) || '0'), {
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
    const payload = await createWorkspace(page, backendRoot, 'pagebuilder', brief, { fakeMode: true });
    expect(payload.success, `create-session failed: ${JSON.stringify(payload)}`).toBeTruthy();
    expect(payload.workspace_url).toBeTruthy();

    const workspaceUrl = new URL(payload.workspace_url, page.url()).toString();
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
      .poll(async () => Number((await page.locator('#pb-ai-weline-theme-id').textContent()) || '0'), {
        timeout: WORKSPACE_TIMEOUT,
      })
      .toBeGreaterThan(0);

    await expect(page.locator('#pb-ai-visual-preview-frame')).toBeVisible({ timeout: 60000 });
  });
});
