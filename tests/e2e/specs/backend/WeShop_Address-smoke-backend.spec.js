// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const WESHOP_ADDRESS_MODULE = 'WeShop_Address';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CUSTOMER_ID = '1';

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => {
    errors.push(String(error && error.message ? error.message : error));
  });
  return errors;
}

async function expectNoFatal(page) {
  await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN, { timeout: 15000 });
}

async function expectRowDefaultBadge(row) {
  await expect(row.locator('span.badge.bg-primary')).toBeVisible({ timeout: 10000 });
}

async function expectRowNoDefaultBadge(row) {
  await expect(row.locator('span.badge.bg-primary')).toHaveCount(0, { timeout: 10000 });
}

function makeAddressPayload(tag, isDefault) {
  return {
    customer_id: CUSTOMER_ID,
    is_default: isDefault ? '1' : '0',
    firstname: `E2E${tag}`,
    lastname: 'Address',
    contact_name: `E2E Contact ${tag}`,
    telephone: '13800138000',
    country: 'CN',
    province: 'Guangdong',
    city: 'Shenzhen',
    district: 'Nanshan',
    street: `Keji Ave ${tag}`,
    postcode: '518000',
  };
}

async function openAddressIndex(page) {
  const route = buildModuleBackendRoute(WESHOP_ADDRESS_MODULE, 'address');
  await gotoBackend(page, route, { timeout: 90000, settleMs: 1200 });
  await expectNoFatal(page);
}

async function openAddressOffcanvas(page) {
  await openAddressIndex(page);
  const trigger = page
    .locator('[data-bs-target*="offcanvasRightAddressForm"], [data-bs-target="#offcanvasRightAddressForm"]')
    .first();
  await expect(trigger).toBeVisible({ timeout: 10000 });
  await trigger.click({ force: true });
  await page.locator('.offcanvas.show').first().waitFor({ state: 'visible', timeout: 15000 });
  await expect(page.frameLocator('.offcanvas.show iframe').locator('form#address-form')).toBeVisible({ timeout: 15000 });
}

async function fillAddressOffcanvasForm(page, payload) {
  const frame = page.frameLocator('.offcanvas.show iframe');
  await frame.locator('input[name="customer_id"]').fill(payload.customer_id);
  await frame.locator('input[name="firstname"]').fill(payload.firstname);
  await frame.locator('input[name="lastname"]').fill(payload.lastname);
  await frame.locator('input[name="contact_name"]').fill(payload.contact_name);
  await frame.locator('input[name="telephone"]').fill(payload.telephone);
  await frame.locator('select[name="country"]').selectOption(payload.country);
  await frame.locator('input[name="province"]').fill(payload.province);
  await frame.locator('input[name="city"]').fill(payload.city);
  await frame.locator('input[name="district"]').fill(payload.district);
  await frame.locator('input[name="street"]').fill(payload.street);
  await frame.locator('input[name="postcode"]').fill(payload.postcode);

  const defaultSwitch = frame.locator('input[name="is_default"]');
  if (payload.is_default === '1') {
    await defaultSwitch.check({ force: true });
  } else {
    await defaultSwitch.uncheck({ force: true });
  }
}

async function submitAddressOffcanvas(page) {
  const responsePromise = page.waitForResponse(response =>
    response.request().method() === 'POST' && /\/address\/backend\/address\/save/.test(response.url()),
  { timeout: 30000 });
  await page.frameLocator('.offcanvas.show iframe').locator('button[type="submit"]').click({ force: true });
  const response = await responsePromise;
  const body = await response.json();
  expect(response.ok(), JSON.stringify(body)).toBeTruthy();
  expect(body.success, JSON.stringify(body)).toBeTruthy();
}

async function saveAddressByRequest(page, payload) {
  const route = buildModuleBackendRoute(WESHOP_ADDRESS_MODULE, 'address', 'save');
  const result = await page.evaluate(async ({ route, payload }) => {
    const body = new URLSearchParams(payload).toString();
    const response = await fetch(route, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body,
    });
    const json = await response.json();
    return { ok: response.ok, status: response.status, json };
  }, { route, payload });
  expect(result.ok, JSON.stringify(result)).toBeTruthy();
  expect(result.status).toBe(200);
  expect(result.json && result.json.success, JSON.stringify(result)).toBeTruthy();
}

