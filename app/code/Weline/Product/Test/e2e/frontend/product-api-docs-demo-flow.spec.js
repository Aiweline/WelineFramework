// @weline-e2e-runtime wls
// @ts-check
/**
 * 正常 API 登录与产品 Demo 的真实写入和中英店面回读。
 * 2026-09-13 验收状态：脚本已准备，完整真实链路尚未验收；店面访问因 Browser 策略拒绝而暂停。
 *
 * 运行：WELINE_PRODUCT_API_CREDENTIALS_FILE=/tmp/私有凭据.json
 *       PLAYWRIGHT_TARGET_ORIGIN=https://当前测试实例
 *       PLAYWRIGHT_DISABLE_PROXY=1 php bin/w e2e:run 本文件 --project=chromium --workers=1
 * 凭据文件必须为 0600，内容仅为 username/password；不用后台 Session helper。
 * 每次运行保留一个唯一 SKU 的验收商品，报告中只记录商品标识和店面地址。
 *
 * @weline-e2e-spec { module: Weline_Product, type: flow, layer: frontend }
 */
const fs = require('fs');
const { randomUUID } = require('crypto');
const {
  test,
  expect,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const DEMO_PATH = '/dev/tool/docs/api?api_id=api_Weline_Product_v1_Weline%5CProduct%5CApi%5CRest%5CV1%5CProducts_postCreate&demo=product-rest-demo';

// 认证请求不进入 trace，避免凭据被录入报告；页面仍保留失败截图。
test.use({ viewport: { width: 1440, height: 1000 }, trace: 'off', video: 'off' });

async function disableBrowserCache(page) {
  const session = await page.context().newCDPSession(page);
  await session.send('Network.enable');
  await session.send('Network.setCacheDisabled', { cacheDisabled: true });
}

function credentialsFromPrivateFile() {
  const filename = process.env.WELINE_PRODUCT_API_CREDENTIALS_FILE;
  if (!filename) throw new Error('请设置 WELINE_PRODUCT_API_CREDENTIALS_FILE 指向本次测试账号的 0600 JSON 文件。');
  if ((fs.statSync(filename).mode & 0o777) !== 0o600) {
    throw new Error('API 测试凭据文件权限必须为 0600。');
  }
  const credentials = JSON.parse(fs.readFileSync(filename, 'utf8'));
  if (!credentials.username || !credentials.password) {
    throw new Error('API 测试凭据必须包含 username 和 password。');
  }
  return credentials;
}

async function runDemoAction(demo, label, expectedSteps) {
  const history = demo.locator('[data-demo-history] details');
  const previousCount = await history.count();
  await demo.getByRole('button', { name: label, exact: true }).click();
  await expect(demo.locator('[data-api-demo-status]')).toBeHidden({ timeout: 180000 });
  await expect(history).toHaveCount(previousCount + expectedSteps);
  const entry = history.last();
  await expect(entry.locator('summary')).toContainText('200');
  const response = JSON.parse(await entry.locator('pre code').last().innerText());
  expect(response.success).not.toBe(false);
  if (response.code !== undefined) expect(Number(response.code)).toBe(200);
  expect(response.data.product.product_id).toBeGreaterThan(0);
  return response.data;
}

async function recordPhase(testInfo, phase, data) {
  const evidence = { phase, observed_at: new Date().toISOString(), ...data };
  console.info('[product-api-docs-demo] ' + JSON.stringify(evidence));
  await testInfo.attach(phase, { body: JSON.stringify(evidence, null, 2), contentType: 'application/json' });
}

async function verifyStorefronts(page, demo, expectedNames, testInfo, phase) {
  const urls = {};
  for (const locale of ['zh_Hans_CN', 'en_US']) {
    await demo.locator('[name="demo.locale"]').fill(locale);
    const detail = await runDemoAction(demo, '回读产品', 1);
    expect(detail.content.name).toBe(expectedNames[locale]);
    const link = demo.locator('a[data-demo-link]').first();
    await expect(link).toBeVisible();
    const entryUrl = await link.getAttribute('href');
    expect(new URL(entryUrl).origin).toBe(new URL(page.url()).origin);
    const [storefront] = await Promise.all([
      page.context().waitForEvent('page'),
      link.click(),
    ]);
    try {
      await storefront.waitForLoadState('domcontentloaded');
      await disableBrowserCache(storefront);
      await storefront.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
      // API 链接不随 locale 改变；从店面标准语言菜单选择，跟随服务端提供的 href。
      const switcher = storefront.locator('[data-i18n-switcher]:visible').first();
      const trigger = switcher.locator('[data-w-menu-trigger]');
      await expect(trigger).toBeVisible();
      const panelId = await trigger.getAttribute('aria-controls');
      await trigger.click();
      const panel = storefront.locator('[id="' + panelId + '"]');
      const option = panel.locator('a[data-language-option][data-lang="' + locale + '"]');
      await expect(option).toBeVisible();
      const targetUrl = new URL(await option.getAttribute('href'), storefront.url()).href;
      await Promise.all([
        storefront.waitForEvent('framenavigated', {
          predicate: frame => frame === storefront.mainFrame(), timeout: 90000,
        }),
        option.click(),
      ]);
      await storefront.waitForLoadState('domcontentloaded');
      await expect(storefront).toHaveURL(targetUrl);
      urls[locale] = storefront.url();
      await expect(storefront.getByRole('heading', { name: expectedNames[locale], exact: true }))
        .toBeVisible({ timeout: 60000 });
      const otherLocale = locale === 'en_US' ? 'zh_Hans_CN' : 'en_US';
      await expect(storefront.locator('body')).not.toContainText(expectedNames[otherLocale]);
      await storefront.screenshot({ path: testInfo.outputPath(phase + '-' + locale + '-storefront.png'), fullPage: true });
      await recordPhase(testInfo, phase + '-storefront-' + locale, {
        product_id: detail.product.product_id, title: expectedNames[locale], url: storefront.url(),
      });
    } finally {
      await storefront.close();
    }
  }
  return urls;
}

moduleDescribe(test, MODULE, 'API 文档 Demo 真实完整链路', () => {
  test.describe.configure({ retries: 0 });
  test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus || page.isClosed()) return;
    const entries = page.locator('[data-api-demo="product-rest-demo"] [data-demo-history] details');
    const history = [];
    for (let index = 0; index < await entries.count(); index += 1) {
      const entry = entries.nth(index);
      const result = { summary: await entry.locator('summary').innerText() };
      try {
        const response = JSON.parse(await entry.locator('pre code').last().innerText());
        result.product_id = response.data?.product?.product_id;
        result.title = response.data?.content?.name;
      } catch (_) { /* 未完成响应仍保留页面操作摘要。 */ }
      history.push(result);
    }
    await recordPhase(testInfo, 'failure-visible-history', { history });
  });

  moduleCase(
    test,
    { module: MODULE, id: 'PRODUCT-API-DOCS-DEMO-FLOW-001' },
    '普通登录后创建发布、独立编辑语言、批量译文并在中英店面验证',
    async ({ page }, testInfo) => {
      test.setTimeout(600000);
      const credentials = credentialsFromPrivateFile();
      const origin = new URL(process.env.PLAYWRIGHT_TARGET_ORIGIN || getRuntimeInfo().runtime.target_origin).origin;
      const token = randomUUID();
      const sku = 'API-UI-E2E-' + token;
      const initial = { zh_Hans_CN: 'API 界面验收中文初始 ' + token, en_US: 'API UI acceptance initial ' + token };
      const single = { zh_Hans_CN: initial.zh_Hans_CN, en_US: 'API UI acceptance English edit ' + token };
      const batch = { zh_Hans_CN: 'API 界面验收批量中文 ' + token, en_US: 'API UI acceptance batch English ' + token };
      const demo = page.locator('[data-api-demo="product-rest-demo"]');

      await disableBrowserCache(page);
      await page.goto(new URL(DEMO_PATH, origin).href, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await expect(demo.getByRole('heading', { name: '产品创建、发布与多语言编辑' })).toBeVisible();
      await expect(page.locator('[data-api-toc]')).toHaveCount(1);
      await expect(page.locator('[data-api-toc]')).toBeHidden();

      await page.getByRole('button', { name: '登录', exact: true }).click();
      const login = page.getByRole('dialog', { name: '前端 API 用户登录' });
      await expect(login).toBeVisible();
      await expect(login.getByLabel('用户名', { exact: true })).toBeVisible();
      await expect(login.getByLabel('密码', { exact: true })).toBeVisible();
      await page.screenshot({ path: testInfo.outputPath('login-empty.png'), fullPage: true });
      await login.getByLabel('用户名', { exact: true }).fill(credentials.username);
      await login.getByLabel('密码', { exact: true }).fill(credentials.password);
      await login.getByRole('button', { name: '登录', exact: true }).click();
      await expect(login).toBeHidden({ timeout: 90000 });
      await expect(page.getByRole('button', { name: credentials.username, exact: true })).toBeVisible();
      await expect(demo).toContainText(credentials.username);

      await demo.getByRole('button', { name: '填入新示例', exact: true }).click();
      await demo.locator('[name="demo.locale"]').fill('zh_Hans_CN');
      await demo.locator('[name="demo.name"]').fill(initial.zh_Hans_CN);
      await demo.locator('[name="demo.sku"]').fill(sku);
      await demo.locator('[name="demo.translations"]').fill(JSON.stringify({ en_US: { name: initial.en_US } }));
      await demo.locator('[name="demo.translate_to"]').fill('[]');

      const created = await runDemoAction(demo, '创建并发布', 4);
      const productId = created.product.product_id;
      await recordPhase(testInfo, 'create-publish-completed', { product_id: productId, sku, title: created.content.name });
      expect(created.content.name).toBe(initial.zh_Hans_CN);
      expect(created.translations.en_US.name).toBe(initial.en_US);
      await expect(demo.locator('[data-demo-history] details').first().locator('summary')).toContainText('201');
      await demo.screenshot({ path: testInfo.outputPath('create-demo.png') });
      await verifyStorefronts(page, demo, initial, testInfo, 'create');

      await demo.locator('[name="demo.locale"]').fill('en_US');
      await demo.locator('[name="demo.name"]').fill(single.en_US);
      await demo.locator('[name="demo.translations"]').fill('{}');
      const edited = await runDemoAction(demo, '保存当前语言并回读', 2);
      await recordPhase(testInfo, 'single-completed', { product_id: edited.product.product_id, title: edited.content.name });
      expect(edited.product.product_id).toBe(productId);
      expect(edited.content.name).toBe(single.en_US);
      expect(edited.translations.zh_Hans_CN.name).toBe(initial.zh_Hans_CN);
      await demo.screenshot({ path: testInfo.outputPath('single-demo.png') });
      await verifyStorefronts(page, demo, single, testInfo, 'single');

      await demo.locator('[name="demo.locale"]').fill('zh_Hans_CN');
      await demo.locator('[name="demo.name"]').fill(batch.zh_Hans_CN);
      await demo.locator('[name="demo.translations"]').fill(JSON.stringify({
        zh_Hans_CN: { name: batch.zh_Hans_CN },
        en_US: { name: batch.en_US },
      }));
      const translated = await runDemoAction(demo, '保存多语言译文并回读', 2);
      await recordPhase(testInfo, 'batch-completed', { product_id: translated.product.product_id, title: translated.content.name });
      expect(translated.product.product_id).toBe(productId);
      expect(translated.content.name).toBe(batch.zh_Hans_CN);
      expect(translated.translations.en_US.name).toBe(batch.en_US);
      await demo.screenshot({ path: testInfo.outputPath('batch-demo.png') });
      const storefrontUrls = await verifyStorefronts(page, demo, batch, testInfo, 'batch');

      await testInfo.attach('product-api-docs-demo-result', {
        body: JSON.stringify({ product_id: productId, sku, storefront_urls: storefrontUrls, names: { initial, single, batch } }, null, 2),
        contentType: 'application/json',
      });
    },
  );
});
