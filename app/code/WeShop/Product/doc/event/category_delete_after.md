# 分类删除后事件 (category_delete_after)

## 事件名称
`WeShop_Product::category_delete_after`

## 触发时机
分类删除后触发，可用于清理相关数据。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| category_id | int | 已删除的分类ID |

## 使用场景

- 清理分类相关数据
- 清除分类缓存
- 更新分类索引
- 记录删除日志

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CategoryDeleteAfterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $categoryId = $data['category_id'] ?? 0;
        
        if ($categoryId) {
            // 清理分类相关数据
            $this->cleanupRelatedData($categoryId);
            
            // 清除分类缓存
            $this->clearCategoryCache($categoryId);
        }
    }
    
    private function cleanupRelatedData(int $categoryId): void
    {
        // 清理相关数据逻辑
    }
    
    private function clearCategoryCache(int $categoryId): void
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
    <event name="WeShop_Product::category_delete_after">
        <observer name="Your_Module::category_delete_after"
                  instance="Your\Module\Observer\CategoryDeleteAfterObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
