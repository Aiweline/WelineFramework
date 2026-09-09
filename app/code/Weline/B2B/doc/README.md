# Weline_B2B

`Weline_B2B` 是 B2B 客户组、价目表、报价重验与订单价格快照的归属模块，当前模块版本为 `2.3.0`。

## 主要能力

- `B2BPriceCandidateInterface` / `B2BPriceEngine`：按客户、Website、Channel 与价目版本解析服务端价格候选。
- `B2BCheckoutRecheckInterface` / `B2BCheckoutRecheckService`：提交前重验报价 token、会员关系与价格版本。
- `B2BOrderSnapshotStore`：在订单边界保存不可变价格快照，避免后续价目变化回算旧单。
- `B2BRolloutGate`、`B2BMigrationService`：控制能力启用及 clone 迁移、验证和回退。
- 后台 Control Center、只读 QueryProvider 与迁移 CLI 为运维和验收入口。

## 边界

- B2B 价格规则归本模块所有，不应写入 Product、Cart、Checkout 或 Order 的私有实现。
- 客户组、Website、SKU、报价版本等身份均由服务端事实解析；客户端声明不能覆盖这些事实。
- 详细冻结合同、验证命令与迁移约束见 [b2b.md](b2b.md)。

## 文档

- [需求](需求.md)
- [开发日志](开发日志.md)
- [当前源码能力快照](功能现状.md)
- [B2B 价格与迁移合同](b2b.md)
