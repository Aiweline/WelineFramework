# Weline_Order::order_status_changed - 订单状态变更后

## 事件说明

在订单状态变更成功后触发，允许其他模块在状态变更后执行相关操作，如发送通知、更新库存、记录历史等。

## 触发时机

在 `OrderStateMachine::transition()` 方法中，状态更新到数据库后。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'old_status' => string,
    'new_status' => string,
    'comment' => string|null,
    'notify_customer' => bool,
]
```

## 可用数据

- `order` (Order) - 更新后的订单对象
- `order_id` (int) - 订单ID
- `old_status` (string) - 旧状态
- `new_status` (string) - 新状态
- `comment` (string|null) - 状态变更备注
- `notify_customer` (bool) - 是否通知客户

## 使用场景

- 发送状态变更通知（邮件、短信等）
- 更新库存（如取消订单时恢复库存）
- 记录订单历史
- 触发其他业务流程
- 同步状态到外部系统

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Model\Order;

class OrderStatusChangedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'];
        $oldStatus = $data['old_status'];
        $newStatus = $data['new_status'];
        $notifyCustomer = $data['notify_customer'] ?? false;
        
        // 订单取消时恢复库存
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            $this->restoreInventory($order);
        }
        
        // 订单完成时发放积分
        if ($newStatus === 'completed' && $oldStatus !== 'completed') {
            $this->awardPoints($order);
        }
        
        // 发送状态变更通知
        if ($notifyCustomer) {
            $this->sendStatusChangeNotification($order, $oldStatus, $newStatus);
        }
        
        // 记录状态变更日志
        $this->logStatusChange($order, $oldStatus, $newStatus);
    }
    
    private function restoreInventory(Order $order): void
    {
        // 实现库存恢复逻辑
    }
    
    private function awardPoints(Order $order): void
    {
        // 实现积分发放逻辑
    }
    
    private function sendStatusChangeNotification(Order $order, string $oldStatus, string $newStatus): void
    {
        // 实现通知逻辑
    }
    
    private function logStatusChange(Order $order, string $oldStatus, string $newStatus): void
    {
        // 实现日志记录逻辑
    }
}
```

## 注意事项

- 状态已更新到数据库
- 订单历史记录通常由 `OrderStatusChangedObserver` 处理
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Order::order_status_change_before` - 订单状态变更前
- `Weline_Order::order_paid` - 订单支付后
- `Weline_Order::order_cancelled` - 订单取消后
