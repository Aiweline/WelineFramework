# w_query 统一查询使用说明

`w_query()` 用于 PHP 服务端模块间 QueryProvider 调用，内部路由到 `QueryProviderRegistry` 中已注册的 provider。

浏览器站内业务请求不再直接使用 JSON `/api/framework/query`。前端业务 JS 必须使用 `Weline.Api.resource()/graph()/stream()`，由 `theme.js -> weline-api -> worker -> /api/framework/query-bin -> FrontendQueryGateway` 转发；PHP 服务端继续使用 `w_query()`。

## 后端 PHP 示例

```php
// Widget 查询
$widgets = w_query('widget', 'getAvailableList', [
    'page_type' => 'homepage',
]);

// 服务端内部 CRUD 查询
$products = w_query('crud', 'list', [
    'model' => 'WeShop\\Product\\Model\\Product',
    'page' => 1,
    'page_size' => 20,
]);

// 服务端 introspect
$providers = w_query('framework', 'introspect', ['what' => 'providers']);
$ops = w_query('framework', 'introspect', ['what' => 'operations', 'provider' => 'widget']);
```

函数签名：

```php
function w_query(string $provider, string $operation, array $params = [], string $area = 'backend'): mixed
```

## 前端 JS 示例

```js
const CartApi = await Weline.Api.resource('cart');

await CartApi.add({ product_id, qty });
await CartApi.options({ product_id });
await CartApi.miniItems({ limit: 10 });
```

只有需要别名或限制子集时才传 optional map：

```js
const CartApi = await Weline.Api.resource('cart', {
  addItem: 'add',
  getOptions: 'options',
});

await CartApi.addItem({ product_id, qty });
```

`crud.*` 和 `framework.introspect` 默认不暴露给浏览器 frontend worker。需要前端能力时，QueryProvider descriptor 必须显式声明 `frontend=true`、`mode`、`graph`、`params`、`returns`。

## Frontend Worker API

前端能力清单来自 API 文档的 `Frontend Worker API` 目录。浏览器业务代码不要手写 `/api/framework/query-bin`，也不要直接 `fetch()` 站内业务 REST URL。

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
| `provider` | string | QueryProvider 标识，例如 `cart`、`widget`、`websites`、`framework` |
| `operation` | string | 操作名 |
| `params` | array/object | 操作参数 |
| `area` | string | PHP 侧 `frontend` 或 `backend`，默认 `backend` |

## 注册查询器

模块通过 `extends/module/Weline_Framework/Query/` 注册实现 `QueryProviderInterface` 的类。

接口要求：

- `getProviderName(): string`
- `execute(string $operation, array $params = []): mixed`
- `getDescriptor(): array`

## 框架事件

- `Weline_Framework_Query::before_execute`
- `Weline_Framework_Query::after_execute`
