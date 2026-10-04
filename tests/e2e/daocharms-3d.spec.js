const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const fs = require('fs');

// Execute only after independent UI and prototype acceptance, with the current
// native theme preview URL. No old prototype server, mocked API or fake cart.
const entry = process.env.DAOCHARMS_3D_URL;
async function disableCache(page) {
  const session = await page.context().newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.setCacheDisabled', { cacheDisabled: true });
}
test.beforeEach(async ({ page }) => disableCache(page));
async function openTown(page) {
  if (!entry) throw new Error('DAOCHARMS_3D_URL must be the current native theme preview URL');
  const url = new URL(entry);
  if (url.port === '9568') throw new Error('The standalone prototype is not the commerce runtime');
  const response = await page.goto(entry, { waitUntil: 'domcontentloaded' });
  expect(response.status()).toBe(200);
  const town = page.locator('[data-daocharms-town]');
  await expect(town).toHaveAttribute('data-scene', 'ready', { timeout: 60000 });
  await expect(town.locator('canvas')).toBeVisible();
  await expect(town.locator('[data-town-status]')).not.toHaveText(/unavailable|暂不可用/i);
  return town;
}

test('UC-1 database catalogue search locates the same exhibit', async ({ page }) => {
  const town = await openTown(page);
  const product = town.locator('[data-town-product]').first();
  const name = await product.getAttribute('data-name');
  const href = await product.getAttribute('data-url');
  expect(name).toBeTruthy();
  expect(new URL(href, page.url()).pathname).not.toMatch(/\/products\/?$/);
  await town.locator('[data-town-search] input').fill(name);
  await town.locator('[data-town-search] button').click();
  const hit = town.locator('[data-town-results] a').filter({ hasText: name }).first();
  await expect(hit).toBeVisible({ timeout: 30000 });
  expect(new URL(await hit.getAttribute('href'), page.url()).searchParams.get('offer'))
    .toBe(new URL(href, page.url()).searchParams.get('offer'));
  await hit.click();
  await expect(town.locator('#location')).toContainText(name);
  await town.locator('[data-town-search] input').fill('no-such-product-daocharms-3d-20261002');
  await town.locator('[data-town-search] button').click();
  await expect(town.locator('[data-town-results]')).toHaveText(/没有找到|no products|no results/i);
});

