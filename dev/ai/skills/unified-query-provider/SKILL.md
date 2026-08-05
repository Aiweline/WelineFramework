---
name: unified-query-provider
description: Implement and consume Weline w_query and cross-module QueryProvider contracts, including provider discovery, introspection, and query:help. Use for stable cross-module read APIs; use ordinary module ORM for same-module persistence and weline-api for browser transport.
---

# w_query 与 QueryProvider

## 边界

- 新增稳定的跨模块读取契约时使用 QueryProvider / `w_query()`；已有发布接口时遵循其 owning contract。
- 禁止为读取数据而跨模块 `use`、注入、`ObjectManager::getInstance` 或 `new` 对方 Service/Model/Helper。
- 浏览器传输仍使用 `Weline.Api.resource()`、`graph()` 或 `stream()`；不要把 `w_query()` 当成新的传输协议。
- 调用前先查帮助：`php bin/w query:help <provider|WeShop_Product>` 或 `w_query('模块名')`

## PHP 帮助

```php
w_query();                              // 全部 provider 摘要
w_query('widget');                      // widget 完整 descriptor
w_query('WeShop_Product');              // 按模块名解析
w_query('widget', 'getAvailableList', ['page_type' => 'homepage']);
w_query('framework', 'introspect', ['what' => 'providers']);
```

## CLI

```bash
php bin/w query:help
php bin/w query:help widget
php bin/w query:help WeShop_Product
php bin/w query:help product getById --json
```

## 浏览器帮助

```js
await w_query('cart');           // 仅 frontend=true 的 operations
await Weline.Query.help('cart');
```

完整服务端契约以 PHP/CLI 为准；浏览器帮助为 frontend 暴露子集。

## 实现 QueryProvider

- 路径：`extends/module/Weline_Framework/Query/{Module}QueryProvider.php`
- 实现 `QueryProviderInterface`：`getProviderName()`、`execute()`、`getDescriptor()`
- `getDescriptor()` 必须列出全部 operations 与 params，供 `query:help` / introspect 发现
- 前端暴露的 operation 须在 descriptor 中声明 `frontend=true`、`mode`、`params`

## 协作边界

- 已有读取契约：遵循 owning published interface；新增可发现的跨模块读取：QueryProvider + `w_query`
- 写入和副作用：owning Interface、Event、Hook、Queue 或其他已发布命令边界
- 不要用 Event 代替查询 API

## 参考

- `app/code/Weline/Event/doc/w_query.md`
- `app/code/Weline/Framework/Service/Query/Provider/QueryProviderInterface.php`
