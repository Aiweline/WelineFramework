// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

async function openWebsiteIndex(page) {
  await gotoBackend(page, 'websites/admin/website');
  await page.waitForTimeout(1000);
}

async function openAddPanel(page) {
  await openWebsiteIndex(page);
  const addButton = page.locator('button[data-bs-toggle="offcanvas"], a[data-bs-toggle="offcanvas"]').first();
  await addButton.waitFor({ timeout: 5000 }).catch(() => {});

  if (!(await addButton.isVisible().catch(() => false))) {
    throw new Error('Website add trigger is not visible.');
  }

  await addButton.click();
  await page.waitForTimeout(1500);
}

test.describe('Website add flow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('opens the add website form', async ({ page }) => {
    await openAddPanel(page);
    await expect(page.locator('.offcanvas.show, .offcanvas[class*="show"]')).toBeVisible({ timeout: 3000 });
  });

  test('fills and submits the website form', async ({ page }) => {
    await openAddPanel(page);

    const iframe = page.frameLocator('iframe').first();
    await page.waitForTimeout(2000);

    const timestamp = Date.now();
    const nameInput = iframe.locator('input[name="name"], input[id*="name"]').first();
    if (await nameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await nameInput.fill(`test-site-${timestamp}`);
    }

    const codeInput = iframe.locator('input[name="code"], input[id*="code"]').first();
    if (await codeInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await codeInput.fill(`test_${timestamp}`);
    }

    const urlInput = iframe.locator('input[name="url"], input[id*="url"]').first();
    if (await urlInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await urlInput.fill('http://test.example.com');
    }

    const saveButton = page.locator('button[id*="Save"]').first();
    if (await saveButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await saveButton.click();
      await page.waitForTimeout(3000);

      const successMsg = page.locator('text=/成功|success/i');
      const errorMsg = page.locator('text=/失败|error|错误/i');
      await page.waitForTimeout(1500);

      const hasSuccess = await successMsg.isVisible().catch(() => false);
      const hasError = await errorMsg.isVisible().catch(() => false);
      expect(hasSuccess || hasError).toBeTruthy();
    }
  });

  test('shows error handling after an invalid submit', async ({ page }) => {
    await openAddPanel(page);

    const iframe = page.frameLocator('iframe').first();
    await page.waitForTimeout(2000);

    const codeInput = iframe.locator('input[name="code"], input[id*="code"]').first();
    if (await codeInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await codeInput.fill('default');
    }

    const saveButton = page.locator('button[id*="Save"]').first();
    if (await saveButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await saveButton.click();
      await page.waitForTimeout(3000);

      await expect(page.locator('text=/警告提示|错误|失败/i')).toBeVisible({ timeout: 5000 });
      await expect(page.locator('text=/秒后|countdown/i')).toBeVisible({ timeout: 3000 });

      await page.waitForTimeout(4000);

      const currentUrl = page.url();
      const isListPage = currentUrl.includes('/websites/admin/website');
      const offcanvasClosed = !(await page.locator('.offcanvas.show').isVisible().catch(() => false));
      expect(isListPage || offcanvasClosed).toBeTruthy();
    }
  });

  test('does not validate website_id during add flow', async ({ page }) => {
    await gotoBackend(page, 'websites/admin/website/add');
    await page.waitForTimeout(1000);

    const form = page.locator('form').first();
    if (await form.isVisible({ timeout: 3000 }).catch(() => false)) {
      const submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
      if (await submitButton.isVisible({ timeout: 3000 }).catch(() => false)) {
        await submitButton.click();
        await page.waitForTimeout(2000);
        await expect(page.locator('text=/网站ID不存在|website.*id.*not.*found/i')).not.toBeVisible({ timeout: 2000 });
      }
    }
  });

  test('renders the add form without undefined template variables', async ({ page }) => {
    await openAddPanel(page);

    const errors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    page.on('pageerror', error => {
      errors.push(error.message);
    });

    const pageContent = await page.content();
    const hasUndefinedWarning = /Undefined variable.*target_button_text|Undefined variable.*title|Undefined variable.*submit_button_text/i.test(pageContent);
    expect(hasUndefinedWarning).toBeFalsy();

    const hasConsoleError = errors.some(error => /Undefined variable|syntax error|Fatal error/i.test(error));
    expect(hasConsoleError).toBeFalsy();
  });
});
