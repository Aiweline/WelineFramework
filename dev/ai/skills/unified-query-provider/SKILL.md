---
name: unified-query-provider
description: 模块间查询必须用 QueryProvider/w_query()。禁止为查询创建独立事件。查询型用 Provider，通知型用 Event。
globs:
  - "**/extends/module/Weline_Framework/Query/*.php"
  - "**/Service/Query/**/*.php"
alwaysApply: false
---

# unified-query-provider（极简版）

## 何时使用

- 模块间查询/获取数据
- 模块间 CRUD 操作
- 为其他模块提供数据能力
- 从其他模块获取信息

## 必做

- 查询型（读数据、做操作）→ QueryProvider，禁止用事件
- 通知型（某事发生、多模块响应）→ Event
- 在 extends/module/Weline_Framework/Query/ 下实现 QueryProviderInterface
- 实现 getProviderName()、execute(operation, params)

## 最小示例

```php
// 注册 Provider
class XxxQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string { return 'xxx'; }
    public function execute(string $operation, array $params = []): mixed { }
}
```

```php
// 调用
w_query('xxx', 'getList', ['page' => 1]);
```

## 禁止

- 为查询/获取数据创建事件
- 模块间直接 new 其他模块的 Model
