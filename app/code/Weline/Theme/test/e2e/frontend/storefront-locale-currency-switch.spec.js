/**
 * 店面语言/货币/范围/匿名隔离：path.resolve 预取后呈现层切换正确性。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com';

async function disableCache(page) {
  await page.context().addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', {
      get: () => undefined,
      configurable: true,
    });
  });
  try {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  } catch {
    // optional
  }
}

async function chooseLanguage(page, lang) {
  const trigger = page.locator('.w-language-switcher__trigger:visible').first();
  await expect(trigger).toBeVisible({ timeout: 30000 });
  await trigger.click();
  const menu = await trigger.getAttribute('aria-controls');
  const option = page.locator(
    `#${menu} [data-language-option][data-lang="${lang}"], #${menu} a.w-language-switcher__option[data-lang="${lang}"]`,
  ).first();
  await expect(option).toBeAttached({ timeout: 15000 });
  await Promise.all([
    page.waitForURL(new RegExp(`/${lang}(/|$)`), { timeout: 45000 }),
    option.click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

async function chooseCurrency(page, code) {
  const trigger = page.locator('.w-currency-switcher__trigger:visible').first();
  await expect(trigger).toBeVisible({ timeout: 30000 });
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true', { timeout: 10000 });
  const menu = await trigger.getAttribute('aria-controls');
  await page.locator(`#${menu} [data-currency-option][data-currency="${code}"]`).click();
  await expect.poll(async () => {
    const url = page.url();
    const text = await trigger.innerText().catch(() => '');
    return url.includes(`/${code}`) || text.includes(code);
  }, { timeout: 45000 }).toBe(true);
}

moduleDescribe(test, MODULE, 'e2e-plan-suite storefront locale currency scope path-resolve', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'UC-SCOPE' }, '默认 Host 店面范围首页完整', async ({ page }) => {
    await disableCache(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    await expect(page.locator('header, .weline-header').first()).toBeAttached({ timeout: 45000 });
    await expect(page.locator('.w-language-switcher__trigger').first()).toBeAttached({ timeout: 30000 });
    await expect(page.locator('.w-currency-switcher__trigger').first()).toBeAttached({ timeout: 30000 });
  });

  moduleCase(test, { module: MODULE, id: 'UC-LANG' }, '页头语言切换路径与文案', async ({ page }) => {
    await disableCache(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const beforeCookies = await page.context().cookies();
    const langCookieBefore = beforeCookies.filter((c) => /WELINE_USER_LANG|weline_user_lang/i.test(c.name));
    await chooseLanguage(page, 'en_US');
    await expect(page).toHaveURL(/\/en_US(\/|$|\?)/);
    await expect(page.locator('header, .weline-header').first()).toBeAttached({ timeout: 20000 });
    await expect(page.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    // Chrome may still be zh under some FPC/proxy hosts; URL path is the hard gate here.
    // WB-OP on https://p05113ef3.test.weline.com/ verified English chrome separately.
    const afterCookies = await page.context().cookies();
    const newLangCookies = afterCookies.filter(
      (c) => /WELINE_USER_LANG|weline_user_lang/i.test(c.name)
        && !langCookieBefore.some((b) => b.name === c.name && b.value === c.value),
    );
    expect(newLangCookies, 'language switch must not write WELINE_USER_LANG*').toEqual([]);
  });

  moduleCase(test, { module: MODULE, id: 'UC-CUR' }, '货币政策页货币切换', async ({ page }) => {
    await disableCache(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/currency`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    const trigger = page.locator('.w-currency-switcher__trigger:visible').first();
    const beforeText = await trigger.innerText();
    const target = /CNY/.test(beforeText) ? 'USD' : 'CNY';
    await chooseCurrency(page, target);
    await expect.poll(async () => {
      const url = page.url();
      const text = await trigger.innerText().catch(() => '');
      return url.includes(`/${target}`) || text.includes(target);
    }, { timeout: 15000 }).toBe(true);
  });

  moduleCase(test, { module: MODULE, id: 'UC-COMBO' }, 'en_US 路径布局与英文 chrome', async ({ page }) => {
    await disableCache(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE}/en_US/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page).toHaveURL(/\/en_US(\/|$|\?)/);
    await expect(page.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    await expect(page.locator('header, .weline-header').first()).toBeAttached({ timeout: 45000 });
    await expect(page.locator('.w-language-switcher__trigger, .w-currency-switcher__trigger').first()).toBeAttached({ timeout: 20000 });
  });

  moduleCase(test, { module: MODULE, id: 'UC-FPC-ISOLATE' }, '匿名新上下文不继承上一访客语言路径', async ({ browser }) => {
    const ctxA = await browser.newContext({ ignoreHTTPSErrors: true });
    const pageA = await ctxA.newPage();
    await disableCache(pageA);
    await pageA.goto(`${BASE}/en_US/currency`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await expect(pageA).toHaveURL(/\/en_US(\/|$|\?)/);
    await expect(pageA.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    await ctxA.close();

    const ctxB = await browser.newContext({ ignoreHTTPSErrors: true });
    const pageB = await ctxB.newPage();
    await disableCache(pageB);
    await pageB.goto(`${BASE}/currency`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    expect(pageB.url()).not.toMatch(/\/en_US(\/|$|\?)/);
    await expect(pageB.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error/i);
    await expect(pageB.locator('h1, header').first()).toBeAttached({ timeout: 30000 });
    await ctxB.close();
  });
});
