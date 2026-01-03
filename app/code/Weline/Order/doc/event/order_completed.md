# Weline_Order::order_completed - 订单完成后

## 事件说明

在订单完成后触发，允许其他模块在订单完成后执行相关操作，如发送通知、发放积分、更新客户统计等。

## 触发时机

在订单状态转换为 `completed` 后触发。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID

## 使用场景

- 发送订单完成通知
- 发放积分或奖励
- 更新客户统计（订单数、消费金额等）
- 触发评价邀请
- 记录完成日志
- 同步完成状态到外部系统

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderCompletedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'] ?? null;
        $orderId = $data['order_id'] ?? null;
        
        if (!$order || !$orderId) {
            return;
        }
        
        // 发送完成通知
        $this->sendCompletionNotification($order);
        
        // 发放积分
        $this->awardPoints($order);
        
        // 更新客户统计
        $this->updateCustomerStatistics($order);
        
        // 触发评价邀请
        $this->requestReview($order);
        
        // 记录完成日志
        error_log("订单已完成: #{$order->getOrderNumber()}");
    }
    
    private function sendCompletionNotification(Order $order): void
    {
        // 实现通知逻辑
    }
    
    private function awardPoints(Order $order): void
    {
        // 实现积分发放逻辑
    }
    
    private function updateCustomerStatistics(Order $order): void
    {
        // 实现客户统计更新逻辑
    }
    
    private function requestReview(Order $order): void
    {
        // 实现评价邀请逻辑
    }
}
```

## 注意事项

- 订单状态已更新为 `completed`
- 订单处于终态，通常不会再变更
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_shipped` - 订单发货后
- `Weline_Order::order_status_changed` - 订单状态变更后