async function confirmIfPrompted(page) {
  const candidates = [
    '.modal.show button.btn-primary',
    '.modal.show button:has-text("Confirm")',
    '.modal.show button:has-text("确定")',
    '.modal.show button:has-text("Yes")',
  ];
  for (const selector of candidates) {
    const button = page.locator(selector).first();
    if (await button.isVisible({ timeout: 800 }).catch(() => false)) {
      await button.click({ force: true });
      return;
    }
  }
}

test.describe('WeShop Address backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('TC-01: renders address list page', async ({ page }) => {
    await openAddressIndex(page);
    const table = page.locator('#address-table');
    await expect(table).toBeVisible({ timeout: 15000 });
    await expect(table.locator('thead th')).toHaveCount(7, { timeout: 10000 });
    const offcanvasTrigger = page
      .locator('[data-bs-target*="offcanvasRightAddressForm"], [data-bs-target="#offcanvasRightAddressForm"]')
      .first();
    await expect(offcanvasTrigger).toBeVisible({ timeout: 10000 });
  });

  test('TC-02: renders address edit page (empty form)', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_ADDRESS_MODULE, 'address', 'edit');
    await gotoBackend(page, route, { timeout: 90000, settleMs: 1200 });
    await expectNoFatal(page);
    const form = page.locator('form#address-form');
    await expect(form).toBeVisible({ timeout: 15000 });
    await expect(form.locator('input[name="customer_id"]')).toBeVisible({ timeout: 10000 });
    await expect(form.locator('input[name="firstname"]')).toBeVisible({ timeout: 10000 });
    await expect(form.locator('input[name="lastname"]')).toBeVisible({ timeout: 10000 });
    await expect(form.locator('select[name="country"]')).toBeVisible({ timeout: 10000 });
  });

  test('TC-03: saves address in offcanvas form', async ({ page }) => {
    const pageErrors = bindPageErrors(page);
    const tag = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const payload = makeAddressPayload(tag, true);

    await openAddressOffcanvas(page);
    await fillAddressOffcanvasForm(page, payload);
    await submitAddressOffcanvas(page);

    await expect(page.locator('.offcanvas.show')).toBeHidden({ timeout: 15000 });
    const savedRow = page.locator('#address-table tbody tr', { hasText: payload.contact_name }).first();
    await expect(savedRow).toBeVisible({ timeout: 20000 });
    await expectRowDefaultBadge(savedRow);
    await expectNoFatal(page);
    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  });

  test('TC-04: toggles default address from address list action', async ({ page }) => {
    const pageErrors = bindPageErrors(page);
    const seedTag = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const defaultPayload = makeAddressPayload(`${seedTag}-A`, true);
    const targetPayload = makeAddressPayload(`${seedTag}-B`, false);

    await saveAddressByRequest(page, defaultPayload);
    await saveAddressByRequest(page, targetPayload);

    await openAddressIndex(page);
    const targetRow = page.locator('#address-table tbody tr', { hasText: targetPayload.contact_name }).first();
    await expect(targetRow).toBeVisible({ timeout: 20000 });

    const setDefaultButton = targetRow.locator('button.btn-outline-success').first();
    await expect(setDefaultButton).toBeVisible({ timeout: 10000 });

    const responsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST' && /\/address\/backend\/address\/set-default/.test(response.url()),
    { timeout: 30000 });
    await setDefaultButton.click({ force: true });
    await confirmIfPrompted(page);
    const response = await responsePromise;
    const body = await response.json();
    expect(response.ok(), JSON.stringify(body)).toBeTruthy();
    expect(body.success, JSON.stringify(body)).toBeTruthy();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expectNoFatal(page);
    const refreshedPreviousDefaultRow = page.locator('#address-table tbody tr', { hasText: defaultPayload.contact_name }).first();
    const refreshedTargetRow = page.locator('#address-table tbody tr', { hasText: targetPayload.contact_name }).first();
    await expect(refreshedPreviousDefaultRow).toBeVisible({ timeout: 20000 });
    await expect(refreshedTargetRow).toBeVisible({ timeout: 20000 });
    await expectRowDefaultBadge(refreshedTargetRow);
    await expectRowNoDefaultBadge(refreshedPreviousDefaultRow);
    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  });
});

