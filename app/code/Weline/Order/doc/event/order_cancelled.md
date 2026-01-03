# Weline_Order::order_cancelled - 订单取消后

## 事件说明

在订单取消成功后触发，允许其他模块在订单取消后执行相关操作，如恢复库存、退款、发送通知等。

## 触发时机

在 `OrderService::cancelOrder()` 方法中，订单状态转换为 `cancelled` 后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'reason' => string,
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID
- `reason` (string) - 取消原因

## 使用场景

- 恢复库存（释放预留的库存）
- 处理退款（如果已支付）
- 发送取消通知（邮件、短信等）
- 记录取消日志
- 更新客户统计
- 同步取消状态到外部系统

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderCancelledObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $reason = $data['reason'] ?? '';
        
        if (!$order || !$orderId) {
            return;
        }
        
        // 恢复库存
        $this->restoreInventory($order);
        
        // 处理退款（如果已支付）
        $paymentStatus = $order->getPaymentStatus();
        if ($paymentStatus === 'paid') {
            $this->processRefund($order);
        }
        
        // 发送取消通知
        $this->sendCancellationNotification($order, $reason);
        
        // 记录取消日志
        error_log("订单已取消: #{$order->getOrderNumber()}, 原因: {$reason}");
    }
    
    private function restoreInventory(Order $order): void
    {
        // 实现库存恢复逻辑
    }
    
    private function processRefund(Order $order): void
    {
        // 实现退款处理逻辑
    }
    
    private function sendCancellationNotification(Order $order, string $reason): void
    {
        // 实现通知逻辑
    }
}
```

## 注意事项

- 订单状态已更新为 `cancelled`
- 订单处于终态，通常不会再变更
- 如果订单已支付，需要处理退款
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_status_changed` - 订单状态变更后
- `Weline_Order::order_refunded` - 订单退款后
