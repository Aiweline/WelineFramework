/**
 * 分类与活动链接的真实 HTTP 集成；浏览器点击由人工等价验收另行记录。
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const { test, expect } = require('@playwright/test');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

test('分类与活动批量 URL 保留原路径且页面完整可达', async ({ request }, testInfo) => {
  test.setTimeout(90000);
  const home = await request.get(BASE + '/', { ignoreHTTPSErrors: true });
  expect(home.status()).toBe(200);
  const html = await home.text();
  const observations = [];
  for (const path of ['/category/women', '/promotion/deals']) {
    expect(html).toContain(BASE + path);
    const response = await request.get(BASE + path, { ignoreHTTPSErrors: true });
    expect(response.status()).toBe(200);
    expect(new URL(response.url()).pathname).toBe(path);
    const body = await response.text();
    expect(body).toContain('</html>');
    expect(body).toContain('</footer>');
    const title = body.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || '';
    expect(title.trim()).not.toBe('');
    observations.push({ path, status: response.status(), url: response.url(), title });
  }
  await testInfo.attach('真实 HTTP 页面证据', { body: JSON.stringify(observations, null, 2), contentType: 'application/json' });
});
