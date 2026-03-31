// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, getRuntimeInfo } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'WeShop_Address';
const CUSTOMER_ID = '1';

function makePayload(tag, isDefault) {
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

async function openIndex(page) {
  // buildModuleBackendRoute returns 'address/backend/address'; gotoBackend prepends @backend literally.
  // Use direct URL construction with the real prefix to avoid @backend not being substituted.
  const runtime = getRuntimeInfo();
  const backendPrefix = runtime.routes.backend;
  const route = buildModuleBackendRoute(MODULE, 'address');
  const targetUrl = `${runtime.proxy.origin}/${backendPrefix}/${route}`;
  await page.goto(targetUrl, { timeout: 90000, waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  // Check for PHP fatal before proceeding
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const hasFatal = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Class .* not found/i.test(bodyText);
  if (hasFatal) {
    throw new Error('PHP fatal on address list page: ' + bodyText.substring(0, 200));
  }
  await expect(page.locator('#address-table')).toBeVisible({ timeout: 20000 });
}

async function expectRowDefaultBadge(row) {
  await expect(row.locator('span.badge.bg-primary')).toBeVisible({ timeout: 10000 });
}

async function openOffcanvas(page) {
  await openIndex(page);
  const trigger = page
    .locator('[data-bs-target*="offcanvasRightAddressForm"], [data-bs-target="#offcanvasRightAddressForm"]')
    .first();
  await expect(trigger).toBeVisible({ timeout: 10000 });
  await trigger.click({ force: true });
  await expect(page.locator('.offcanvas.show')).toBeVisible({ timeout: 15000 });
  await expect(page.frameLocator('.offcanvas.show iframe').locator('form#address-form')).toBeVisible({ timeout: 15000 });
}

async function fillAndSubmit(page, payload) {
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

  const responsePromise = page.waitForResponse(response =>
    response.request().method() === 'POST' && /\/address\/backend\/address\/save/.test(response.url()),
  { timeout: 30000 });
  await frame.locator('button[type="submit"]').click({ force: true });
  const response = await responsePromise;
  const body = await response.json();
  expect(response.ok(), JSON.stringify(body)).toBeTruthy();
  expect(body.success, JSON.stringify(body)).toBeTruthy();
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

async function saveByRequest(page, payload) {
  const route = buildModuleBackendRoute(MODULE, 'address', 'save');
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

test.describe('WeShop Address backend offcanvas/default', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('offcanvas 保存后关闭并回到列表', async ({ page }) => {
    const payload = makePayload(`${Date.now()}-${Math.floor(Math.random() * 1000)}`, true);
    await openOffcanvas(page);
    await fillAndSubmit(page, payload);
    // Wait for offcanvas close animation to start before asserting hidden
    await page.waitForTimeout(1000);
    await expect(page.locator('.offcanvas.show')).toBeHidden({ timeout: 15000 });
  });

  test('列表操作切换默认地址', async ({ page }) => {
    const seed = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const defaultPayload = makePayload(`${seed}-A`, true);
    const targetPayload = makePayload(`${seed}-B`, false);

    await saveByRequest(page, defaultPayload);
    await saveByRequest(page, targetPayload);

    // openIndex navigates to the list; reload to force DataTable to re-fetch with new rows
    const runtime = getRuntimeInfo();
    const backendPrefix = runtime.routes.backend;
    const route = buildModuleBackendRoute(MODULE, 'address');
    const targetUrl = `${runtime.proxy.origin}/${backendPrefix}/${route}`;
    await page.goto(targetUrl, { timeout: 90000, waitUntil: 'domcontentloaded' });
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasFatal = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Class .* not found/i.test(bodyText);
    if (hasFatal) {
      throw new Error('PHP fatal on address list page: ' + bodyText.substring(0, 200));
    }
    // Wait for DataTable to finish loading (serverSide AJAX completes)
    await expect(page.locator('#address-table')).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(3000);
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
    const refreshedTargetRow = page.locator('#address-table tbody tr', { hasText: targetPayload.contact_name }).first();
    await expectRowDefaultBadge(refreshedTargetRow);
  });
});
