# w_query 统一查询使用说明

`w_query` 用于统一调用 Query API，内部路由到 `QueryProviderRegistry` 中已注册的查询器执行。

## 前端示例

```js
// CRUD 查询
const result = await window.w_query('crud', 'list', {
  model: 'WeShop\\Product\\Model\\Product',
  page: 1,
  page_size: 20
});

// 短 model 名（配合 module 参数）
const result = await window.w_query('crud', 'list', {
  module: 'WeShop_Product',
  model: 'product',
  page: 1,
  page_size: 20
});

// Widget 查询
const result = await window.w_query('widget', 'getAvailableList', {
  page_type: 'frontend'
}, { area: 'backend' });
```

## 使用说明查询（introspect）

```js
// 列出所有 provider
const providers = await window.w_query('framework', 'introspect', { what: 'providers' });

// 列出某 provider 的 operations
const ops = await window.w_query('framework', 'introspect', { what: 'operations', provider: 'widget' });

// 查看某 operation 的详细参数
const detail = await window.w_query('framework', 'introspect', { what: 'operation', provider: 'widget', operation: 'getAvailableList' });
```

## 入参

- `provider`: 提供者标识（如 `crud`、`widget`、`framework`）
- `operation`: 操作名
- `params`: 操作参数
- `options.area`: `frontend` 或 `backend`

## 短 model 名约定（crud provider）

当传 `module` 时，`model` 可简写，框架自动解析：

- `model: 'product'` -> `WeShop\Product\Model\Product`
- `model: 'product_category'` -> `WeShop\Product\Model\ProductCategory`（snake_case 转 PascalCase）

## 如何注册查询器

各模块通过 extends 注册：在模块目录下 `extends/module/Weline_Framework/Query/` 放置实现 `QueryProviderInterface` 的类。

接口要求实现：
- `getProviderName(): string` — 返回提供者标识
- `execute(string $operation, array $params = []): mixed` — 执行查询
- `getDescriptor(): array` — 返回使用说明描述

## 框架级事件

- `Weline_Framework_Query::before_execute` — 查询执行前（鉴权、拦截）
- `Weline_Framework_Query::after_execute` — 查询执行后（审计、结果过滤）
