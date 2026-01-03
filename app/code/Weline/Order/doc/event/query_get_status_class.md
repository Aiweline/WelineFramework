# Weline_Order::query::get_status_class - 获取订单状态CSS类

## 事件说明

在获取订单状态CSS类时触发，允许其他模块扩展状态样式。观察者可以设置 `class` 字段来提供自定义的CSS类名。

## 触发时机

在 `Order::getStatusClassByCode()` 静态方法中，通过事件机制获取状态CSS类时。

## 数据格式

```php
[
    'status' => string,
    'class' => string,  // 观察者可以设置此值
]
```

## 可用数据

- `status` (string) - 状态代码（如：pending, processing, paid等）
- `class` (string) - CSS类名（可修改，观察者设置）

## 使用场景

- 提供自定义状态样式
- 根据状态返回不同的CSS类（如Bootstrap的badge类）
- 扩展新的状态样式
- 实现动态状态样式

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class GetStatusClassObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $status = $data['status'] ?? '';
        
        if (empty($status)) {
            return;
        }
        
        // 如果已经有CSS类，不覆盖
        if (!empty($data['class'])) {
            return;
        }
        
        // 提供自定义状态CSS类（Bootstrap样式）
        $classes = [
            'pending' => 'warning',
            'processing' => 'info',
            'paid' => 'success',
            'fulfilled' => 'primary',
            'completed' => 'success',
            'cancelled' => 'danger',
            'refunded' => 'secondary',
        ];
        
        if (isset($classes[$status])) {
            $event->setData('class', $classes[$status]);
        }
    }
}
```

## 注意事项

- 如果观察者设置了 `class`，将使用该CSS类，不再使用默认值
- 如果多个观察者都设置了 `class`，后执行的会覆盖前面的
- 建议检查 `class` 是否已设置，避免覆盖其他观察者的结果
- CSS类名应该符合前端框架的规范（如Bootstrap的badge类）

## 相关事件

- `Weline_Order::query::get_status_label` - 获取订单状态标签
- `Weline_Order::domain::resolve_status_info` - 解析完整状态信息
