# Weline_Order::order_status_deleted - 订单状态删除后

## 事件说明

在订单状态删除成功后触发，允许其他模块在状态删除后执行相关操作。

## 触发时机

在订单状态模型删除成功后。

## 数据格式

```php
[
    'status_id' => int,
    'status_name' => string,
]
```

## 可用数据

- `status_id` (int) - 已删除的状态ID
- `status_name` (string) - 已删除的状态名称

## 使用场景

- 清除相关缓存
- 同步删除到外部系统
- 记录删除日志
- 清理相关数据

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusDeletedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $statusId = $data['status_id'] ?? null;
        $statusName = $data['status_name'] ?? '';
        
        if (!$statusId) {
            return;
        }
        
        // 清除缓存
        $this->clearStatusCache($statusId);
        
        // 同步删除到外部系统
        $this->syncDeleteToExternalSystem($statusId);
        
        // 记录日志
        error_log("订单状态已删除: {$statusName} (ID: {$statusId})");
    }
    
    private function clearStatusCache(int $statusId): void
    {
        // 实现缓存清除逻辑
    }
    
    private function syncDeleteToExternalSystem(int $statusId): void
    {
        // 实现同步删除逻辑
    }
}
```

## 注意事项

- 状态已从数据库删除
- 状态对象已不可用
- 建议在观察者中进行异步操作，避免阻塞主流程

## 相关事件

- `Weline_Order::order_status_delete_before` - 订单状态删除前
