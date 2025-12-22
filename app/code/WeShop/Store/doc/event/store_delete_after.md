# 店铺删除后事件 (store_delete_after)

## 事件名称
`WeShop_Store::store_delete_after`

## 触发时机
店铺数据删除成功后触发。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| store_id | int | 被删除的店铺ID |
| store_data | array | 删除前的店铺数据 |

## 使用场景

- 清理店铺相关缓存
- 删除关联数据
- 记录删除日志
- 通知相关系统

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\ObserverInterface;

class StoreDeleteAfterObserver implements ObserverInterface
{
    public function execute(array $data): void
    {
        $storeId = $data['store_id'] ?? null;
        $storeData = $data['store_data'] ?? [];
        
        if ($storeId) {
            // 清理关联数据
        }
    }
}
```

