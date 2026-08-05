# Weline_Checkout::checkout::legacy_writer::assert

## 目的

在 legacy Checkout Order writer 进入业务校验与数据库事务之前执行同步门禁。
该事件用于 Checkout→Order 单写切换，Checkout 本身不引用 Order 的内部
Service 或 Model。

## 触发时机

`CheckoutService::createOrder()` 完成结账身份标准化后、调用
`validateCheckout()` 和 `beginTransaction()` 之前。

## Payload

```php
[
    'data' => [
        'website_id' => 0,
        'store_id' => 0,
        // normalized checkout payload
    ],
]
```

`website_id=0` 是合法的系统默认站点，不得视为空值。缺少 `website_id` 时，
allowlist 模式必须 fail-closed，不能因无法判定 subject 而绕过切换门禁。

## Observer 约束

- 必须是 `delivery="sync"`、`failure="critical"`。
- 只做 writer 可写性判定，不执行 DML、锁、预占、outbox 或 cache write。
- 拒绝时直接抛出稳定业务异常；异常必须在事务开始前向上传播。
- 不得修改 payload，也不得把该事件用于跨模块读取数据。

