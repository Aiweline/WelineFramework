# 产品删除后事件 (product_delete_after)

## 事件名称
`WeShop_Product::product_delete_after`

## 触发时机
产品删除后触发，可用于清理相关数据。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product_id | int | 已删除的产品ID |

## 使用场景

- 清理产品相关数据
- 删除产品库存记录
- 清除产品缓存
- 更新产品索引
- 记录删除日志

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductDeleteAfterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $productId = $data['product_id'] ?? 0;
        
        if ($productId) {
            // 清理产品相关数据
            $this->cleanupRelatedData($productId);
            
            // 清除产品缓存
            $this->clearProductCache($productId);
        }
    }
    
    private function cleanupRelatedData(int $productId): void
    {
        // 清理相关数据逻辑
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
    <event name="WeShop_Product::product_delete_after">
        <observer name="Your_Module::product_delete_after"
                  instance="Your\Module\Observer\ProductDeleteAfterObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
