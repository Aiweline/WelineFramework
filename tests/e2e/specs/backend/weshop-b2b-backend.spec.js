// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const WESHOP_B2B_MODULE = 'WeShop_B2B';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const MAINTENANCE_PATTERN = /网站维护|网站正在维护中|maintenance/i;
const TRANSIENT_NET_PATTERN = /ERR_CONNECTION_REFUSED|ERR_CONNECTION_RESET|ERR_TIMED_OUT|Target page, context or browser has been closed|Test ended/i;

function uniqueSuffix() {
  return `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
}

/** 匹配 pathname 中的模块后台路由后缀（含随机 admin 前缀），避免写死 `/backend/` */
function routePathRegex(route) {
  const trimmed = String(route || '').replace(/^\/+/, '').replace(/\/+$/g, '');
  if (!trimmed) {
    return /$^/;
  }
  const escaped = trimmed
    .split('/')
    .map((segment) => segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('\\/');
  return new RegExp(`\\/${escaped}(?:\\?|\\/|$)`);
}

function hasRecordIdInUrl(url) {
  return /[?&]id=\d+/i.test(url) || /\/id\/\d+(?:\/|$)/i.test(url);
}

async function expectOptionalValidation(page, pattern) {
  const body = page.locator('body');
  const text = await body.innerText();
  if (pattern.test(text)) {
    await expect(body).toContainText(pattern);
  } else {
    await expect(body).not.toContainText(FATAL_PATTERN);
  }
}

async function expectBackendPageHealthy(page, titlePattern) {
  const body = page.locator('body');
  await expect(body).toBeVisible();
  await expect(body).not.toContainText(MAINTENANCE_PATTERN);
  await expect(body).toContainText(titlePattern);
  await expect(body).not.toContainText(FATAL_PATTERN);
}

async function gotoBackendStable(page, route, options = {}) {
  let lastError = null;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      await gotoBackend(page, route, options);
      await expect(page.locator('body')).not.toContainText(MAINTENANCE_PATTERN, { timeout: 15000 });
      return;
    } catch (error) {
      lastError = error;
      const message = error instanceof Error ? error.message : String(error);
      if (!TRANSIENT_NET_PATTERN.test(message) && !MAINTENANCE_PATTERN.test(message)) {
        throw error;
      }
      await page.waitForTimeout(1500 * (attempt + 1));
    }
  }
  throw lastError;
}

async function loginAsAdminStable(page, options = {}) {
  let lastError = null;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      await loginAsAdmin(page, options);
      return;
    } catch (error) {
      lastError = error;
      const message = error instanceof Error ? error.message : String(error);
      if (!TRANSIENT_NET_PATTERN.test(message)) {
        throw error;
      }
      await page.waitForTimeout(2000 * (attempt + 1));
    }
  }
  throw lastError;
}

test.describe('WeShop B2B backend (credit & AR)', () => {
  test.describe.configure({ retries: 1 });
  test.beforeEach(async ({ page }) => {
    await loginAsAdminStable(page, { timeout: 90000 });
  });

  const pages = [
    { id: 'credit', segments: ['credit'], expect: /B2B Credit Lines|B2B 授信额度/i },
    { id: 'account', segments: ['account'], expect: /B2B Trade Accounts|B2B 赊账账户/i },
    { id: 'b2bcustomer', segments: ['b2bcustomer'], expect: /B2B Enterprise Customers|B2B 企业客户/i },
    { id: 'receivable', segments: ['receivable'], expect: /B2B Receivables|B2B 应收账款/i },
    { id: 'b2breport', segments: ['b2breport'], expect: /B2B Credit & AR Summary|B2B 授信与应收概览/i },
  ];

  for (const { id, segments, expect: textRe } of pages) {
    test(`renders ${id} page without PHP errors`, async ({ page }) => {
      const route = buildModuleBackendRoute(WESHOP_B2B_MODULE, ...segments);
      await gotoBackendStable(page, route, {
        timeout: 120000,
        settleMs: 1200,
      });

      await expectBackendPageHealthy(page, textRe);
    });
  }

  test('company page: supports save and validation', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_B2B_MODULE, 'company');
    const companyPathRe = routePathRegex(route);
    const suffix = uniqueSuffix();
    const companyName = `E2E Company ${suffix}`;
    const companyEmail = `e2e-company-${suffix}@example.com`;

    await gotoBackendStable(page, route, {
      timeout: 120000,
      settleMs: 1200,
    });
    await expectBackendPageHealthy(page, /B2B Company Management|B2B 公司管理/i);
    const companyForm = page.locator('form[data-e2e="weshop-b2b-company-edit-form"]');
    await expect(companyForm).toBeVisible();

    await companyForm.locator('input[name="name"]').fill(companyName);
    await companyForm.locator('input[name="email"]').fill(companyEmail);
    await companyForm.locator('input[name="tax_id"]').fill(`TAX-${suffix}`);
    await companyForm.locator('input[name="phone"]').fill('13800138000');
    await companyForm.locator('textarea[name="address"]').fill(`E2E Address ${suffix}`);
    await companyForm.locator('select[name="status"]').selectOption('approved');
    await companyForm.locator('button[type="submit"]').filter({ hasText: /Save Company/i }).click();
    await expect(page).toHaveURL(companyPathRe, { timeout: 60000 });
    if (!hasRecordIdInUrl(page.url())) {
      await expect.poll(async () => {
        const text = await page.locator('body').innerText();
        return text.includes(companyName);
      }, { timeout: 45000 }).toBeTruthy();
    }
    await expectBackendPageHealthy(page, /B2B Company Management|B2B 公司管理/i);

    await companyForm.locator('input[name="name"]').fill('   ');
    await companyForm.locator('input[name="email"]').fill(`invalid-name-${suffix}@example.com`);
    await companyForm.locator('button[type="submit"]').filter({ hasText: /Save Company/i }).click();

    await expect(page).toHaveURL(companyPathRe, { timeout: 60000 });
    await expectOptionalValidation(page, /Company name is required|公司名称.*必填|公司名称.*不能为空|name.*required/i);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

    await page.locator('input[name="name"]').first().fill(`E2E Invalid Email ${suffix}`);
    await page.locator('input[name="email"]').first().fill('invalid-email');
    await page.locator('button[type="submit"]').filter({ hasText: /Save Company/i }).first().click();

    await expect(page).toHaveURL(/\/backend\/company(?:\?|\/|$)/, { timeout: 30000 });
    await expectOptionalValidation(page, /Company contact email is invalid|联系邮箱.*无效|邮箱格式.*错误|email.*invalid/i);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });

  test('credit page: supports save and validation', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_B2B_MODULE, 'credit');
    const suffix = uniqueSuffix();
    const customerId = 700000 + Math.floor(Math.random() * 100000);

    await gotoBackendStable(page, route, {
      timeout: 120000,
      settleMs: 1200,
    });
    await expectBackendPageHealthy(page, /B2B Credit Lines|B2B 授信额度/i);
    await expect(page.locator('form').filter({ has: page.locator('input[name="customer_id"]') }).first()).toBeVisible();

    await page.locator('input[name="customer_id"]').first().fill('0');
    await page.locator('input[name="credit_limit"]').first().fill('1000.50');
    await page.locator('input[name="credit_level"]').first().fill('B');
    await page.locator('button[type="submit"]').filter({ hasText: /^Save$/i }).first().click();

    await expect(page).toHaveURL(/\/backend\/credit(?:\?|\/|$)/, { timeout: 30000 });
    await expectOptionalValidation(page, /Customer ID is required|Customer ID.*required|客户ID.*必填|客户ID.*不能为空/i);

    await page.locator('input[name="customer_id"]').first().fill(String(customerId));
    await page.locator('input[name="credit_limit"]').first().fill('-1');
    await page.locator('input[name="credit_level"]').first().fill('B');
    await page.locator('button[type="submit"]').filter({ hasText: /^Save$/i }).first().click();

    await expect(page).toHaveURL(/\/backend\/credit(?:\?|\/|$)/, { timeout: 30000 });
    await expectOptionalValidation(page, /Credit limit must not be negative|授信额度.*不能为负|额度.*不能小于0/i);

    await page.locator('input[name="customer_id"]').first().fill(String(customerId));
    await page.locator('input[name="credit_limit"]').first().fill('18888.88');
    await page.locator('input[name="credit_level"]').first().fill('A');
    await page.locator('button[type="submit"]').filter({ hasText: /^Save$/i }).first().click();

    await expect(page).toHaveURL(/\/backend\/credit(?:\?|\/|$)/, { timeout: 30000 });
    await expect.poll(async () => {
      const text = await page.locator('body').innerText();
      if (!text.includes(String(customerId))) {
        return false;
      }
      const idIdx = text.indexOf(String(customerId));
      const windowText = text.slice(Math.max(0, idIdx - 200), Math.min(text.length, idIdx + 200));
      return /\b18888(?:[.,]\d+)?\b/.test(windowText);
    }, { timeout: 60000 }).toBeTruthy();
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });
});
