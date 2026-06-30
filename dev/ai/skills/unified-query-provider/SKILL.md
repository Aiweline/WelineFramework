---
name: unified-query-provider
description: w_query 与跨模块 QueryProvider 契约；跨模块读数据、帮助发现、introspect、query:help CLI。
version: 1.0.0
---

# w_query 与 QueryProvider

## 何时使用

- 关键词：`w_query`、`QueryProvider`、`query:help`、`introspect`、跨模块读数据
- 任何模块需要读取另一模块数据，或 AI/开发需要发现 provider 支持的 operations

## 跨模块禁令（强制）

- 禁止跨模块 `use`/注入/`ObjectManager::getInstance`/`new` 引用对方 Service/Model/Helper
- 跨模块读数据**必须**使用 `w_query()` 或浏览器 `Weline.Api.*`
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

- 读数据：QueryProvider + `w_query`
- 写/副作用：Event、Hook、Queue、已发布 Interface
- 不要用 Event 代替查询 API

## 参考

- `dev/ai/global-constraints.md` 第 4.1 节
- `app/code/Weline/Event/doc/w_query.md`
- `app/code/Weline/Framework/Service/Query/Provider/QueryProviderInterface.php`
