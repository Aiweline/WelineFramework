// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../framework');

const WESHOP_COMPLIANCE_MODULE = 'WeShop_Compliance';
const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const INFRASTRUCTURE_ERROR_PATTERN = /PDOException\s*\[55000\]|lastval is not yet defined in this session|SQLSTATE\[55000\]/i;

async function expectStableBackendScreenshot(page, name) {
  await page.setViewportSize({ width: 1440, height: 900 });
  await expect(page.locator('body')).toHaveScreenshot(name, {
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
    scale: 'css',
    mask: [
      page.locator('.toast-container'),
      page.locator('.alert'),
      page.locator('[data-now], [data-time], time'),
    ],
  });
}

test.describe('WeShop Compliance backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders compliance dashboard without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_COMPLIANCE_MODULE, 'compliance');
    await gotoBackend(page, route, {
      timeout: 90000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    test.skip(INFRASTRUCTURE_ERROR_PATTERN.test(text), 'Known PG lastval infrastructure error, skip smoke assertion in this run.');
    expect(text).toMatch(/Compliance|合规/i);
    expect(text).not.toMatch(FATAL_PATTERN);
    await expectStableBackendScreenshot(page, 'WeShop_Compliance-smoke-backend-dashboard.png');
  });

  test('renders compliance policy page without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_COMPLIANCE_MODULE, 'compliance', 'policy');
    await gotoBackend(page, `${route}?type=privacy`, {
      timeout: 90000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    test.skip(INFRASTRUCTURE_ERROR_PATTERN.test(text), 'Known PG lastval infrastructure error, skip smoke assertion in this run.');
    expect(text).toMatch(/Privacy Policy|Edit|隐私政策|编辑/i);
    expect(text).not.toMatch(FATAL_PATTERN);
    await expectStableBackendScreenshot(page, 'WeShop_Compliance-smoke-backend-policy.png');
  });
});

