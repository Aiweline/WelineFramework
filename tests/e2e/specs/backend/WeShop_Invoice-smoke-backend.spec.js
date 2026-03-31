// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Invoice';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const FIXTURE_SCRIPT = path.resolve(__dirname, '../../framework/invoice-pending-fixture.php');

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

function createPendingInvoiceFixture() {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: path.resolve(__dirname, '../../..'),
    env: process.env,
    encoding: 'utf8',
  });
  return JSON.parse(stdout);
}

test.describe('WeShop_Invoice backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, {
      // Skip PHP-side bootstrap to avoid occasional local session bootstrap hangs.
      // Fall back to direct UI login flow for stability.
      bootstrapModes: [],
    });
  });

  test('TC-01: renders invoice index page without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'invoice');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-02: renders invoice detail route without fatal errors', async ({ page }) => {
    const fixture = createPendingInvoiceFixture();
    const invoiceId = Number(fixture.invoice_id || 0);
    expect(invoiceId).toBeGreaterThan(0);
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'invoice', 'view');

    await gotoBackend(page, `${route}?id=${invoiceId}`, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-03: issues a pending invoice and observes status transition', async ({ page }) => {
    const fixture = createPendingInvoiceFixture();
    const invoiceId = Number(fixture.invoice_id || 0);
    const invoiceNumber = String(fixture.invoice_number || '');
    expect(invoiceId).toBeGreaterThan(0);
    expect(invoiceNumber).not.toBe('');

    const listRoute = buildModuleBackendRoute(MODULE_NAME, 'invoice');
    await gotoBackend(page, `${listRoute}?invoice_number=${encodeURIComponent(invoiceNumber)}`, {
      timeout: 60000,
      settleMs: 1000,
    });

    const row = page.locator(`tbody tr[data-invoice-number="${invoiceNumber}"]`).first();
    await expect(row).toBeVisible();
    await expect(row.locator('[data-status-code]')).toHaveAttribute('data-status-code', 'pending');

    const issueButton = row.locator('[data-action="issue-invoice"]').first();
    await expect(issueButton).toBeVisible();
    await issueButton.click();

    await page.waitForLoadState('domcontentloaded', { timeout: 60000 });
    if (page.url().includes('/invoice/view')) {
    await expect(page.locator('body')).toContainText(/Invoice issued\./i);
    await expect(page.locator('[data-status-code]')).toHaveAttribute('data-status-code', 'issued');
    await expect(page.locator('[data-action="issue-invoice"]')).toHaveCount(0);
    }

    await gotoBackend(page, `${listRoute}?invoice_number=${encodeURIComponent(invoiceNumber)}`, {
      timeout: 60000,
      settleMs: 1000,
    });
    const issuedRow = page.locator(`tbody tr[data-invoice-number="${invoiceNumber}"]`).first();
    await expect(issuedRow).toBeVisible();
    await expect(issuedRow.locator('[data-status-code]')).toHaveAttribute('data-status-code', 'issued');
    await expect(issuedRow.locator('[data-action="issue-invoice"]')).toHaveCount(0);
  });
});
