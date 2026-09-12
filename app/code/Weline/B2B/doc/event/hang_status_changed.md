# Weline_B2B — hang 状态变更事件

## 概述

`Weline_B2B::hang_status_changed` 在 ToB hang 生命周期状态写入后派发，供旁路 Observer（投影、通知、审计）扩展。**禁止**跨模块直调 `B2BHangOrderService` 处理旁路逻辑。

## 基本信息

| 项 | 值 |
|----|-----|
| 事件名 | `Weline_B2B::hang_status_changed` |
| 派发方 | `Weline\B2B\Service\B2BHangOrderService`（状态 `put` 后） |
| 支付入口 | `Weline\B2B\Api\B2BHangPaymentBridgeInterface`（唯一 soft 入口） |
| 配置 | 观察者在消费模块 `etc/event.xml` 注册；本模块可不挂默认 Observer |

## 触发时机

- 创建 hang / 定金确认 / 商家批准或驳回 / 尾款确认 / 其他 hang 状态推进之后

## 数据契约

```php
$eventsManager->dispatch('Weline_B2B::hang_status_changed', [
    'order_type' => 'tob',
    'order_ref' => (string) $hang->orderRef,
    'hang_id' => (string) $hang->hangId,
    'hang_status' => (string) $hang->hangStatus,
    'type_payload' => $hang->typePayload(), // array
    'website_id' => (int) $hang->websiteId,
]);
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `order_type` | string | 固定 `tob` |
| `order_ref` | string | Order UUID |
| `hang_id` | string | Hang 主键 |
| `hang_status` | string | 当前 hang 状态 |
| `type_payload` | array | Hang 投影片段（deposit/balance/hang_status 等） |
| `website_id` | int | 网站范围 |

## Observer 示例

```xml
<event name="Weline_B2B::hang_status_changed">
    <observer name="Vendor_Module::on_b2b_hang_status_changed"
              instance="Vendor\Module\Observer\HangStatusChangedObserver"
              disabled="false" shared="false" sort="100"/>
</event>
```

## 相关

- [hang-status-state.md](../hang-status-state.md)
- [扩展点选型](../../Framework/doc/3-开发/扩展点选型.md)
