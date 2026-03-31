// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Membership';
const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
// 仅跳过明确的基础设施不可用（数据库会话引导失败）；其它错误应失败以便暴露环境/用例问题。
const BACKEND_BOOTSTRAP_INFRA_PATTERN =
  /Backend E2E session bootstrap failed:.*DB Error|SQLSTATE\[08006\]|\bconnection to server at "127\.0\.0\.1", port 5432 failed\b|timeout expired|ECONNREFUSED/i;

function uniqueCustomerId() {
  // Keep an integer and avoid colliding with existing fixtures.
  return 80000000 + (Date.now() % 10000000);
}

/** 后台会员保存表单：隐藏 membership_id + 保存按钮，排除列表筛选 GET 表单 */
function membershipSaveForm(page) {
  return page
    .locator('form')
    .filter({ has: page.locator('input[name="membership_id"]') })
    .filter({ has: page.getByRole('button', { name: /Save Membership|保存会员/i }) })
    .first();
}

function membershipFilterForm(page) {
  return page.locator('form[method="get"]').filter({ has: page.locator('input[name="customer_id"]') }).first();
}

/** 会员列表页（非 create 的 view、非 save POST 目标页） */
function isMembershipBackendIndexUrl(url) {
  const p = url.pathname;
  return (
    p.includes('/membership/backend/membership') &&
    !p.includes('/membership/save') &&
    !p.endsWith('/view') &&
    !p.endsWith('/view/')
  );
}

