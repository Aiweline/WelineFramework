# Display Number（P2D-003 / DEC-017）

展示号命名空间为 `(website_id, store_id, number_kind, display_number)`。

## 契约

| 项 | 规则 |
|---|---|
| 生成 | `DisplayNumberAllocator` 使用 `random_int`；冲突安全 upsert，最多 5 次 → `display_number_allocate_exhausted` |
| Kind | `order` / `invoice` / `refund`（`DisplayNumberRegistry::KIND_*`） |
| 查号 | 必须带 `number_kind`；省略 → `display_number_kind_required` |
| Bare number | **不提供**跨 kind 公共查号 |
| 同号跨 kind | 允许（TEST-P2D-04） |

## 入口

- Allocator：`Service/DisplayNumberAllocator`（`forTesting()` 内存）
- Lookup：`Service/DisplayNumberLookup`
- Backend Query：`order_admin.lookupDisplayNumber`，强制
  `number_kind + display_number + website_id + store_id` 并校验对象 Scope
- Registry Model：`Model/DisplayNumberRegistry`（additive schema，`setup:upgrade`）
- DTO：`Api/Data/DisplayNumberRef`
- 资金后处理扩展点：`Api/OrderPostPaymentHookInterface` +
  `Api/Data/OrderPaidContext` + `NoopOrderPostPaymentHook`
  （模块 `provides` 默认绑定 Noop，可由后续模块替换）
- Minor-unit 金额 DTO：复用 `Api/Data/MoneySnapshot`（不改 Refund/Invoice 资金逻辑）

`OrderFacade::create` 为每张 Order 分配 `number_kind=order` 展示号；提交失败时与组一并回滚。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Order/Test/Unit/bootstrap.php \
  app/code/Weline/Order/Test/Unit/Service/DisplayNumberAllocatorTest.php
```

当前模块版本：`2.12.1`；需 `php bin/w setup:upgrade --module=Weline_Order`
安装/核对 `weline_display_number_registry`。
