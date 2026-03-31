const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--ignore-certificate-errors'] });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();

  // Login first
  const runtime = { proxy: { origin: 'https://127.0.0.1:3999' }, routes: { backend: 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8' } };
  const loginUrl = runtime.proxy.origin + '/' + runtime.routes.backend + '/admin/login';
  await page.goto(loginUrl, { timeout: 30000 });
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await page.click('button[type=submit]');
  await page.waitForTimeout(3000);

  // Navigate to address page
  const addressUrl = runtime.proxy.origin + '/' + runtime.routes.backend + '/address/backend/address';
  await page.goto(addressUrl, { timeout: 30000 });
  await page.waitForTimeout(2000);

  // Check if page loaded without fatal
  const bodyText = await page.locator('body').innerText().catch(() => '');
  const hasFatal = /WLS Runtime Error|ParseError|syntax error|Fatal error/i.test(bodyText);
  console.log('Has fatal:', hasFatal);
  console.log('Body preview:', bodyText.substring(0, 300));

  // Get all iframes
  const iframeCount = await page.locator('iframe').count();
  console.log('Total iframes on page:', iframeCount);

  // Get the offcanvas iframe data-src attribute
  for (let i = 0; i < iframeCount; i++) {
    const iframe = page.locator('iframe').nth(i);
    const id = await iframe.getAttribute('id').catch(() => 'no-id');
    const dataSrc = await iframe.getAttribute('data-src').catch(() => 'no-data-src');
    console.log(`Iframe ${i}: id=${id}, data-src=${dataSrc}`);
  }

  // Click the offcanvas trigger
  const trigger = page.locator('[data-bs-target*="offcanvasRightAddressForm"]').first();
  const triggerVisible = await trigger.isVisible({ timeout: 5000 }).catch(() => false);
  console.log('Trigger visible:', triggerVisible);

  if (triggerVisible) {
    await trigger.click({ force: true });
    await page.waitForTimeout(5000);

    // Check iframe src after click
    const iframeSrc = await page.locator('.offcanvas.show iframe').getAttribute('src').catch(() => 'NOT FOUND');
    console.log('Iframe src after click:', iframeSrc);

    // Try to get form content
    try {
      const frame = page.frameLocator('.offcanvas.show iframe').first();
      const formCount = await frame.locator('form').count();
      console.log('Form count in iframe:', formCount);
      const iframeBody = await frame.locator('body').innerText({ timeout: 5000 }).catch(() => 'ERROR');
      console.log('Iframe body preview:', iframeBody.substring(0, 300));
    } catch(e) {
      console.log('Error accessing iframe:', e.message);
    }
  }

  await browser.close();
})().catch(e => { console.error('Test error:', e.message); process.exit(1); });
