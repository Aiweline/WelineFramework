# Weline_Vendor

`Weline_Vendor` 负责供应商身份、Website/Store 授权、商品绑定、分账快照、付款台账与退款冲销，`etc/module.php` 当前版本为 `1.5.0`。

## 主要能力

- `VendorRegistryStore`、`VendorAuthorizationService`：持久化供应商身份与 Website 授权版本。
- `VendorStoreAccountBindingService`、`VendorProductBindingService`：绑定 Store 账户引用和规范 Product identity。
- `VendorSettlementService`、`VendorSplitRuleStore`、`VendorSplitSnapshotStore`：维护分账规则和不可变结算快照。
- `VendorPayoutLedger`、`VendorRefundReversalService`：处理幂等付款与追加式退款冲销。
- `VendorRolloutGate`、`VendorMigrationService`：控制能力启用、clone 验证与回退。

## 边界

- Vendor 域归本模块所有，不应写入 Payment、Order、Product 或 Websites 的私有实现。
- Store、Product 等外部身份通过对方公开契约解析；账户绑定只保存非凭据引用和校验 Hash。
- 分账快照写入后不可按新规则回算；同一幂等键携带不同 Payload 必须拒绝。
- 完整冻结合同和迁移命令见 [vendor.md](vendor.md)。

## 文档

- [需求](需求.md)
- [开发日志](开发日志.md)
- [当前源码能力快照](功能现状.md)
- [Vendor 身份与结算合同](vendor.md)
