# Weline_Order::order_status_can_transition - 订单状态转换规则检查

## 事件说明

在检查订单状态是否可以转换时触发，允许其他模块扩展状态转换规则。观察者可以修改 `can_transition` 字段来允许或阻止状态转换。

## 触发时机

在 `OrderStateMachine::canTransition()` 方法中，基本转换规则检查后。

## 数据格式

```php
[
    'from_status' => string,
    'to_status' => string,
    'can_transition' => bool,  // 观察者可以修改此值
    'transitions' => array,    // 基本转换规则
]
```

## 可用数据

- `from_status` (string) - 当前状态
- `to_status` (string) - 目标状态
- `can_transition` (bool) - 是否允许转换（可修改）
- `transitions` (array) - 基本转换规则映射

## 使用场景

- 扩展状态转换规则（允许额外的状态转换）
- 根据业务条件动态允许或阻止状态转换
- 实现自定义状态转换逻辑

## 使用示例

### 示例1：允许额外的状态转换

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusCanTransitionObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $fromStatus = $data['from_status'];
        $toStatus = $data['to_status'];
        $canTransition = $data['can_transition'];
        
        // 如果基本规则不允许，检查自定义规则
        if (!$canTransition) {
            // 允许从 processing 直接转换到 completed（跳过 paid 和 fulfilled）
            if ($fromStatus === 'processing' && $toStatus === 'completed') {
                $data['can_transition'] = true;
                $event->setData($data);
            }
        }
    }
}
```

### 示例2：根据订单金额限制状态转换

```php
class OrderStatusCanTransitionObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $fromStatus = $data['from_status'];
        $toStatus = $data['to_status'];
        
        // 获取订单信息（需要从事件中获取或查询）
        // 这里假设可以通过某种方式获取订单
        $orderId = $data['order_id'] ?? null;
        if ($orderId) {
            // 查询订单
            // 如果订单金额超过阈值，需要特殊审批才能取消
            if ($fromStatus !== 'cancelled' && $toStatus === 'cancelled') {
                // 检查是否需要审批
                // 如果需要审批且未审批，阻止转换
            }
        }
    }
}
```

## 注意事项

- 此事件用于扩展转换规则，不是验证业务逻辑
- 业务逻辑验证应该在 `order_status_change_before` 事件中处理
- 修改 `can_transition` 会影响状态转换检查结果
- 建议只扩展规则，不要完全覆盖基本规则

## 相关事件

- `Weline_Order::order_status_change_before` - 订单状态变更前
- `Weline_Order::order_status_changed` - 订单状态变更后
