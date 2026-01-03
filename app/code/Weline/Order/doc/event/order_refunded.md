# Weline_Order::order_refunded - 订单退款后

## 事件说明

在订单退款成功后触发，允许其他模块在订单退款后执行相关操作，如发送通知、更新财务记录等。

## 触发时机

在 `PaymentService::refundPayment()` 方法中，退款处理成功后，订单状态更新为 `refunded` 后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'refund' => OrderPayment对象,
    'refund_amount' => float,
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID
- `refund` (OrderPayment) - 退款记录对象
- `refund_amount` (float) - 退款金额

## 使用场景

- 发送退款通知（邮件、短信等）
- 更新财务记录
- 记录退款日志
- 同步退款信息到外部系统
- 更新客户统计

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderRefundedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $refund = $data['refund'] ?? null;
        $refundAmount = $data['refund_amount'] ?? 0;
        
        if (!$order || !$orderId) {
            return;
        }
        
        // 发送退款通知
        $this->sendRefundNotification($order, $refundAmount);
        
        // 更新财务记录
        $this->updateFinancialRecords($order, $refund);
        
        // 同步到外部系统
        $this->syncToExternalSystem($order, $refund);
        
        // 记录退款日志
        error_log("订单已退款: #{$order->getOrderNumber()}, 金额: {$refundAmount}");
    }
    
    private function sendRefundNotification(Order $order, float $amount): void
    {
        // 实现通知逻辑
    }
    
    private function updateFinancialRecords(Order $order, $refund): void
    {
        // 实现财务记录更新逻辑
    }
    
    private function syncToExternalSystem(Order $order, $refund): void
    {
        // 实现同步逻辑
    }
}
```

## 注意事项

- 订单状态已更新为 `refunded`
- 订单处于终态，通常不会再变更
- 退款记录已保存
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_cancelled` - 订单取消后
- `Weline_Order::order_status_changed` - 订单状态变更后
