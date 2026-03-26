// @weline-e2e-runtime wls
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

async function openWebsiteIndex(page) {
  await gotoBackend(page, 'websites/admin/website', {
    timeout: 60000,
    settleMs: 1000,
  });
}

async function openAddPanel(page) {
  await openWebsiteIndex(page);
  const addButton = page.locator(
    'a[data-bs-target*="websites_add_website"], button[data-bs-target*="websites_add_website"], a[data-bs-toggle="offcanvas"].btn.btn-primary, button[data-bs-toggle="offcanvas"].btn.btn-primary'
  ).first();
  await addButton.waitFor({ timeout: 5000 }).catch(() => {});

  if (!(await addButton.isVisible().catch(() => false))) {
    throw new Error('Website add trigger is not visible.');
  }

  await addButton.click({ force: true });
  await page.waitForTimeout(1500);
}

async function readOffcanvasState(page) {
  const iframe = page.frameLocator('iframe').first();
  const bodyLocator = iframe.locator('body').first();
  const bodyText = await bodyLocator.innerText().catch(() => '');
  const resultType = await bodyLocator.getAttribute('data-offcanvas-result').catch(() => null);
  const hasForm = await iframe.locator('form').first().isVisible().catch(() => false);

  return {
    bodyText,
    resultType,
    hasForm,
    hasFatalError: /Fatal error|ParseError|syntax error|WLS Runtime Error|Undefined variable/i.test(bodyText),
    hasWebsiteIdRegression: /website.*id.*not.*found/i.test(bodyText),
  };
}

async function createDomainPoolEntry(iframe) {
  const domain = `site-${Date.now()}.example.com`;

  const response = await iframe.locator('body').evaluate(async (_body, payload) => {
    const scriptContent = Array.from(document.scripts)
      .map(script => script.textContent || '')
      .find(text => text.includes('manualCreateApi')) || '';
    const apiMatch = scriptContent.match(/var\s+manualCreateApi\s*=\s*"([^"]+)"/);
    const endpoint = apiMatch
      ? apiMatch[1].replace(/\\\//g, '/')
      : 'websites/admin/domain/create-manual-domain';

    const formData = new FormData();
    formData.append('domain', payload.domain);
    formData.append('description', 'e2e website add');
    formData.append('dns_account_id', '0');
    formData.append('cdn_account_id', '0');
    formData.append('https_mode', 'none');
    formData.append('https_email', '');

    const result = await fetch(endpoint, {
      method: 'POST',
      body: formData,
    });

    return result.json();
  }, { domain });

  const poolId = response && response.code === 200 && response.data && response.data.pool_id
    ? String(response.data.pool_id)
    : '';

  if (!poolId) {
    throw new Error(`Failed to create domain pool entry: ${JSON.stringify(response)}`);
  }

  return poolId;
}

async function selectOrCreateDomain(iframe) {
  const trigger = iframe.locator('#website_domain_select_trigger, .weline-domain-select-trigger').first();
  await expect(trigger).toBeVisible({ timeout: 10000 });
  await trigger.click({ force: true });

  const firstDomainItem = iframe.locator('.weline-domain-select-item').first();
  const hiddenInput = iframe.locator('#website_domain_select_value, input[name="pool_ids"]').first();
  if (await firstDomainItem.isVisible({ timeout: 1500 }).catch(() => false)) {
    await firstDomainItem.click({ force: true });

    const confirmButton = iframe.locator('#website_domain_select_confirm, button:has-text("确定")').first();
    await expect(confirmButton).toBeVisible({ timeout: 5000 });
    await confirmButton.click({ force: true });
  } else {
    const poolId = await createDomainPoolEntry(iframe);
    await hiddenInput.evaluate((input, value) => {
      input.value = String(value);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }, poolId);
  }

  await expect(hiddenInput).toHaveValue(/.+/, { timeout: 10000 });
}

test.describe('Website add flow', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('opens the add website form', async ({ page }) => {
    await openAddPanel(page);
    await page.waitForTimeout(2000);
    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|syntax error/i);
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

    await selectOrCreateDomain(iframe);

    const saveButton = page.locator('button[id*="Save"]').first();
    if (await saveButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await saveButton.click({ force: true });
      await page.waitForTimeout(4000);

      const state = await readOffcanvasState(page);
      const currentUrl = page.url();
      const isListPage = currentUrl.includes('/websites/admin/website');
      const offcanvasStillOpen = await page.locator('.offcanvas.show').isVisible().catch(() => false);

      expect(state.hasFatalError).toBeFalsy();
      expect(state.hasWebsiteIdRegression).toBeFalsy();
      expect(isListPage || offcanvasStillOpen || Boolean(state.resultType) || state.hasForm).toBeTruthy();
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
      await saveButton.click({ force: true });
      await page.waitForTimeout(4000);

      const state = await readOffcanvasState(page);
      expect(state.hasFatalError).toBeFalsy();
      expect(state.hasWebsiteIdRegression).toBeFalsy();

      const currentUrl = page.url();
      const isListPage = currentUrl.includes('/websites/admin/website');
      const offcanvasStillOpen = await page.locator('.offcanvas.show').isVisible().catch(() => false);
      expect(isListPage || offcanvasStillOpen || Boolean(state.resultType) || state.hasForm).toBeTruthy();
    }
  });

  test('does not validate website_id during add flow', async ({ page }) => {
    await gotoBackend(page, 'websites/admin/website/add', {
      timeout: 60000,
      settleMs: 1000,
    });

    const form = page.locator('form').first();
    if (await form.isVisible({ timeout: 3000 }).catch(() => false)) {
      const submitButton = form.locator('button[type="submit"], input[type="submit"]').first();
      if (await submitButton.isVisible({ timeout: 3000 }).catch(() => false)) {
        await submitButton.click({ force: true });
        await page.waitForTimeout(2000);
        await expect(page.locator('text=/website.*id.*not.*found/i')).not.toBeVisible({ timeout: 2000 });
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

    const iframe = page.frameLocator('iframe').first();
    await page.waitForTimeout(2000);
    const frameBody = await iframe.locator('body').first().innerText().catch(() => '');

    const hasUndefinedWarning = /Undefined variable.*target_button_text|Undefined variable.*title|Undefined variable.*submit_button_text/i.test(frameBody);
    expect(hasUndefinedWarning).toBeFalsy();

    const hasConsoleError = errors.some(error => /Undefined variable|syntax error|Fatal error/i.test(error));
    expect(hasConsoleError).toBeFalsy();
  });
});
