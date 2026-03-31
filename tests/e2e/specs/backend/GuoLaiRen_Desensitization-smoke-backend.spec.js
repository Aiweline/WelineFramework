// @weline-e2e-runtime wls
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'GuoLaiRen_Desensitization';

/** 仅用字面量片段判断 PHP/WLS 致命页，避免宽泛正则误伤业务页内联脚本（如 `undefined`、英文说明等）。 */
const FATAL_SNIPPETS = [
  'WLS Runtime Error',
  'Fatal error',
  'ParseError',
  'syntax error',
  'Uncaught',
  'Call to undefined',
  '404 Not Found',
];

/**
 * 会话偶发落在登录页时补一次登录（不每次 refreshRuntime，减少 target_origin 在 WLS/CLI 间抖动）。
 * @param {import('@playwright/test').Page} page
 */
async function ensureBackendSession(page) {
  const u = page.url();
  if (u.includes('/admin/login')) {
    await loginAsAdmin(page, { useProxy: false, refreshRuntime: false });
  }
}

test.describe('GuoLaiRen Desensitization backend smoke', () => {
  test.describe.configure({ mode: 'serial', retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { useProxy: false, refreshRuntime: true });
  });

  const pages = [
    { route: buildModuleBackendRoute(MODULE, 'detect'), urlRe: /backend\/detect/i, locator: '#detectForm', requireLocator: true },
    {
      route: buildModuleBackendRoute(MODULE, 'rewrite'),
      urlRe: /backend\/rewrite/i,
      locator: '#desensRewriteForm',
      requireLocator: true,
      // 后台页可能有长连接导致 window load 不触发；用 gotoUrl 的 readySelector 等到表单挂载（timeout 与 goto 共用）
      gotoTimeout: 180000,
      readySelector: '#desensRewriteForm',
      locatorTimeout: 120000,
    },
    { route: buildModuleBackendRoute(MODULE, 'config'), urlRe: /backend\/config/i, locator: '#meta_rules', requireLocator: true },
  ];

  for (let idx = 0; idx < pages.length; idx++) {
    const pageCase = pages[idx];
    test(`SM-0${idx + 1}: render ${pageCase.route} without fatal errors`, async ({ page }) => {
      test.setTimeout(300000);
      const errors = [];
      page.on('pageerror', err => errors.push(String(err?.message || err)));

      const gotoOpts = {
        timeout: pageCase.gotoTimeout ?? 150000,
        waitUntil: pageCase.waitUntil ?? 'domcontentloaded',
        settleMs: pageCase.settleMs ?? 1500,
        useProxy: false,
        ...(pageCase.readySelector !== undefined ? { readySelector: pageCase.readySelector } : {}),
        ...(pageCase.loadStateTimeout !== undefined ? { loadStateTimeout: pageCase.loadStateTimeout } : {}),
        ...(pageCase.allowLoadStateTimeout !== undefined ? { allowLoadStateTimeout: pageCase.allowLoadStateTimeout } : {}),
      };

      await gotoBackend(page, pageCase.route, gotoOpts);
      await ensureBackendSession(page);
      if (page.url().includes('/admin/login')) {
        await gotoBackend(page, pageCase.route, gotoOpts);
      }

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 60000 });
      await expect(page).toHaveURL(pageCase.urlRe, { timeout: 60000 });

      const locator = page.locator(pageCase.locator);
      const locatorWait = pageCase.locatorTimeout ?? 90000;
      if (pageCase.requireLocator) {
        try {
          await expect(locator.first()).toBeVisible({ timeout: locatorWait });
        } catch (firstErr) {
          if (String(pageCase.route).includes('rewrite')) {
            await page.reload({
              waitUntil: 'commit',
              timeout: pageCase.gotoTimeout ?? 150000,
            });
            if (pageCase.readySelector) {
              await page
                .locator(pageCase.readySelector)
                .first()
                .waitFor({ state: 'attached', timeout: pageCase.gotoTimeout ?? 150000 });
            } else {
              await page.waitForLoadState('domcontentloaded', { timeout: 120000 });
            }
            await ensureBackendSession(page);
            if (page.url().includes('/admin/login')) {
              await gotoBackend(page, pageCase.route, gotoOpts);
            }
            await expect(page).toHaveURL(pageCase.urlRe, { timeout: 60000 });
            await expect(locator.first()).toBeVisible({ timeout: locatorWait });
          } else {
            throw firstErr;
          }
        }
      }
      const locatorCount = await locator.count().catch(() => 0);
      const bodyTextSample = await body.innerText().catch(() => '');
      const hasFatal = FATAL_SNIPPETS.some(s => bodyTextSample.includes(s));
      console.log(
        `[${pageCase.route}] url=${page.url()} locator=${pageCase.locator} count=${locatorCount} hasFatal=${hasFatal}`,
      );

      for (const snippet of FATAL_SNIPPETS) {
        await expect(body).not.toContainText(snippet);
      }

      expect(errors, errors.join('\n')).toEqual([]);
    });
  }

  test('SM-04: config save API persists key fields', async ({ page }) => {
    test.setTimeout(180000);
    const configRoute = buildModuleBackendRoute(MODULE, 'config');
    const saveRoute = buildModuleBackendRoute(MODULE, 'config/save');
    const marker = `e2e-marker-${Date.now()}`;

    await gotoBackend(page, configRoute, {
      timeout: 90000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
      useProxy: false,
    });
    await expect(page.locator('#meta_rules')).toBeVisible({ timeout: 30000 });

    const originalMode = await page.locator('#desens_mode').inputValue();
    const originalLevel = await page.locator('#desens_level').inputValue();
    const originalDefaultModel = await page.locator('#default_model').inputValue();
    const originalStyle = await page.locator('#rewrite_style').inputValue();
    const originalPreserve = await page.locator('#rewrite_preserve').isChecked();
    const originalReadability = await page.locator('#rewrite_readability').isChecked();
    const originalStrict = await page.locator('#desens_strict_check').isChecked();
    const originalMetaRules = await page.locator('#meta_rules').inputValue();
    const originalGoogleRules = await page.locator('#google_rules').inputValue();
    const nextMetaRules = `${originalMetaRules}\n${marker}`;
    const nextMode = originalMode === 'detect' ? 'mark' : 'detect';
    const nextLevel = originalLevel === 'standard' ? 'high' : 'standard';
    const nextStyle = originalStyle === 'natural' ? 'formal' : 'natural';

    const saveResult = await page.evaluate(
      async ({ route, payload }) => {
        const response = await fetch(route, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify(payload),
        });
        const text = await response.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch (error) {
          data = { success: false, message: `non-json:${text.slice(0, 200)}` };
        }
        return { ok: response.ok, data };
      },
      {
        route: saveRoute,
        payload: {
          desensitization_params: {
            mode: nextMode,
            level: nextLevel,
            enable_strict_check: !originalStrict,
            default_model: originalDefaultModel,
          },
          rewrite_params: {
            style: nextStyle,
            preserve_format: !originalPreserve,
            enhance_readability: !originalReadability,
          },
          meta_rules: nextMetaRules,
          google_rules: originalGoogleRules,
        },
      },
    );

    try {
      expect(saveResult.ok, JSON.stringify(saveResult)).toBeTruthy();
      expect(saveResult.data && saveResult.data.success, JSON.stringify(saveResult)).toBeTruthy();

      await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      await expect(page.locator('#meta_rules')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#meta_rules')).toHaveValue(new RegExp(marker));
      await expect(page.locator('#desens_mode')).toHaveValue(nextMode);
      await expect(page.locator('#desens_level')).toHaveValue(nextLevel);
      await expect(page.locator('#desens_strict_check')).toBeChecked({ checked: !originalStrict });
      await expect(page.locator('#default_model')).toHaveValue(originalDefaultModel);
      await expect(page.locator('#rewrite_style')).toHaveValue(nextStyle);
      await expect(page.locator('#rewrite_preserve')).toBeChecked({ checked: !originalPreserve });
      await expect(page.locator('#rewrite_readability')).toBeChecked({ checked: !originalReadability });
    } finally {
      await page.evaluate(
        async ({ route, payload }) => {
          await fetch(route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
          });
        },
        {
          route: saveRoute,
          payload: {
            desensitization_params: {
              mode: originalMode,
              level: originalLevel,
              enable_strict_check: originalStrict,
              default_model: originalDefaultModel,
            },
            rewrite_params: {
              style: originalStyle,
              preserve_format: originalPreserve,
              enhance_readability: originalReadability,
            },
            meta_rules: originalMetaRules,
            google_rules: originalGoogleRules,
          },
        },
      );
    }
  });

  test('SM-05: saveRules rejects when default_model not configured', async ({ page }) => {
    test.setTimeout(300000);
    const configRoute = buildModuleBackendRoute(MODULE, 'config');
    const saveRoute = buildModuleBackendRoute(MODULE, 'config/save');
    const saveRulesRoute = buildModuleBackendRoute(MODULE, 'config/saveRules');

    await gotoBackend(page, configRoute, {
      timeout: 90000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
      useProxy: false,
    });
    await expect(page.locator('#default_model')).toBeVisible({ timeout: 30000 });
    const originalDefaultModel = await page.locator('#default_model').inputValue().catch(() => '');

    if (originalDefaultModel.trim()) {
      const clearResult = await page.evaluate(
        async ({ route }) => {
          const response = await fetch(route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
              desensitization_params: {
                mode: 'detect',
                level: 'standard',
                enable_strict_check: true,
                default_model: '',
              },
            }),
          });
          return { ok: response.ok, data: await response.json() };
        },
        { route: saveRoute },
      );
      expect(clearResult.ok, JSON.stringify(clearResult)).toBeTruthy();
      expect(clearResult.data?.success, JSON.stringify(clearResult)).toBeTruthy();
    }

    try {
      const saveRulesResult = await page.evaluate(
        async ({ route }) => {
          const response = await fetch(route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ platform: 'meta' }),
          });
          const data = await response.json();
          return { ok: response.ok, data };
        },
        { route: saveRulesRoute },
      );

      expect(saveRulesResult.ok, JSON.stringify(saveRulesResult)).toBeTruthy();
      expect(saveRulesResult.data?.success, JSON.stringify(saveRulesResult)).toBeFalsy();
      expect(String(saveRulesResult.data?.message || '')).toContain('默认AI模型');
    } finally {
      if (originalDefaultModel.trim()) {
        await page.evaluate(
          async ({ route, defaultModel }) => {
            await fetch(route, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({
                desensitization_params: {
                  mode: 'detect',
                  level: 'standard',
                  enable_strict_check: true,
                  default_model: defaultModel,
                },
              }),
            });
          },
          { route: saveRoute, defaultModel: originalDefaultModel },
        );
      }
    }
  });
});

