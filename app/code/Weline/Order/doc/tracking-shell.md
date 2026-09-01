# 订单跟踪壳（Tracking Shell）

对齐万能支付壳：`Weline_Order` 拥有跟踪状态入口、反馈 Inbox、统一回调 URL 与 Provider 扫描；第三方只实现 `TrackingProviderInterface`。

## 边界

| 能力 | 归属 |
|---|---|
| **主入口** 个人中心订单详情/列表 | `Weline_Order` 账户 Hook |
| 旧 `/orders/track` 书签 | `Order\Controller\Router` 302 → 账户订单（无独立页） |
| Provider 解析 + 轨迹 | `OrderTrackingService` + `TrackingProvider` |
| 无物流商 | 内置 `system`：已发货 → 发往目的地 |

## 统一 URL

- 前台主入口：`customer/account/index?order_uuid={uuid}#orders`
- 反馈通知：`order/frontend/tracking-callback/notify?endpoint_code={method}.{env}.default`
- 开发探针：`order/frontend/dev-relay/probe`（仅 DEV / 显式启用）
- 开发事件列表：`order/frontend/dev-relay/events`

禁止每个 Provider 再登记独立 notify URL。

## 内置 Provider

| code | 说明 |
|---|---|
| `system` | 系统订单追踪 |
| `fake_carrier` | 开发演示正式物流 |

## 相关文档

- [tracking-provider-development.md](tracking-provider-development.md)
- [dev-tracking-relay.md](dev-tracking-relay.md)
- [extends.md](../extends.md)
