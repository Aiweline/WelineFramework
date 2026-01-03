# Weline_Order::order_status_change_before - 订单状态变更前

## 事件说明

在订单状态变更执行前触发，允许其他模块验证或阻止状态变更。观察者可以通过设置 `can_change` 为 `false` 来阻止状态转换。

## 触发时机

在 `OrderStateMachine::transition()` 方法中，状态转换规则检查通过后，执行状态更新前。

## 数据格式

```php
[
    'order' => Order对象,
    'order_id' => int,
    'old_status' => string,
    'new_status' => string,
    'comment' => string|null,
    'notify_customer' => bool,
    'can_change' => bool,  // 观察者可以设置为false来阻止转换
]
```

## 可用数据

- `order` (Order) - 订单对象
- `order_id` (int) - 订单ID
- `old_status` (string) - 当前状态
- `new_status` (string) - 目标状态
- `comment` (string|null) - 状态变更备注
- `notify_customer` (bool) - 是否通知客户
- `can_change` (bool) - 是否允许变更（可修改）

## 使用场景

- 验证状态转换是否允许（业务规则检查）
- 阻止不符合业务规则的状态转换
- 在状态变更前执行预处理操作
- 记录状态变更前的状态

## 使用示例

### 示例1：阻止已发货订单取消

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusChangeBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $oldStatus = $data['old_status'];
        $newStatus = $data['new_status'];
        
        // 阻止已发货订单取消
        if ($oldStatus === 'fulfilled' && $newStatus === 'cancelled') {
            $data['can_change'] = false;
            $event->setData($data);
            throw new \Exception(__('已发货的订单不能取消'));
        }
    }
}
```

### 示例2：验证支付状态

```php
class OrderStatusChangeBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        /** @var Order $order */
        $order = $data['order'];
        $newStatus = $data['new_status'];
        
        // 如果要转换到已发货状态，必须已支付
        if ($newStatus === 'fulfilled') {
            $paymentStatus = $order->getPaymentStatus();
            if ($paymentStatus !== 'paid') {
                $data['can_change'] = false;
                $event->setData($data);
                throw new \Exception(__('未支付的订单不能发货'));
            }
        }
    }
}
```

## 注意事项

- 通过设置 `can_change` 为 `false` 可以阻止状态转换
- 如果阻止转换，建议抛出异常说明原因
- 状态转换规则已在 `canTransition()` 方法中检查
- 观察者应该只进行业务规则验证，不要执行耗时操作

## 相关事件

- `Weline_Order::order_status_changed` - 订单状态变更后
- `Weline_Order::order_status_can_transition` - 订单状态转换规则检查
