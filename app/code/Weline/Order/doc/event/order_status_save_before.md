# Weline_Order::order_status_save_before - 订单状态保存前

## 事件说明

在订单状态保存到数据库前触发，允许其他模块在状态保存前进行验证或修改状态数据。

## 触发时机

在订单状态模型保存前触发。

## 数据格式

```php
[
    'status' => OrderStatus对象,
    'status_id' => int,
    'status_data' => array,
]
```

## 可用数据

- `status` (OrderStatus) - 订单状态对象
- `status_id` (int) - 状态ID（如果是更新）
- `status_data` (array) - 状态数据数组（可修改）

## 使用场景

- 验证状态数据
- 修改状态数据（如自动设置某些字段）
- 在保存前执行预处理操作

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusSaveBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $statusData = $data['status_data'] ?? [];
        
        // 自动设置创建时间
        if (empty($statusData['created_at'])) {
            $statusData['created_at'] = date('Y-m-d H:i:s');
        }
        
        // 验证状态名称
        if (empty($statusData['name'])) {
            throw new \Exception(__('状态名称不能为空'));
        }
        
        $event->setData('status_data', $statusData);
    }
}
```

## 注意事项

- 可以修改 `status_data` 来改变保存的数据
- 如果验证失败，应该抛出异常
- 状态对象可能尚未保存到数据库

## 相关事件

- `Weline_Order::order_status_saved` - 订单状态保存后
