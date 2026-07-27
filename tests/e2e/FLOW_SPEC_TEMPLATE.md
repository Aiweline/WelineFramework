# Backend Flow E2E 金标模板（防假绿）

## 何时用

- `type: flow`：真实业务交互（点、填、OffCanvas、开关、提交）。
- `type: smoke`：每模块至多 **1** 条，标题必须诚实写「路由可达」，禁止声称搜索/筛选/CRUD。

## 最低标准（flow）

1. `loginAsAdmin` 只作前置，不算业务步骤。
2. 至少 1 次业务交互：`click` / `fill` / OffCanvas / 开关 / 提交。
3. 至少 1 条决定性断言：业务 DOM 变化，或拦截 `Weline.Api`/bin-query 的 path + 关键字段。
4. **禁止**仅靠 `body` 可见 / 无 Fatal / `toHaveURL(?keyword=)` 通过。
5. 禁止驱动产品页 `alert`/`confirm`；业务请求走页面既有 bin-query。

## 伪代码

```js
// @weline-e2e-spec { module: Weline_X, type: flow, layer: backend }
const {
  test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute,
  moduleDescribe, moduleCase, waitForBackendShellReady, submitForm,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_X';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_X 核心流程', () => {
  moduleCase(test, { module: MODULE, id: 'X-FLOW-001' }, '业务标题：真实交互 + 决定性断言', async ({ page }) => {
    await loginAsAdmin(page);
    await gotoBackend(page, buildModuleBackendRoute(MODULE, '<route>'), { timeout: 60000, settleMs: 800 });
    await waitForBackendShellReady(page); // 必做：关掉 #loading，否则 click 会被遮罩拦截
    await expect(page.locator('body')).not.toContainText(FATAL);
    await expect(page.locator('<业务根>')).toBeVisible({ timeout: 15000 });

    await page.locator('<输入>').fill('sample');
    const pending = page.waitForResponse((r) => r.url().includes('<api-or-query>') && r.ok(), { timeout: 20000 });
    await submitForm(page, 'form'); // 或 click({ force: true })
    await pending;

    await expect(page.locator('<结果区>')).toBeVisible();
  });
});
```

## 假绿抽检

改写后临时注释掉业务 `click`/`fill`，用例应失败；若仍绿，判定仍为假用例。
