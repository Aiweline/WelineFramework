# Weline_Order::order_updated - 订单更新后

## 事件说明

在订单更新成功后触发，允许其他模块在订单更新后执行相关操作，如发送通知、同步数据等。

## 触发时机

在 `OrderService::updateOrder()` 方法中，订单更新成功并提交事务后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
]
```

## 可用数据

- `order` (Order) - 更新后的订单对象
- `order_id` (int) - 订单ID

## 使用场景

- 发送订单更新通知
- 同步订单数据到外部系统
- 记录订单变更日志
- 触发相关业务流程

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderUpdatedObserver implements ObserverInterface
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
        
        // 同步到外部系统
        $this->syncToExternalSystem($order);
        
        // 记录变更日志
        $this->logOrderChange($order);
    }
    
    private function syncToExternalSystem(Order $order): void
    {
        // 实现同步逻辑
    }
    
    private function logOrderChange(Order $order): void
    {
        // 实现日志记录逻辑
    }
}
```

## 注意事项

- 订单已更新到数据库
- 如果更新了订单项，订单总额会重新计算
- 建议在观察者中进行异步操作，避免阻塞主流程

## 相关事件

- `Weline_Order::order_created` - 订单创建后
- `Weline_Order::order_status_changed` - 订单状态变更后
