# Weline_Order::order_status_delete_before - 订单状态删除前

## 事件说明

在订单状态删除前触发，允许其他模块在状态删除前进行验证或阻止删除。

## 触发时机

在订单状态模型删除前触发。

## 数据格式

```php
[
    'status' => OrderStatus对象,
    'status_id' => int,
    'can_delete' => bool,  // 观察者可以设置为false来阻止删除
]
```

## 可用数据

- `status` (OrderStatus) - 要删除的订单状态对象
- `status_id` (int) - 状态ID
- `can_delete` (bool) - 是否允许删除（可修改）

## 使用场景

- 验证状态是否可以被删除（如检查是否有订单使用此状态）
- 阻止删除正在使用的状态
- 在删除前执行清理操作

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusDeleteBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $statusId = $data['status_id'] ?? null;
        
        if (!$statusId) {
            return;
        }
        
        // 检查是否有订单使用此状态
        $orderCount = $this->getOrderCountByStatus($statusId);
        if ($orderCount > 0) {
            $data['can_delete'] = false;
            $event->setData($data);
            throw new \Exception(__('该状态正在被 %{1} 个订单使用，不能删除', [$orderCount]));
        }
    }
    
    private function getOrderCountByStatus(int $statusId): int
    {
        // 查询使用此状态的订单数量
        // 实现查询逻辑
        return 0;
    }
}
```

## 注意事项

- 通过设置 `can_delete` 为 `false` 可以阻止删除
- 如果阻止删除，建议抛出异常说明原因
- 状态对象尚未从数据库删除

## 相关事件

- `Weline_Order::order_status_deleted` - 订单状态删除后
