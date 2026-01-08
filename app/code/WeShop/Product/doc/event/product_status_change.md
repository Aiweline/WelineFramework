# 产品状态变更事件 (product_status_change)

## 事件名称
`WeShop_Product::product_status_change`

## 触发时机
产品状态变更时触发（启用/禁用）。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product_id | int | 产品ID |
| old_status | int | 旧状态 |
| new_status | int | 新状态 |

## 使用场景

- 更新产品索引
- 清除产品缓存
- 发送状态变更通知
- 同步产品状态到其他系统

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductStatusChangeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $productId = $data['product_id'] ?? 0;
        $oldStatus = $data['old_status'] ?? 0;
        $newStatus = $data['new_status'] ?? 0;
        
        if ($productId) {
            // 更新产品索引
            $this->updateProductIndex($productId, $newStatus);
            
            // 清除产品缓存
            $this->clearProductCache($productId);
        }
    }
    
    private function updateProductIndex(int $productId, int $status): void
    {
        // 更新索引逻辑
    }
    
    private function clearProductCache(int $productId): void
    {
        // 清除缓存逻辑
    }
}
```

## 注册观察者

在模块的 `etc/event.xml` 中注册：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="WeShop_Product::product_status_change">
        <observer name="Your_Module::product_status_change"
                  instance="Your\Module\Observer\ProductStatusChangeObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