for (const width of [375, 768, 1440]) {
  test(`UC-6 ${width}px controls, focus isolation and real movement`, async ({ page }, info) => {
    await page.setViewportSize({ width, height: width === 1440 ? 900 : 1024 });
    const town = await openTown(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    const input = town.locator('[data-town-search] input');
    const location = town.locator('#location');
    await expect(location).toHaveAttribute('data-position', /,/);
    const initial = await location.getAttribute('data-position');
    await input.fill('wasd');
    await input.press('ArrowUp');
    expect(await location.getAttribute('data-position')).toBe(initial);
    await town.locator('[data-move="forward"]').scrollIntoViewIfNeeded();
    await town.locator('[data-move="forward"]').hover();
    await page.mouse.down();
    await expect(location).not.toHaveAttribute('data-position', initial);
    await page.mouse.up();
    const artifactDir = path.join(__dirname, 'artifacts', 'daocharms-3d');
    fs.mkdirSync(artifactDir, { recursive: true });
    await page.screenshot({ path: path.join(artifactDir, `town-${width}.png`), fullPage: true });
  });
}

test('UC-5/7 no-JavaScript SSR product links and truthful original images', async ({ browser }) => {
  if (!entry) throw new Error('DAOCHARMS_3D_URL is required');
  const context = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true });
  try {
    const page = await context.newPage();
    await disableCache(page);
    const response = await page.goto(entry, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
    expect(await page.locator('link[hreflang]').count()).toBeGreaterThan(0);
    const product = page.locator('[data-town-product]').first();
    await expect(product).toHaveAttribute('data-town-product', /^\d+$/);
    await expect(product.locator('img')).toHaveAttribute('src', /\/media\//);
    await expect(page.locator('[data-daocharms-town]')).toContainText(/立体示意|Illustrative 3D model/);
    const href = await product.getAttribute('data-url');
    const result = await page.goto(new URL(href, page.url()).href, { waitUntil: 'domcontentloaded' });
    expect(result.status()).toBe(200);
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
    const entities = await page.locator('script[type="application/ld+json"]').allTextContents();
    expect(entities.join('\n')).toMatch(/Product/);
    expect(entities.join('\n')).toMatch(/Offer/);
  } finally {
    await context.close();
  }
});

test('UC-5 native robots and sitemap protocol routes', async ({ request }, info) => {
  if (!entry) throw new Error('DAOCHARMS_3D_URL is required');
  const root = new URL(entry);
  root.search = '';
  const robots = await request.get(new URL('robots.txt', root).href);
  expect(robots.status()).toBe(200);
  const robotsBody = await robots.text();
  expect(robotsBody).toMatch(/User-agent:/i);
  const sitemap = await request.get(new URL('sitemap.xml', root).href);
  expect(sitemap.status()).toBe(200);
  const sitemapBody = await sitemap.text();
  expect(sitemapBody).toMatch(/<(?:sitemapindex|urlset)\b/);
  const locations = xml => [...xml.matchAll(/<loc>\s*([^<]+)\s*<\/loc>/g)].map(match => match[1].replaceAll('&amp;', '&').trim());
  const assertScope = loc => {
    const parsed = new URL(loc);
    expect(parsed.origin).toBe(root.origin);
    expect(parsed.pathname.startsWith(root.pathname)).toBe(true);
    expect(parsed.searchParams.has('weline_preview_token')).toBe(false);
  };
  const indexLocations = locations(sitemapBody);
  expect(indexLocations.length).toBeGreaterThan(0);
  indexLocations.forEach(assertScope);
  const shardUrl = indexLocations.find(loc => /product|categor/i.test(loc));
  expect(shardUrl, 'Index must contain an actual product/category shard').toBeTruthy();
  const shard = await request.get(shardUrl);
  expect(shard.status()).toBe(200);
  const shardBody = await shard.text();
  const shardLocations = locations(shardBody);
  expect(shardLocations.length).toBeGreaterThan(0);
  shardLocations.forEach(assertScope);
  await info.attach('native-protocols', { body: JSON.stringify({ robots_url: robots.url(), robots: robotsBody, sitemap_url: sitemap.url(), sitemap: sitemapBody, shard_url: shardUrl, shard: shardBody }), contentType: 'application/json' });
});

test('UC-5 native private routes remain noindex and delivery viewport screenshot', async ({ page }, info) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const town = await openTown(page);
  await town.locator('[data-town-search] input').fill('');
  await page.keyboard.press('ControlOrMeta+Home');
  const artifactDir = path.join(__dirname, 'artifacts', 'daocharms-3d');
  fs.mkdirSync(artifactDir, { recursive: true });
  await page.screenshot({ path: path.join(artifactDir, 'town-1440-viewport.png'), fullPage: false });
  const routes = await town.evaluate(el => ['cartUrl', 'checkoutUrl', 'accountUrl'].map(key => el.dataset[key]));
  const observed = [];
  for (const url of routes) {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBe(200);
    const meta = await page.locator('meta[name="robots"]').getAttribute('content');
    const header = response.headers()['x-robots-tag'] || '';
    expect(`${meta || ''} ${header}`).toMatch(/noindex/i);
    observed.push({ requested: url, current: page.url(), robots: meta, x_robots_tag: header });
  }
  await info.attach('private-route-seo', { body: JSON.stringify(observed), contentType: 'application/json' });
});

