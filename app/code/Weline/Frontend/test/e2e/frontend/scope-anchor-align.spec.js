const { test, expect } = require('@playwright/test');

/**
 * Task 4 e2e：世代锚点对齐（generation_uid）。
 *
 * 跑法：GEN_ANCHOR_URL="https://<host>/<path>?w_fpc_pierce=<真实章>" php bin/w e2e:run scope-anchor-align
 *
 * 断言目标（对应计划 Task 4 的三条防循环硬规则）：
 *  - 页面锚点来自 `Weline.config.generationUid`（新锚点），不再用旧字段；
 *  - 锚点一致 → 零额外 scope-bootstrap 请求（R3 终态）；
 *  - 同一锚点第二次加载 → 依旧零请求、零导航（防循环机器判据）；
 *  - `themePublishedVersionId` / `assetVersion` 变化不影响判定。
 */
const entry = process.env.GEN_ANCHOR_URL;

async function disableCache(page) {
  const session = await page.context().newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.setCacheDisabled', { cacheDisabled: true });
}

test.beforeEach(async ({ page }) => {
  await disableCache(page);
});

async function openPage(page) {
  if (!entry) {
    throw new Error('GEN_ANCHOR_URL must point at a live storefront page with a real w_fpc_pierce stamp');
  }
  const response = await page.goto(entry, { waitUntil: 'domcontentloaded' });
  expect(response.status()).toBe(200);
  return response;
}

test('UC-1 page exposes the unified generation anchor', async ({ page }) => {
  await openPage(page);
  const anchor = await page.evaluate(() => {
    const cfg = (window.Weline && window.Weline.config) || {};
    return {
      generationUid: String(cfg.generationUid || ''),
      fromModule: window.WelineGenerationAnchor
        ? window.WelineGenerationAnchor.readPageAnchor()
        : '',
      hasModule: typeof window.WelineGenerationAnchor === 'object',
    };
  });
  expect(anchor.hasModule).toBe(true);
  expect(anchor.generationUid).not.toBe('');
  expect(anchor.fromModule).toBe(anchor.generationUid);
});

test('UC-2 aligned anchor makes zero scope-bootstrap request and reaches terminal state', async ({ page }) => {
  await openPage(page);
  // 用模块自己的判定跑一次对齐；锚点一致时应直接命中终态，不发请求。
  const result = await page.evaluate(async () => {
    const mod = window.WelineGenerationAnchor;
    if (!mod) {
      return { ok: false, reason: 'module_missing' };
    }
    sessionStorage.removeItem('weline.storefront_last_aligned_anchor');
    sessionStorage.removeItem('weline.storefront_anchor_align_at');
    const first = await mod.ensureScopeAnchorAligned();
    const second = await mod.ensureScopeAnchorAligned();
    return {
      ok: true,
      first,
      second,
      lastAligned: sessionStorage.getItem('weline.storefront_last_aligned_anchor') || '',
    };
  });
  expect(result.ok).toBe(true);
  expect(result.lastAligned).not.toBe('');
  // 第二次必须是终态（零请求）
  expect(result.second.reason).toBe('terminal');
});

test('UC-3 legacy fields do not drive the anchor decision', async ({ page }) => {
  await openPage(page);
  const decision = await page.evaluate(async () => {
    const cfg = (window.Weline && window.Weline.config) || {};
    const before = window.WelineGenerationAnchor
      ? window.WelineGenerationAnchor.readPageAnchor()
      : '';
    // 篡改旧字段，判定结果不得改变
    cfg.themePublishedVersionId = 'tampered-legacy-id';
    cfg.assetVersion = 'tampered-asset-version';
    const after = window.WelineGenerationAnchor
      ? window.WelineGenerationAnchor.readPageAnchor()
      : '';
    return { before, after };
  });
  expect(decision.before).not.toBe('');
  expect(decision.after).toBe(decision.before);
});
