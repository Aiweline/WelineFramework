// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

function sleepMs(ms) {
  return new Promise(resolve => {
    setTimeout(resolve, ms);
  });
}

test.describe('WeShop Affiliate backend (smoke)', () => {
  test.describe.configure({ timeout: 240000, retries: 0 });

  /** 左侧「创建/编辑」POST 表单；与右侧 GET 筛选用 customer_id 分离，避免 strict 双匹配 */
  function affiliatePostForm(page) {
    return page.locator('.col-lg-4').first().locator('form[method="post"]');
  }

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  async function gotoBackendWithAbortRetry(page, route, options = {}) {
    try {
      return await gotoBackend(page, route, options);
    } catch (error) {
      const message = String(error?.message || '');
      if (!/ERR_ABORTED|frame was detached/i.test(message)) {
        throw error;
      }
      await sleepMs(1200);
      return gotoBackend(page, route, options);
    }
  }

  test('index, view (id=1), and list→detail→edit→save→detail', async ({ page }) => {
    let lastError;
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        await loginAsAdmin(page, {
          timeout: 90000,
          refreshRuntime: attempt > 0,
          settleMs: 1500,
        });
        lastError = null;
        break;
      } catch (error) {
        lastError = error;
        await sleepMs(3000);
      }
    }
    if (lastError) {
      throw lastError;
    }

    const route = buildModuleBackendRoute('WeShop_Affiliate', 'affiliate');
    await test.step('renders affiliate management index without PHP errors', async () => {
      await gotoBackendWithAbortRetry(page, route, {
        timeout: 60000,
        readySelector: 'html',
        settleMs: 1000,
      });
      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
    });

    await test.step('renders affiliate details page (id=1) without PHP errors', async () => {
      const viewRoute = buildModuleBackendRoute('WeShop_Affiliate', 'affiliate', 'view');
      await gotoBackendWithAbortRetry(page, `${viewRoute}?id=1`, {
        timeout: 60000,
        readySelector: 'html',
        settleMs: 1000,
      });
      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
    });

    await test.step('list -> detail -> edit -> save -> back to detail', async () => {
      await gotoBackendWithAbortRetry(page, route, {
        timeout: 60000,
        readySelector: 'html',
        settleMs: 1000,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);

      let viewButtons = page.locator('a.btn.btn-sm.btn-outline-info');
      if (await viewButtons.count() === 0) {
        const postForm = affiliatePostForm(page);
        await expect(postForm).toBeVisible();
        await postForm.locator('input[name="customer_id"]').fill('1');
        await postForm.locator('input[name="commission_rate"]').fill('0.10');
        await postForm.getByRole('button', { name: /Create Affiliate/i }).click();
        await page.waitForLoadState('domcontentloaded');
        await sleepMs(800);
        viewButtons = page.locator('a.btn.btn-sm.btn-outline-info');
      }
      if (await viewButtons.count() === 0) {
        test.skip(true, 'No affiliate rows after create attempt (e.g. customer_id=1 missing).');
      }

      const viewAction = page.locator('a.btn.btn-sm.btn-outline-info').first();
      await expect(viewAction).toBeVisible();
      await viewAction.click();
      await expect(page.locator('h1').filter({ hasText: /Affiliate Details/i }).first()).toBeVisible({
        timeout: 60000,
      });
      await expect(body).not.toContainText(FATAL_PATTERN);

      const backToListButton = page.getByRole('link', { name: /Back to List/i });
      await expect(backToListButton).toBeVisible();

      const editButton = page.getByRole('link', { name: /^Edit$/i });
      await editButton.click();
      await expect(page.getByRole('heading', { name: /Edit Affiliate Account/i })).toBeVisible({
        timeout: 60000,
      });
      await expect(page.getByRole('button', { name: /Update Affiliate/i })).toBeVisible({ timeout: 30000 });

      const rateInput = affiliatePostForm(page).locator('input[name="commission_rate"]');
      await expect(rateInput).toBeVisible();
      const currentRate = (await rateInput.inputValue()).trim();
      const nextRate = currentRate === '0.11' ? '0.12' : '0.11';
      await rateInput.fill(nextRate);

      const saveButton = page.getByRole('button', { name: /Update Affiliate|Save Changes/i });
      await saveButton.click();

      await expect(page.locator('h1').filter({ hasText: /Affiliate Details/i }).first()).toBeVisible({
        timeout: 60000,
      });
      await expect(page.locator('body')).toContainText(/Affiliate saved\./i);
      await expect(
        page.locator('.container-fluid').locator('input[name="commission_rate"]').first(),
      ).toHaveValue(nextRate);
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
    });
  });
});
