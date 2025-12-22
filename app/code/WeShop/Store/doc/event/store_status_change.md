# 店铺状态变更事件 (store_status_change)

## 事件名称
`WeShop_Store::store_status_change`

## 触发时机
店铺状态发生变更时触发（启用/禁用）。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| store | WeShop\Store\Model\Store | 店铺模型实例 |
| old_status | int | 变更前状态 |
| new_status | int | 变更后状态 |

## 状态值

| 值 | 说明 |
|----|------|
| 0 | 禁用 |
| 1 | 启用 |

## 使用场景

- 状态变更通知
- 更新相关业务状态
- 记录状态变更日志

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\ObserverInterface;

class StoreStatusChangeObserver implements ObserverInterface
{
    public function execute(array $data): void
    {
        $store = $data['store'] ?? null;
        $oldStatus = $data['old_status'] ?? null;
        $newStatus = $data['new_status'] ?? null;
        
        if ($store && $oldStatus !== $newStatus) {
            if ($newStatus == 1) {
                // 店铺启用逻辑
            } else {
                // 店铺禁用逻辑
            }
        }
    }
}
```

