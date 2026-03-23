# Playwright E2E

`tests/e2e` 现在提供一层统一的 E2E 封包，目标是让用例尽量只关心业务行为，不再手写运行时地址、后台前缀、API 前缀、协议差异或当前 WLS 端口。

## 设计目标

- 自动收集所有模块下的 E2E 用例
- 用统一代理入口屏蔽框架运行时差异
- 在封包层统一处理前后台地址、API 地址、认证和主题预览信息
- 保持 Playwright 用法尽量原生
- 仍然允许用例直接访问外部绝对地址

## 自动收集规则

收集脚本会扫描：

- `tests/e2e/specs/**`
- 模块目录下的 `test/e2e/**`
- 模块目录下的 `Test/e2e/**`
- `e2e` / `E2E` 大小写变体

运行：

```bash
cd tests/e2e
node collect-tests.js
```

## 统一入口

Playwright `baseURL` 不再直接指向真实服务，而是指向本地反向代理：

- 代理入口：`https://localhost:3999`
- 真实目标：由 `tests/e2e/framework/runtime-info.php` 在运行时自动解析

代理会统一转发：

- `/` 前台请求
- `/@backend/...` 后台请求
- `/@api/...` 前台 API
- `/@backend-api/...` 后台 API

这样测试不需要知道：

- 当前是 `http` 还是 `https`
- WLS 实际监听哪个端口
- 后台随机前缀是什么
- 后台 API 前缀是什么

## 封包入口

推荐所有 E2E 用例统一从下面入口导入：

```js
const {
  test,
  expect,
  gotoFrontend,
  gotoBackend,
  gotoApi,
  gotoThemePreview,
  loginAsAdmin,
  getRuntimeInfo,
  getActiveTheme,
} = require('../../../../../../../tests/e2e/framework');
```

可用 helper 包括：

- `getRuntimeInfo()`：读取当前真实运行时信息
- `gotoFrontend(page, path)`
- `gotoBackend(page, route)`
- `gotoApi(page, route)`
- `loginAsAdmin(page)`
- `getActiveTheme('frontend' | 'backend')`
- `gotoThemePreview(page, { themeId?, pageType?, previewMode?, status? })`

如果传入的是绝对 URL，例如 `https://example.com/path`，封包不会改写，仍会直接访问外部地址。

## 主题预览能力

`runtime-info.php` 会额外读取当前数据库中的活主题信息，封包会优先使用：

- `themes.active.frontend`
- 若前台未单独激活，则回退到 `themes.active.global`

因此主题类 E2E 不需要再硬编码 `theme_id`，通常直接：

```js
await gotoThemePreview(page, { pageType: 'homepage' });
```

## 运行测试

```bash
cd tests/e2e
npx playwright test
```

只跑某个用例：

```bash
cd tests/e2e
npx playwright test app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js --project=chromium --workers=1
```

列出当前收集结果：

```bash
cd tests/e2e
npx playwright test --list
```

## 编写模块用例

模块内建议结构：

```text
app/code/Vendor/Module/
└─ test/
   └─ e2e/
      ├─ frontend/
      └─ backend/
```

示例：

```js
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test('admin page renders', async ({ page }) => {
  await loginAsAdmin(page);
  await gotoBackend(page, 'system/cache');
  await expect(page.locator('body')).toContainText('Cache');
});
```

## 故障排查

如果发现地址不对，优先检查：

1. `php tests/e2e/framework/runtime-info.php`
2. `node tests/e2e/collect-tests.js`
3. `npx playwright test --list`

如果主题预览或后台地址异常，通常不是用例本身的问题，而是运行时信息、随机前缀或主题上下文没有被统一 helper 接管。
