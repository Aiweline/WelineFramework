# Weline_Order::domain::resolve_status_info - 解析完整状态信息

## 事件说明

在解析订单状态的完整信息时触发，允许其他模块扩展状态信息（标签、CSS类、图标、颜色、描述等）。观察者可以设置相应的字段来提供完整的状态信息。

## 触发时机

在 `StatusHelper::resolveStatusInfo()` 静态方法中，通过事件机制解析完整状态信息时。

## 数据格式

```php
[
    'status' => string,
    'label' => string,        // 可修改
    'class' => string,        // 可修改
    'icon' => string|null,    // 可修改
    'color' => string|null,   // 可修改
    'description' => string|null,  // 可修改
]
```

## 可用数据

- `status` (string) - 状态代码
- `label` (string) - 状态标签（可修改）
- `class` (string) - CSS类名（可修改）
- `icon` (string|null) - 图标类名（可修改）
- `color` (string|null) - 颜色值（可修改）
- `description` (string|null) - 状态描述（可修改）

## 使用场景

- 提供完整的状态信息（标签、样式、图标等）
- 实现统一的状态信息管理
- 扩展状态的可视化信息
- 实现动态状态信息

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ResolveStatusInfoObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $status = $data['status'] ?? '';
        
        if (empty($status)) {
            return;
        }
        
        // 定义完整的状态信息
        $statusInfo = [
            'pending' => [
                'label' => __('待处理'),
                'class' => 'warning',
                'icon' => 'fa-clock',
                'color' => '#ffc107',
                'description' => __('订单已创建，等待处理'),
            ],
            'processing' => [
                'label' => __('处理中'),
                'class' => 'info',
                'icon' => 'fa-cog',
                'color' => '#17a2b8',
                'description' => __('订单正在处理中'),
            ],
            'paid' => [
                'label' => __('已支付'),
                'class' => 'success',
                'icon' => 'fa-check-circle',
                'color' => '#28a745',
                'description' => __('订单已支付，等待发货'),
            ],
            'fulfilled' => [
                'label' => __('已发货'),
                'class' => 'primary',
                'icon' => 'fa-truck',
                'color' => '#007bff',
                'description' => __('订单已发货，运输中'),
            ],
            'completed' => [
                'label' => __('已完成'),
                'class' => 'success',
                'icon' => 'fa-check-double',
                'color' => '#28a745',
                'description' => __('订单已完成，客户已收货'),
            ],
            'cancelled' => [
                'label' => __('已取消'),
                'class' => 'danger',
                'icon' => 'fa-times-circle',
                'color' => '#dc3545',
                'description' => __('订单已取消'),
            ],
            'refunded' => [
                'label' => __('已退款'),
                'class' => 'secondary',
                'icon' => 'fa-undo',
                'color' => '#6c757d',
                'description' => __('订单已退款'),
            ],
        ];
        
        if (isset($statusInfo[$status])) {
            $info = $statusInfo[$status];
            
            // 只设置未设置的字段
            if (empty($data['label'])) {
                $event->setData('label', $info['label']);
            }
            if (empty($data['class'])) {
                $event->setData('class', $info['class']);
            }
            if (empty($data['icon'])) {
                $event->setData('icon', $info['icon']);
            }
            if (empty($data['color'])) {
                $event->setData('color', $info['color']);
            }
            if (empty($data['description'])) {
                $event->setData('description', $info['description']);
            }
        }
    }
}
```

## 注意事项

- 建议只设置未设置的字段，避免覆盖其他观察者的结果
- 如果无法提供某个字段，应该保持为空，让其他观察者或默认逻辑处理
- 图标类名应该符合前端图标库的规范（如Font Awesome）
- 颜色值可以使用CSS颜色格式（如：`#ffc107`、`rgb(255, 193, 7)`等）

## 相关事件

- `Weline_Order::query::get_status_label` - 获取订单状态标签
- `Weline_Order::query::get_status_class` - 获取订单状态CSS类
