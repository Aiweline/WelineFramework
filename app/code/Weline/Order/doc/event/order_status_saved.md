# Weline_Order::order_status_saved - 订单状态保存后

## 事件说明

在订单状态保存到数据库后触发，允许其他模块在状态保存后执行相关操作。

## 触发时机

在订单状态模型保存成功后。

## 数据格式

```php
[
    'status' => OrderStatus对象,
    'status_id' => int,
]
```

## 可用数据

- `status` (OrderStatus) - 已保存的订单状态对象
- `status_id` (int) - 状态ID

## 使用场景

- 同步状态数据到外部系统
- 清除相关缓存
- 记录状态保存日志
- 触发其他业务流程

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusSavedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $status = $data['status'] ?? null;
        $statusId = $data['status_id'] ?? null;
        
        if (!$status || !$statusId) {
            return;
        }
        
        // 清除缓存
        $this->clearStatusCache($statusId);
        
        // 同步到外部系统
        $this->syncToExternalSystem($status);
        
        // 记录日志
        error_log("订单状态已保存: {$status->getName()}");
    }
    
    private function clearStatusCache(int $statusId): void
    {
        // 实现缓存清除逻辑
    }
    
    private function syncToExternalSystem($status): void
    {
        // 实现同步逻辑
    }
}
```

## 注意事项

- 状态已保存到数据库
- 建议在观察者中进行异步操作，避免阻塞主流程

## 相关事件

- `Weline_Order::order_status_save_before` - 订单状态保存前
