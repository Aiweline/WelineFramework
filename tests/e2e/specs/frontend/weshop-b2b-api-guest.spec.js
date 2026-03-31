// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend, buildFrontendModuleRestPathForModule } = require('../../framework');

const WESHOP_B2B_MODULE = 'WeShop_B2B';

test.describe('WeShop B2B REST API (guest)', () => {
  test('credit and receivables endpoints require login', async ({ page }) => {
    // 登录页脚本会包装 fetch，仅放行部分 API 路径；首页发起请求可避免 Failed to fetch
    await gotoFrontend(page, '/', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    const creditPath = buildFrontendModuleRestPathForModule(WESHOP_B2B_MODULE, 'b2-b-invoice', 'credit');
    const receivablesPath = buildFrontendModuleRestPathForModule(WESHOP_B2B_MODULE, 'b2-b-invoice', 'receivables');

    const result = await page.evaluate(
      async ({ creditPath: cPath, receivablesPath: rPath }) => {
        const headers = {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        };
        const creditRes = await fetch(cPath, { method: 'GET', headers });
        const recRes = await fetch(rPath, { method: 'GET', headers });
        return {
          credit: { status: creditRes.status, json: await creditRes.json() },
          receivables: { status: recRes.status, json: await recRes.json() },
        };
      },
      { creditPath, receivablesPath },
    );

    expect(result.credit.status).toBe(401);
    expect(result.credit.json.code).toBe(401);
    expect(String(result.credit.json.msg || '')).toMatch(
      /Please log in|请先登录|Missing authentication token|缺少认证|鐠囧嘲鍘涢惂璇茬秿/i,
    );

    expect(result.receivables.status).toBe(401);
    expect(result.receivables.json.code).toBe(401);
    expect(String(result.receivables.json.msg || '')).toMatch(
      /Please log in|请先登录|Missing authentication token|缺少认证|鐠囧嘲鍘涢惂璇茬秿/i,
    );
  });
});
