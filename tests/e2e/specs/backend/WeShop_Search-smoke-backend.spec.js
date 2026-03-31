// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Search';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const UPSTREAM_FAILURE_PATTERN = /upstream_request_failed|Client network socket disconnected before secure TLS connection was established/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function gotoBackendStable(page, route, options = {}) {
  const maxAttempts = options.maxAttempts || 3;
  const gotoOptions = {
    timeout: options.timeout || 60000,
    settleMs: options.settleMs || 1200,
    useProxy: false,
  };

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    await gotoBackend(page, route, gotoOptions);
    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    if (!UPSTREAM_FAILURE_PATTERN.test(text)) {
      return text;
    }
    if (attempt < maxAttempts) {
      await page.waitForTimeout(1000 * attempt);
    }
  }

  throw new Error(`Failed to open backend route "${route}" after ${maxAttempts} attempts due to upstream transport errors.`);
}

test.describe('WeShop_Search backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { useProxy: false });
  });

  test('TC-01: renders search engine config index without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'engine');

    const text = await gotoBackendStable(page, route);
    await expect(page.locator('#scope-select')).toBeVisible();
    // 避免 strict mode：页面上会有“新增配置”和每条配置的“编辑配置”链接
    // 这里断言“新增配置（不带 id，仅带 scope）”那条链接
    await expect(page.locator('a[href*="search/backend/engine/form?scope="]').first()).toBeVisible();

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-02: renders search engine config form without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'engine', 'form');

    const text = await gotoBackendStable(page, route);
    await expect(page.locator('#search-engine-form')).toBeVisible();
    await expect(page.locator('#engine_type')).toBeVisible();

    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
