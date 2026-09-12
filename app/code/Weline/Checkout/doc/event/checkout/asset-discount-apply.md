# Weline_Checkout::checkout::asset_discount::apply - 会话资产折扣应用

## 事件说明

submit 前触发。**Payment** Observer 钳制抵扣并写入 `type_payload`。

## 边界

- **B2B**：只提供批发信用类型策略，不写结账金额。
- **Payment**：贡献额度数据与 `discount_kind=asset_b2b_credit`。
- **Checkout**：经事件读取，不硬耦合业务具体类。
