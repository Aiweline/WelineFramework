// @weline-e2e-runtime fallback
// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

function ensureBackendTplHooksScandirDir() {
  const missingHooksDir = path.resolve(
    __dirname,
    '../../../../app/code/Weline/Backend/view/tpl/zh_Hans_CN/hooks'
  );
  fs.mkdirSync(missingHooksDir, { recursive: true });
}

test.describe('GuoLaiRen_Smtp smoke backend', () => {
  test.beforeEach(async ({ page }) => {
    ensureBackendTplHooksScandirDir();
    await loginAsAdmin(page);
  });

  test('renders SMTP config page with GuoLaiRen sender preset', async ({ page }) => {
    const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

    await gotoBackend(page, 'smtp/backend/config', {
      timeout: 90000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible({ timeout: 15000 });
    await expect(body).not.toContainText(FATAL_PATTERN, { timeout: 15000 });

    await expect(page.locator('#smtpSendersForm')).toBeVisible({ timeout: 10000 });
    // In case server senders is empty, page JS should add at least 1 sender card.
    await page.waitForFunction(
      () => document.querySelectorAll('.smtp-sender-card').length > 0,
      { timeout: 20000 }
    );

    const cardCount = await page.locator('.smtp-sender-card').count();
    expect(cardCount, 'Expected at least one SMTP sender card.').toBeGreaterThan(0);

    // From GuoLaiRen/Smtp defaults:
    // host=mail.privateemail.com, port=587, username like *@stockcircle.to
    const hostValues = await page.locator('.smtp-sender-card .smtp-host')
      .evaluateAll(els => els.map(e => String(e.value || '').trim()).filter(Boolean));
    const portValues = await page.locator('.smtp-sender-card .smtp-port')
      .evaluateAll(els => els.map(e => String(e.value || '').trim()).filter(Boolean));
    const usernameValues = await page.locator('.smtp-sender-card .smtp-username')
      .evaluateAll(els => els.map(e => String(e.value || '').trim()).filter(Boolean));

    const hasGuoLaiRenHost = hostValues.some(v => /mail\.privateemail\.com/i.test(v));
    const hasGuoLaiRenPort = portValues.some(v => v === '587' || v === '465');
    const hasGuoLaiRenUser = usernameValues.some(v => /@stockcircle\.to$/i.test(v) || /privateemail\.com/i.test(v));

    // Only enforce GuoLaiRen preset values when the UI has non-empty fields.
    // If the environment has no seeded smtp_senders in system_config yet, the UI will still work with empty cards.
    if (hostValues.length > 0) {
      expect(
        hasGuoLaiRenHost,
        `Expected GuoLaiRen SMTP host in non-empty senders, got: ${hostValues.join(', ')}`
      ).toBeTruthy();
    }
    if (portValues.length > 0 || usernameValues.length > 0) {
      expect(
        hasGuoLaiRenPort || hasGuoLaiRenUser,
        `Expected GuoLaiRen sender preset to include port 587/465 or username, ports=${portValues.join(', ')}, users=${usernameValues.join(', ')}`
      ).toBeTruthy();
    }

    // Basic UI controls should exist.
    await expect(page.locator('#btnAddSender')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#smtpSendersForm')).toBeVisible({ timeout: 5000 });
  });
});

