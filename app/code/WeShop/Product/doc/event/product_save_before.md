# 产品保存前事件 (product_save_before)

## 事件名称
`WeShop_Product::product_save_before`

## 触发时机
产品保存前触发，可用于验证产品数据。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product | WeShop\Product\Model\Product | 产品模型实例 |
| product_id | int | 产品ID（如果是更新） |

## 使用场景

- 验证产品数据完整性
- 验证产品业务规则
- 预处理产品数据
- 记录保存前日志

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductSaveBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $product = $data['product'] ?? null;
        
        if ($product) {
            // 验证产品数据
            $this->validateProductData($product);
            
            // 预处理产品数据
            $this->preprocessProductData($product);
        }
    }
    
    private function validateProductData($product): void
    {
        // 验证逻辑
        if (empty($product->getName())) {
            throw new \Exception('产品名称不能为空');
        }
    }
    
    private function preprocessProductData($product): void
    {
        // 预处理逻辑
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
    <event name="WeShop_Product::product_save_before">
        <observer name="Your_Module::product_save_before"
                  instance="Your\Module\Observer\ProductSaveBeforeObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
