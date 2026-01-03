# Weline_Order::order_created - 订单创建后

## 事件说明

在订单创建成功后触发，允许其他模块在订单创建后执行相关操作，如发送通知、更新库存、记录日志等。

## 触发时机

在 `OrderService::createOrder()` 方法中，订单创建成功并提交事务后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
]
```

## 可用数据

- `order` (Order) - 订单对象，包含完整的订单信息
- `order_id` (int) - 订单ID

## 使用场景

- 发送订单创建通知（邮件、短信等）
- 更新库存（预留库存）
- 记录订单创建日志
- 触发其他业务流程（如积分、优惠券等）
- 同步订单到外部系统

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderCreatedObserver implements ObserverInterface
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
        
        // 发送订单创建通知
        $this->sendOrderCreatedNotification($order);
        
        // 更新库存
        $this->reserveInventory($order);
        
        // 记录日志
        error_log("订单创建: #{$order->getOrderNumber()}, 金额: {$order->getGrandTotal()}");
    }
    
    private function sendOrderCreatedNotification(Order $order): void
    {
        // 实现通知逻辑
    }
    
    private function reserveInventory(Order $order): void
    {
        // 实现库存预留逻辑
    }
}
```

## 注意事项

- 订单已保存到数据库
- 订单项已创建
- 订单总额已计算
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_updated` - 订单更新后
- `Weline_Order::order_status_changed` - 订单状态变更后
