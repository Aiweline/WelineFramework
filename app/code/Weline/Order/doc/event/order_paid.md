# Weline_Order::order_paid - 订单支付后

## 事件说明

在订单支付成功后触发，允许其他模块在订单支付后执行相关操作，如发送通知、更新库存、触发发货流程等。

## 触发时机

在 `PaymentService::recordPayment()` 方法中，当订单支付金额达到订单总额时，订单状态更新为已支付后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'payment' => OrderPayment对象,
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID
- `payment` (OrderPayment) - 支付记录对象

## 使用场景

- 发送支付成功通知（邮件、短信等）
- 更新库存（确认库存预留）
- 触发发货流程
- 发放积分或优惠券
- 记录支付日志
- 同步支付信息到外部系统

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderPaidObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $payment = $data['payment'] ?? null;
        
        if (!$order || !$orderId) {
            return;
        }
        
        // 发送支付成功通知
        $this->sendPaymentNotification($order, $payment);
        
        // 确认库存预留
        $this->confirmInventoryReservation($order);
        
        // 发放积分
        $this->awardPoints($order);
        
        // 触发发货流程（如果适用）
        $this->triggerFulfillmentProcess($order);
        
        // 记录支付日志
        error_log("订单支付成功: #{$order->getOrderNumber()}, 金额: {$payment->getAmount()}");
    }
    
    private function sendPaymentNotification(Order $order, $payment): void
    {
        // 实现通知逻辑
    }
    
    private function confirmInventoryReservation(Order $order): void
    {
        // 实现库存确认逻辑
    }
    
    private function awardPoints(Order $order): void
    {
        // 实现积分发放逻辑
    }
    
    private function triggerFulfillmentProcess(Order $order): void
    {
        // 实现发货流程触发逻辑
    }
}
```

## 注意事项

- 订单支付状态已更新为 `paid`
- 订单状态可能已转换为 `paid`（通过状态机）
- 支付记录已保存
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_created` - 订单创建后
- `Weline_Order::order_shipped` - 订单发货后
- `Weline_Order::order_status_changed` - 订单状态变更后