test('UC-2/3/4 actual member purchase persists in cart and account orders', async ({ page }, info) => {
  test.setTimeout(360000);
  const output = execFileSync('php', [path.join(__dirname, 'daocharms-3d-customer-fixture.php')], {
    cwd: path.resolve(__dirname, '../..'), encoding: 'utf8',
  });
  const customer = JSON.parse(output.trim().split('\n').pop());
  expect(customer.ok).toBe(true);
  let town = await openTown(page);
  const accountUrl = await town.getAttribute('data-account-url');
  await page.goto(accountUrl, { waitUntil: 'domcontentloaded' });
  const login = page.locator('[data-w-login-form]');
  await expect(login).toBeVisible();
  await login.locator('[name="username"]').fill(customer.email);
  await login.locator('[name="password"]').fill(customer.password);
  await login.locator('[data-w-login-submit]').click();
  const captcha = login.getByRole('textbox', { name: /Enter the code shown|验证码/i });
  await expect.poll(async () => !await login.isVisible() || await captcha.isVisible(), { timeout: 30000 }).toBe(true);
  if (await captcha.isVisible()) {
    const challengeDir = path.join(__dirname, 'artifacts', 'daocharms-3d-captcha');
    fs.mkdirSync(challengeDir, { recursive: true });
    const answerPath = path.join(challengeDir, 'answer.txt');
    fs.rmSync(answerPath, { force: true });
    await login.getByRole('img', { name: /Enter the code shown|验证码/i }).screenshot({ path: path.join(challengeDir, 'challenge.png') });
    console.log('Native image captcha awaiting visual transcription: ' + challengeDir);
    await expect.poll(() => fs.existsSync(answerPath), { timeout: 120000 }).toBe(true);
    await captcha.fill(fs.readFileSync(answerPath, 'utf8').trim());
    fs.rmSync(answerPath, { force: true });
    await login.locator('[data-w-login-submit]').click();
  }
  await expect(login).not.toBeVisible({ timeout: 30000 });
  town = await openTown(page);
  const product = town.locator('[data-town-product="7"]');
  const name = await product.getAttribute('data-name');
  await product.locator('[data-town-purchase]').click();
  const panel = page.locator('#weline-product-purchase-panel-dialog');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText(name);
  await expect(panel.locator('[data-testid="product-qty"]')).toBeVisible();
  await panel.locator('[data-action="add"]').click();
  const cartUrl = await town.getAttribute('data-cart-url');
  await page.goto(cartUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-cart-content="1"]')).toContainText(name);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-cart-content="1"]')).toContainText(name);
  await page.screenshot({ path: info.outputPath('member-cart.png'), fullPage: true });
  await page.locator('.weline-cart-shell__checkout').click();
  const checkout = page.locator('[data-testid="checkout-form-page"]');
  await expect(checkout).toBeVisible();
  const fields = { country_code: 'US', name: 'Demo Buyer', phone: '2025550123', email: customer.email,
    province: 'California', city: 'Los Angeles', address1: '101 Demo Street', postal_code: '90001' };
  for (const [field, value] of Object.entries(fields)) {
    await checkout.locator(`input[name="${field}"]`).fill(value);
  }
  const shipping = checkout.locator('input[name="shipping_method"]').first();
  await expect(shipping).toBeVisible({ timeout: 30000 });
  await shipping.check();
  const sandbox = checkout.locator('input[name="payment_method"][value="fake_card"]');
  await expect(sandbox).toBeVisible({ timeout: 30000 });
  await sandbox.check();
  await expect(sandbox).toBeChecked();
  await expect(checkout.locator('[data-submit]')).toBeEnabled();
  await checkout.locator('[data-submit]').click();
  const success = page.locator('[data-testid="checkout-success"]');
  await expect(success).toBeVisible({ timeout: 60000 });
  const uuid = await success.getAttribute('data-order-uuid');
  expect(uuid).toMatch(/^[a-f0-9-]{36}$/i);
  await info.attach('order-identity', { body: JSON.stringify({ order_uuid: uuid, customer_id: customer.customer_id }), contentType: 'application/json' });
  await page.screenshot({ path: info.outputPath('sandbox-order-success.png'), fullPage: true });
  await page.goto(accountUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-account-nav-link][data-section="orders"]').click();
  const orders = page.locator('[data-account-orders]');
  const detail = orders.locator(`a[href*="order_uuid=${uuid}"]`).first();
  await expect(detail).toBeVisible({ timeout: 30000 });
  await detail.click();
  await expect(page.locator('[data-account-order-detail]')).toContainText(uuid);
  await page.screenshot({ path: info.outputPath('account-same-order.png'), fullPage: true });
});
