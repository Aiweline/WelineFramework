# w_query 统一查询使用说明

`w_query()` 用于 PHP 服务端模块间 QueryProvider 调用，内部路由到 `QueryProviderRegistry` 中已注册的 provider。

**跨模块禁令**：禁止 `use`/注入/`ObjectManager::getInstance`/`new` 引用其他模块内部类；跨模块读数据必须使用 `w_query()`。调用前先查帮助（见下文）。

浏览器站内业务请求不再直接使用 JSON `/api/framework/query`。前端业务 JS 必须使用 `Weline.Api.resource()/graph()/stream()`，由 `theme.js -> weline-api -> worker -> /{rest_frontend}/framework/query-bin -> FrontendQueryGateway` 转发；`{rest_frontend}` 由环境路由前缀生成，PHP 服务端继续使用 `w_query()`。

## 帮助发现（PHP / CLI）

```php
// 列出全部 provider 摘要
$all = w_query();

// 查看单个 provider（含 operations 与 params）
$help = w_query('widget');

// 按模块名查看（支持 WeShop_Product 或 WeShop/Product）
$help = w_query('WeShop_Product');

// 高级 introspect
$providers = w_query('framework', 'introspect', ['what' => 'providers']);
$descriptor = w_query('framework', 'introspect', ['what' => 'provider', 'provider' => 'widget']);
```

```bash
php bin/w query:help
php bin/w query:help widget
php bin/w query:help WeShop_Product
php bin/w query:help theme getActiveTheme --json
```

## 后端 PHP 查询示例

```php
$widgets = w_query('widget', 'getAvailableList', [
    'page_type' => 'homepage',
]);

$products = w_query('crud', 'list', [
    'model' => 'WeShop\\Product\\Model\\Product',
    'page' => 1,
    'page_size' => 20,
]);
```

函数签名：

```php
function w_query(?string $provider = null, ?string $operation = null, array $params = [], string $area = 'backend'): mixed
```

| 调用 | 行为 |
|------|------|
| `w_query()` | 全部 provider 摘要 |
| `w_query('widget')` | widget 完整 descriptor |
| `w_query('WeShop_Product')` | 按模块名解析 provider 帮助 |
| `w_query('widget', 'getList', [...])` | 执行查询 |

## 前端 JS 示例

业务调用：

```js
const CartApi = await Weline.Api.resource('cart');
await CartApi.add({ product_id, qty });
```

帮助（仅 `frontend=true` 的 operations）：

```js
const help = await w_query('cart');
const all = await Weline.Query.help();
```

`crud.*` 和 `framework.introspect` 默认不暴露给浏览器 frontend worker。浏览器帮助通过 `query_help` provider，只展示已声明 `frontend=true` 的 operations。完整服务端契约请用 PHP `w_query()` 或 `php bin/w query:help`。

## Frontend Worker API

前端能力清单来自 API 文档的 `Frontend Worker API` 目录。浏览器业务代码不要手写 query-bin URL，也不要直接 `fetch()` 站内业务 REST URL。

```js
const items = await Weline.Api.graph({
  cart: {
    miniItems: { limit: 10 },
    count: {},
  },
});
```

Graph 只允许 `mode=read && graph=true` 的 operation；write operation 必须单独调用 resource method。

## 入参

| 参数 | 类型 | 说明 |
|---|---|---|
| `provider` | string\|null | QueryProvider 标识、模块名，或为空时列出全部 |
| `operation` | string\|null | 操作名；为空时返回帮助 |
| `params` | array/object | 操作参数 |
| `area` | string | PHP 侧 `frontend` 或 `backend`，默认 `backend` |

## 注册查询器

模块通过 `extends/module/Weline_Framework/Query/` 注册实现 `QueryProviderInterface` 的类。

接口要求：

- `getProviderName(): string`
- `execute(string $operation, array $params = []): mixed`
- `getDescriptor(): array`（必须完整列出 operations 与 params，供 `query:help` 发现）

## 框架事件

- `Weline_Framework_Query::before_execute`
- `Weline_Framework_Query::after_execute`