test.describe('WeShop Membership backend', () => {
  test.describe.configure({ retries: 0 });

  test.beforeEach(async ({ page }) => {
    try {
      await loginAsAdmin(page, { timeout: 120000, refreshRuntime: true });
    } catch (error) {
      const message = String(error?.message || error || '');
      if (BACKEND_BOOTSTRAP_INFRA_PATTERN.test(message)) {
        test.skip(true, `Backend infra unavailable for membership flow: ${message}`);
      }
      throw error;
    }
  });

  test('supports create and edit membership flow', async ({ page }) => {
    const indexRoute = buildModuleBackendRoute(MODULE_NAME, 'membership');
    const customerId = uniqueCustomerId();
    const initialPoints = 123;
    const updatedPoints = 456;

    try {
      await gotoBackend(page, indexRoute, {
        timeout: 90000,
        settleMs: 1000,
      });
    } catch (error) {
      const message = String(error?.message || error || '');
      if (BACKEND_BOOTSTRAP_INFRA_PATTERN.test(message)) {
        test.skip(true, `Backend infra unavailable before membership index: ${message}`);
      }
      throw error;
    }

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const indexText = await body.innerText().catch(() => '');
    test.skip(
      BACKEND_BOOTSTRAP_INFRA_PATTERN.test(indexText),
      'Backend infra unavailable during membership index render.',
    );
    await expect(body).not.toContainText(FATAL_PATTERN);

    // Step 1: open create page from the list and create a record.
    await Promise.all([
      page.waitForLoadState('domcontentloaded', { timeout: 90000 }),
      page.getByRole('link', { name: /Create Membership|创建会员/i }).click(),
    ]);
    const saveForm = membershipSaveForm(page);
    await expect(saveForm).toBeVisible({ timeout: 30000 });
    await expect(saveForm.locator('input[name="customer_id"]')).toBeVisible();
    const createText = await body.innerText().catch(() => '');
    test.skip(
      BACKEND_BOOTSTRAP_INFRA_PATTERN.test(createText),
      'Backend infra unavailable during membership create render.',
    );
    await expect(body).not.toContainText(FATAL_PATTERN);
    await expect(saveForm.locator('input[name="customer_id"]')).toBeEditable();
    await expect(saveForm.locator('select[name="level"]')).toBeVisible({ timeout: 15000 });
    // 与 MembershipService::getLevelOptions() 约定一致（不依赖 option 在无障碍树中的暴露方式）
    const levelAfterCreate = 'gold';
    const levelAfterEdit = 'platinum';

    await saveForm.locator('input[name="customer_id"]').fill(String(customerId));
    await saveForm.locator('select[name="level"]').selectOption(levelAfterCreate, { force: true });
    await expect(saveForm.locator('select[name="level"]')).toHaveValue(levelAfterCreate);
    await saveForm.locator('input[name="points"]').fill(String(initialPoints));
    await Promise.all([
      page.waitForURL(isMembershipBackendIndexUrl, { timeout: 90000 }),
      saveForm.locator('button[type="submit"]').click(),
    ]);

    await expect(body).not.toContainText(FATAL_PATTERN);
    // 保存成功可能重定向到列表且不带 ?id=，以表格行为准
    await expect(page.locator('table tbody')).toContainText(String(customerId), { timeout: 30000 });
    await expect(page.locator('table tbody')).toContainText(String(initialPoints), { timeout: 30000 });

    // Step 2: filter by customer id and open edit（已在列表则无需点「返回」）
    await expect(body).not.toContainText(FATAL_PATTERN);
    await membershipFilterForm(page).locator('input[name="customer_id"]').fill(String(customerId));
    await Promise.all([
      page.waitForURL(url => url.searchParams.get('customer_id') === String(customerId), { timeout: 90000 }),
      membershipFilterForm(page).getByRole('button', { name: /Apply Filters|应用筛选/i }).click(),
    ]);
    await expect(page.locator('table tbody')).toContainText(String(customerId));
    const listRowForCustomer = page.locator('table tbody tr').filter({ hasText: String(customerId) });
    await expect(listRowForCustomer).toHaveCount(1, { timeout: 30000 });
    await listRowForCustomer.getByRole('link', { name: /^Edit$|^编辑$/ }).click();
    // 以保存表单出现为准（避免 waitForURL 与 ?id= 竞态或仅地址栏变化）
    const editSaveForm = membershipSaveForm(page);
    await expect(editSaveForm).toBeVisible({ timeout: 90000 });
    await editSaveForm.locator('select[name="level"]').selectOption(levelAfterEdit, { force: true });
    await expect(editSaveForm.locator('select[name="level"]')).toHaveValue(levelAfterEdit);
    await editSaveForm.locator('input[name="points"]').fill(String(updatedPoints));
    // 保存成功后 Save 会重定向到列表；勿用 searchParams.has('id')，否则在 /view?id= 上过早满足
    await Promise.all([
      page.waitForURL(isMembershipBackendIndexUrl, { timeout: 90000 }),
      editSaveForm.locator('button[type="submit"]').click(),
    ]);

    await expect(body).not.toContainText(FATAL_PATTERN);
    const afterEditForm = membershipSaveForm(page);
    if (await afterEditForm.isVisible().catch(() => false)) {
      await expect(afterEditForm.locator('select[name="level"]')).toHaveValue(levelAfterEdit);
      await expect(afterEditForm.locator('input[name="points"]')).toHaveValue(String(updatedPoints));
    }

    // Step 4: verify persisted values in list page table.
    const backToList = page.getByRole('link', { name: /Back to Memberships|返回会员列表/i });
    if ((await backToList.count()) > 0 && (await backToList.first().isVisible().catch(() => false))) {
      await Promise.all([
        page.waitForURL(isMembershipBackendIndexUrl, { timeout: 90000 }),
        backToList.first().click(),
      ]);
    }
    await membershipFilterForm(page).locator('input[name="customer_id"]').fill(String(customerId));
    await Promise.all([
      page.waitForURL(url => url.searchParams.get('customer_id') === String(customerId), { timeout: 90000 }),
      membershipFilterForm(page).getByRole('button', { name: /Apply Filters|应用筛选/i }).click(),
    ]);
    const verifyRow = page.locator('table tbody tr').filter({ hasText: String(customerId) }).first();
    await expect(verifyRow).toBeVisible({ timeout: 30000 });
    // 再次进入 view 编辑页核对 DB 持久化（列表格积分列偶发与 badge 空白导致误判）
    await verifyRow.getByRole('link', { name: /^Edit$|^编辑$/ }).click();
    const verifyForm = membershipSaveForm(page);
    await expect(verifyForm).toBeVisible({ timeout: 90000 });
    await expect(verifyForm.locator('input[name="points"]')).toHaveValue(String(updatedPoints));
    await expect(verifyForm.locator('select[name="level"]')).toHaveValue(levelAfterEdit);
  });
});
