# Checkout V2 / Shipping Quote（P2E-002）

## 契约

- `ShippingQuoteServiceInterface::listOptions` / `quote` — minor-unit；缺币价/模板/version **fail-closed**
- `CheckoutGroupSubmitService`：可信 Cart freeze/reprice（单次 Quote）→
  owner 100/0 分摊 → Tax 冻结快照 → Inventory reserve →
  `OrderFacadeInterface::create`
- 客户端 `shipping_*` / `tax_*` / `grand_total*` **拒绝**
- 客户端 `lines` / Scope / currency / config version / `customer_id`
  只作为伪造事实检测，绝不成为订单输入
- 多法务主体共收运费 → `checkout_shipping_combo_blocked`
- 全虚拟：shipping=0；混合：首张需配送 Order 为 owner
- Quote Session 持久化 `quoted → submitting → submitted`；同 token +
  同幂等键重放已提交结果，不同键、请求 hash 或配置漂移稳定冲突
- Inventory reservation、CheckoutGroup/Orders 和 Session submitted 状态
  加入同一个默认连接事务，任一步失败均整体回滚
- MIG-P3A durable writer flag 关闭时只保留 P2 Reservation；开启时先通过
  `WarehouseInventoryCapabilityInterface` 幂等绑定同一 Reservation 到可信
  默认 Warehouse，再创建来源为 `warehouse` 的 FulfillmentUnit。writer
  capability 缺失时 fail closed，不得静默回退。
- MIG-P3A 尚未建立默认逻辑仓时，`DefaultWarehouseResolverInterface::ERROR_MISSING`
  表示尚无可切流事实，submit 继续使用 P2 legacy 路径且不写 Warehouse
  分配；歧义、非法、跨环境或未授权解析错误仍然阻断提交。
- 实际 cutover 旧 Checkout writer 不在本任务（已有 P2D-004 guard）
- Tax `off`/`shadow` 只写 `none/none/0`，不改变成交金额；仅
  `allowlist` 命中或 `on` 使用服务端 TaxEngine 结果。
- Tax 请求由冻结订单行的稳定 `line_uuid`、Scope、地址和 currency 构造；
  缺行、重复行、同版本同 Scope LKG 缺失或无法回放均阻断。
- submit 从持久 CheckoutSession 重建同一请求校验 Quote 的
  `rule_set_hash`，不依赖报价 Worker 的内存状态；规则变化要求重报价。
- 订单事务完成后，支付由 `CheckoutOrderPaymentService` 归一化为
  `paid` / `pending` / `failed`。顶层 submit 成功只表示 CheckoutGroup
  已创建或回放，不能替代支付结果。
- `CheckoutPaymentRecoveryStateService` 记录已提交 Session 的支付结果；
  `resumePaymentV2` 原子领取一次恢复尝试并为支付生成新的幂等键，始终复用
  原 CheckoutGroup/Orders，不再次调用订单 writer。
- 成功页必须持有已提交 CheckoutSession 生成的短期高熵
  `checkout_token`。WLS 跨 Worker 跳转尚未恢复前台身份时，该 token
  作为成功页能力凭证；请求中已有登录身份时，仍拒绝与冻结 customer
  不一致的访问。订单详情继续由 customer + website 所有权验证。

## 入口

| 组件 | 路径 |
|---|---|
| Quote DTO/API | `Shipping/Api/Quote/*`、`ScopedShippingQuoteService` |
| 分摊 | `Checkout/Service/ShippingAllocationService` |
| 提交 | `Checkout/Service/CheckoutGroupSubmitService` |
| Session Model | `Checkout/Model/CheckoutSession`（additive） |
| Cart boundary | `Cart/Api/CheckoutCartSnapshotInterface`（服务端身份、Scope、重新定价） |
| Query | `shippingInfo.listQuoteOptions` / `quote`；`checkout.freezeQuote` / `submitV2` / `resumePaymentV2`（Session ORM 跨 Worker；成功 submit 经 OrderFacade DB writer 落库） |
| Payment recovery | `CheckoutOrderPaymentService`、`CheckoutPaymentRecoveryStateService`、`CheckoutSessionAccessService` |
| Session | `CheckoutSessionStoreInterface` → `OrmCheckoutSessionStore`（表 `weline_checkout_session`） |
| Order/Inventory | `OrderFacadeInterface` + `InventoryCapabilityInterface`；Warehouse cutover 仅依赖 `DefaultWarehouseResolverInterface` + `WarehouseInventoryCapabilityInterface`，Checkout 不引用对方内部 Model/Service |
| E2E | `plan-p2e002-current-source.spec.js` 使用真实数据库 Shipping、Cart、Session、Order 与 Inventory |

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Checkout/Test/Unit/bootstrap.php \
  app/code/Weline/Checkout/Test/Unit
php bin/w e2e:run app/code/Weline/Checkout/test/e2e/frontend/plan-p2e002-current-source.spec.js \
  --project=chromium --headless
```

`plan-p2e002-current-source.spec.js` 覆盖 `TEST-P2E-04..08`：可信 Cart
与伪造事实拒绝、数据库配置漂移、跨 Worker 同键重放、单次 Quote、
真实预占与同库回滚、Tax off/shadow、精确 LKG、跨 Worker 版本固定、
拆单逐行守恒、全虚拟及混合履约。DEC-015
运费归属按 `split_key` 排序后的首张需配送 Order 为 owner；owner 内按
最大余数法分摊且合计不丢分。

模块：`Weline_Checkout` `1.4.4`；`Weline_Cart` `1.2.1`；
`Weline_Shipping` `2.2.0`；`Weline_Inventory` `2.5.5`；
`Weline_Order` `2.12.5`；`Weline_Tax` `2.1.2`
（`setup:upgrade` 建 `weline_checkout_session` 和新增 Tax 快照列）。
