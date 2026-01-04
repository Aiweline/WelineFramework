# 产品保存后事件 (product_save_after)

## 事件名称
`WeShop_Product::product_save_after`

## 触发时机
产品保存后触发，可用于更新索引、缓存、库存等。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product | WeShop\Product\Model\Product | 产品模型实例 |
| product_id | int | 产品ID |

## 使用场景

- 更新产品索引
- 清除产品相关缓存
- 初始化库存记录
- 同步产品信息到其他系统
- 发送产品保存通知

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductSaveAfterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $product = $data['product'] ?? null;
        $productId = $data['product_id'] ?? 0;
        
        if ($product && $productId) {
            // 更新产品索引
            $this->updateProductIndex($productId);
            
            // 清除产品缓存
            $this->clearProductCache($productId);
        }
    }
    
    private function updateProductIndex(int $productId): void
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
    <event name="WeShop_Product::product_save_after">
        <observer name="Your_Module::product_save_after"
                  instance="Your\Module\Observer\ProductSaveAfterObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
