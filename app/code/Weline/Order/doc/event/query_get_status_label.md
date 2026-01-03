# Weline_Order::query::get_status_label - 获取订单状态标签

## 事件说明

在获取订单状态标签时触发，允许其他模块扩展状态标签的翻译。观察者可以设置 `label` 字段来提供自定义的状态标签。

## 触发时机

在 `Order::getStatusLabel()` 静态方法中，通过事件机制获取状态标签时。

## 数据格式

```php
[
    'status' => string,
    'label' => string,  // 观察者可以设置此值
]
```

## 可用数据

- `status` (string) - 状态代码（如：pending, processing, paid等）
- `label` (string) - 状态标签（可修改，观察者设置）

## 使用场景

- 提供自定义状态标签翻译
- 根据语言环境返回不同的标签
- 扩展新的状态标签
- 实现动态状态标签

## 使用示例

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class GetStatusLabelObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $status = $data['status'] ?? '';
        
        if (empty($status)) {
            return;
        }
        
        // 如果已经有标签，不覆盖
        if (!empty($data['label'])) {
            return;
        }
        
        // 提供自定义状态标签
        $labels = [
            'pending' => __('待处理'),
            'processing' => __('处理中'),
            'paid' => __('已支付'),
            'fulfilled' => __('已发货'),
            'completed' => __('已完成'),
            'cancelled' => __('已取消'),
            'refunded' => __('已退款'),
        ];
        
        if (isset($labels[$status])) {
            $event->setData('label', $labels[$status]);
        }
    }
}
```

## 注意事项

- 如果观察者设置了 `label`，将使用该标签，不再使用默认翻译
- 如果多个观察者都设置了 `label`，后执行的会覆盖前面的
- 建议检查 `label` 是否已设置，避免覆盖其他观察者的结果
- 如果无法提供标签，应该保持 `label` 为空，让其他观察者或默认逻辑处理

## 相关事件

- `Weline_Order::query::get_status_class` - 获取订单状态CSS类
- `Weline_Order::domain::resolve_status_info` - 解析完整状态信息
