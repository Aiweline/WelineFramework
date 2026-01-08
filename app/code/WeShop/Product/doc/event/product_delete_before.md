# 产品删除前事件 (product_delete_before)

## 事件名称
`WeShop_Product::product_delete_before`

## 触发时机
产品删除前触发，可用于验证是否可删除。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| product | WeShop\Product\Model\Product | 产品模型实例 |
| product_id | int | 产品ID |

## 使用场景

- 验证产品是否可以删除
- 检查产品关联数据
- 记录删除前日志
- 执行删除前的清理工作

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProductDeleteBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $product = $data['product'] ?? null;
        $productId = $data['product_id'] ?? 0;
        
        if ($productId) {
            // 验证是否可以删除
            if (!$this->canDeleteProduct($productId)) {
                throw new \Exception('产品不能删除，存在关联数据');
            }
            
            // 检查关联数据
            $this->checkRelatedData($productId);
        }
    }
    
    private function canDeleteProduct(int $productId): bool
    {
        // 验证逻辑
        return true;
    }
    
    private function checkRelatedData(int $productId): void
    {
        // 检查关联数据逻辑
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
    <event name="WeShop_Product::product_delete_before">
        <observer name="Your_Module::product_delete_before"
                  instance="Your\Module\Observer\ProductDeleteBeforeObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
