// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  buildModuleBackendRoute,
} = require('../../framework');

const WESHOP_NOTIFICATION_MODULE = 'WeShop_Notification';

test.describe('WeShop Notification backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test('renders notification management index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders notification detail page (id=1) without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification', 'view');

    // id=1 可能不存在：控制器会回退到列表；这里主要验证不出现 PHP 致命错误。
    await gotoBackend(page, `${route}?id=1`, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('redirects mark-read request without notification_id back to index with error message', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification', 'mark-read');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);
    await expect(page.locator('body')).toContainText(/Notification ID is required\./i);
  });

  test('marks first unread notification as read from index list', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);

    const unreadRow = page.locator('tbody tr', {
      has: page.locator('button:has-text("Mark as Read")'),
    }).first();
    const unreadCount = await unreadRow.count();
    test.skip(unreadCount === 0, 'No unread notification row available for mark-as-read flow.');

    await unreadRow.locator('button:has-text("Mark as Read")').click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);
    await expect(page.locator('body')).toContainText(/Notification marked as read\./i);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });

  test('keeps selected filters on notification index query', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification');
    await gotoBackend(page, `${route}?customer_id=88&type=order&is_read=0&title=E2E-Notice`, {
      timeout: 60000,
      settleMs: 1000,
    });

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);
    await expect(page.locator('input[name="customer_id"]')).toHaveValue('88');
    await expect(page.locator('select[name="type"]')).toHaveValue('order');
    await expect(page.locator('select[name="is_read"]')).toHaveValue('0');
    await expect(page.locator('input[name="title"]')).toHaveValue('E2E-Notice');
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });

  test('opens first notification detail from index view action', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);

    const detailLink = page.locator('tbody tr a:has-text("View")').first();
    const hasDetailLink = (await detailLink.count()) > 0;
    test.skip(!hasDetailLink, 'No notification row available for detail view flow.');

    await detailLink.click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('h1.h3')).toContainText(/Notification Detail/i);
    await expect(page).toHaveURL(/\/notification\/view\?id=\d+/i);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });

  test('marks unread notification as read from detail page and shows read-state feedback', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_NOTIFICATION_MODULE, 'notification');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    await expect(page.locator('h1.h3')).toContainText(/Notification Management/i);

    const unreadDetailLink = page.locator('tbody tr', {
      has: page.locator('button:has-text("Mark as Read")'),
    }).first().locator('a:has-text("View")');
    const hasUnreadDetail = (await unreadDetailLink.count()) > 0;
    test.skip(!hasUnreadDetail, 'No unread notification row available for detail mark-read flow.');

    await unreadDetailLink.click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('h1.h3')).toContainText(/Notification Detail/i);

    const markAsReadButton = page.locator('button:has-text("Mark as Read")');
    const canMarkFromDetail = (await markAsReadButton.count()) > 0;
    test.skip(!canMarkFromDetail, 'Selected notification is already read.');

    await markAsReadButton.click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('body')).toContainText(/Notification marked as read\./i);
    await expect(page.locator('body')).toContainText(/Already marked as read\./i);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });
});

