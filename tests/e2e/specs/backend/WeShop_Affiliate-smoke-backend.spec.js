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

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
  const PURE_ERROR_PATTERN = /^\s*(404|500)\s*$/;
  const E2E_CUSTOMER_ID = Number(process.env.WESHOP_AFFILIATE_E2E_CUSTOMER_ID || 90527001);

  function affiliatePostForm(page) {
    return page.locator('.col-lg-4').first().locator('form[method="post"]');
  }

  function affiliateFilterForm(page) {
    return page.locator('.col-lg-8').first().locator('form[method="get"]');
  }

  async function attachAcceptanceScreenshot(page, testInfo, name) {
    const safeName = String(name).replace(/[^\w-]+/g, '-');
    const screenshotPath = testInfo.outputPath(`${safeName}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: true });
    await testInfo.attach(safeName, {
      path: screenshotPath,
      contentType: 'image/png',
    });
  }

  async function expectHealthyPage(page, response, context) {
    if (response) {
      const status = response.status();
      expect(status, `${context} should not return HTTP 404`).not.toBe(404);
      expect(status, `${context} should not return HTTP 500`).not.toBe(500);
      expect(status, `${context} should return a successful document response`).toBeLessThan(400);
    }

    const body = page.locator('body');
    await expect(body, `${context} body should be visible`).toBeVisible();
    await expect(body, `${context} should not render a pure 404/500 body`).not.toHaveText(PURE_ERROR_PATTERN);
    await expect(body, `${context} should not contain PHP/WLS fatal output`).not.toContainText(FATAL_PATTERN);
  }

  async function expectAffiliateDetailsPage(page) {
    await expect(page.locator('.ws-affiliate-detail')).toBeVisible({ timeout: 60000 });
    await expect(page.locator('#affiliate-referral-link')).toBeVisible({ timeout: 60000 });
    await expect(page.locator('.ws-affiliate-detail form[method="post"] input[name="affiliate_id"]').first()).toHaveValue(/\d+/, {
      timeout: 30000,
    });
  }

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

  async function clickAndExpectNavigation(page, clickAction, context) {
    const [response] = await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }),
      clickAction(),
    ]);
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await expectHealthyPage(page, response, context);
    return response;
  }

  async function filterByCustomerId(page, customerId) {
    const filterForm = affiliateFilterForm(page);
    await expect(filterForm).toBeVisible({ timeout: 30000 });
    await filterForm.locator('input[name="customer_id"]').fill(String(customerId));
    await filterForm.locator('input[name="referral_code"]').fill('');
    await filterForm.locator('select[name="status"]').selectOption('');
    await clickAndExpectNavigation(
      page,
      () => filterForm.locator('button[type="submit"]').click(),
      `filter customer ${customerId}`,
    );
  }

  test('accepts index, create, view, edit-save, and validation states', async ({ page }, testInfo) => {
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

    await test.step('renders affiliate management index with route health gates', async () => {
      const response = await gotoBackendWithAbortRetry(page, route, {
        timeout: 60000,
        readySelector: 'html',
        settleMs: 1000,
      });

      await expectHealthyPage(page, response, 'affiliate index');
      await expect(page.locator('.ws-affiliate-admin')).toBeVisible({ timeout: 30000 });
      await expect(affiliatePostForm(page).locator('input[name="customer_id"]')).toHaveValue('');
      await attachAcceptanceScreenshot(page, testInfo, 'affiliate-index-accepted');
    });

    await test.step('rejects invalid commission values before submit', async () => {
      const postForm = affiliatePostForm(page);
      await postForm.locator('input[name="customer_id"]').fill(String(E2E_CUSTOMER_ID + 1));
      const rateInput = postForm.locator('input[name="commission_rate"]');
      await rateInput.fill('1.25');

      const validity = await rateInput.evaluate(input => ({
        valid: input.validity.valid,
        rangeOverflow: input.validity.rangeOverflow,
      }));

      expect(validity.valid).toBe(false);
      expect(validity.rangeOverflow).toBe(true);
      await attachAcceptanceScreenshot(page, testInfo, 'affiliate-invalid-rate-accepted');
    });

    await test.step('creates or updates a deterministic affiliate through the admin form', async () => {
      const postForm = affiliatePostForm(page);
      await postForm.locator('input[name="customer_id"]').fill(String(E2E_CUSTOMER_ID));
      await postForm.locator('input[name="commission_rate"]').fill('0.13');
      await postForm.locator('select[name="status"]').selectOption('active');

      await clickAndExpectNavigation(
        page,
        () => postForm.locator('button[type="submit"]').click(),
        'create affiliate submit',
      );

      await filterByCustomerId(page, E2E_CUSTOMER_ID);
      const row = page.locator('.ws-affiliate-admin tbody tr').first();
      await expect(row).toBeVisible({ timeout: 30000 });
      await expect(row.locator('td').nth(1)).toHaveText(String(E2E_CUSTOMER_ID));
      await expect(row.locator('td').nth(3)).toContainText('13.00%');
      await attachAcceptanceScreenshot(page, testInfo, 'affiliate-created-filtered-accepted');
    });

    await test.step('list -> detail -> edit -> save -> detail is accepted', async () => {
      const viewAction = page.locator('a.btn.btn-sm.btn-outline-info').first();
      await expect(viewAction).toBeVisible();
      await clickAndExpectNavigation(page, () => viewAction.click(), 'open affiliate detail');
      await expectAffiliateDetailsPage(page);
      await attachAcceptanceScreenshot(page, testInfo, 'affiliate-detail-accepted');

      const backToListButton = page.locator('.ws-affiliate-detail a.ws-btn:not(.ws-btn-primary)[href*="/affiliate/backend/affiliate"]').first();
      await expect(backToListButton).toBeVisible();

      const editButton = page.locator('.ws-affiliate-detail a.ws-btn.ws-btn-primary[href*="/affiliate/backend/affiliate?id="]').first();
      await expect(editButton).toBeVisible();
      await clickAndExpectNavigation(page, () => editButton.click(), 'open affiliate edit form');

      const editForm = affiliatePostForm(page);
      await expect(editForm.locator('input[name="affiliate_id"]')).toHaveValue(/\d+/, { timeout: 60000 });
      await expect(editForm.locator('button[type="submit"]')).toBeVisible({ timeout: 30000 });

      const rateInput = editForm.locator('input[name="commission_rate"]');
      await expect(rateInput).toBeVisible();
      const nextRate = '0.14';
      await rateInput.fill(nextRate);

      await clickAndExpectNavigation(page, () => editForm.locator('button[type="submit"]').click(), 'save affiliate edit');

      await expectAffiliateDetailsPage(page);
      await expect(
        page.locator('.ws-affiliate-detail').locator('input[name="commission_rate"]').first(),
      ).toHaveValue(nextRate);
      await attachAcceptanceScreenshot(page, testInfo, 'affiliate-edit-save-accepted');

      await gotoBackendWithAbortRetry(page, `${route}?customer_id=${E2E_CUSTOMER_ID}`, {
        timeout: 60000,
        readySelector: 'html',
        settleMs: 1000,
      }).then(response => expectHealthyPage(page, response, 'affiliate filtered list after edit'));

      const row = page.locator('.ws-affiliate-admin tbody tr').first();
      await expect(row.locator('td').nth(1)).toHaveText(String(E2E_CUSTOMER_ID));
      await expect(row.locator('td').nth(3)).toContainText('14.00%');
    });
  });
});
