# Weline_Order::order_shipped - 订单发货后

## 事件说明

在订单发货成功后触发，允许其他模块在订单发货后执行相关操作，如发送通知、更新物流信息等。

## 触发时机

在 `FulfillmentService::createShipment()` 方法中，发货记录创建成功后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'shipment' => Shipment对象,
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID
- `shipment` (Shipment) - 发货记录对象

## 使用场景

- 发送发货通知（邮件、短信等）
- 更新物流跟踪信息
- 同步发货信息到外部系统
- 记录发货日志
- 触发相关业务流程

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderShippedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $shipment = $data['shipment'] ?? null;
        
        if (!$order || !$orderId || !$shipment) {
            return;
        }
        
        // 发送发货通知
        $this->sendShipmentNotification($order, $shipment);
        
        // 更新物流跟踪
        $this->updateTrackingInfo($order, $shipment);
        
        // 同步到外部系统
        $this->syncToExternalSystem($order, $shipment);
        
        // 记录发货日志
        error_log("订单已发货: #{$order->getOrderNumber()}, 物流单号: {$shipment->getTrackingNumber()}");
    }
    
    private function sendShipmentNotification(Order $order, $shipment): void
    {
        // 实现通知逻辑
    }
    
    private function updateTrackingInfo(Order $order, $shipment): void
    {
        // 实现物流跟踪更新逻辑
    }
    
    private function syncToExternalSystem(Order $order, $shipment): void
    {
        // 实现同步逻辑
    }
}
```

## 注意事项

- 发货记录已保存
- 订单发货状态可能已更新
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_paid` - 订单支付后
- `Weline_Order::order_completed` - 订单完成后
- `Weline_Order::order_status_changed` - 订单状态变更后
