# 产品价格变更事件 (product_price_change)

## 事件名称
`WeShop_Product::product_price_change`

## 触发时机
产品价格变更时触发。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product_id | int | 产品ID |
| old_price | float | 旧价格 |
| new_price | float | 新价格 |

## 使用场景

- 更新产品索引
- 清除产品缓存
- 发送价格变更通知
- 记录价格变更历史
- 同步价格信息到其他系统

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductPriceChangeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $productId = $data['product_id'] ?? 0;
        $oldPrice = $data['old_price'] ?? 0;
        $newPrice = $data['new_price'] ?? 0;
        
        if ($productId) {
            // 记录价格变更历史
            $this->logPriceChange($productId, $oldPrice, $newPrice);
            
            // 更新产品索引
            $this->updateProductIndex($productId, $newPrice);
            
            // 清除产品缓存
            $this->clearProductCache($productId);
        }
    }
    
    private function logPriceChange(int $productId, float $oldPrice, float $newPrice): void
    {
        // 记录价格变更历史逻辑
    }
    
    private function updateProductIndex(int $productId, float $newPrice): void
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
    <event name="WeShop_Product::product_price_change">
        <observer name="Your_Module::product_price_change"
                  instance="Your\Module\Observer\ProductPriceChangeObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
