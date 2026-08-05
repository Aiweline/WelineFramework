# Order PayableResolver（weline_order）

## 位置

`extends/module/Weline_Payment/PayableResolver/OrderPayableResolver.php`

`payable_type`：`weline_order`

## 行为

- `resolve` / `snapshot`：经必需的 `OrderFacadeInterface` 从 Order UUID
  读取冻结 `MoneySnapshot` + `ScopeSnapshot`（minor-unit）
- `OrderReadResult.customerId`：把持久 Order owner 带入公开只读投影，Resolver
  冻结为 `owner.actor_type=customer`，避免真实 registry 路径把客户单降级成 guest
- `canPay`：仅 `open|pending|partially_paid`；客户单校验 Actor 与 owner
- `onPaid`：通知 `OrderFacadeInterface::notifyOrderPaid`（不直接改 Refund/Invoice 资金路径）

## 依赖

Payment Facade V2 经 `PayableResolverRegistry` 获取快照；调用方传入的金额、币种、
merchant account 或 Provider reference 均不作为支付事实。Resolver 构造器的
`OrderFacadeInterface` 不得改回 nullable，否则容器会跳过注入并导致真实订单不可解析。

详见 [`Weline_Payment` facade-v2.md](../../Payment/doc/facade-v2.md)。
