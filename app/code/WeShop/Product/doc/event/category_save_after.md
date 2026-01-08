# 分类保存后事件 (category_save_after)

## 事件名称
`WeShop_Product::category_save_after`

## 触发时机
分类保存后触发，可用于更新分类缓存。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| category | WeShop\Product\Model\Category | 分类模型实例 |
| category_id | int | 分类ID |

## 使用场景

- 更新分类缓存
- 更新分类索引
- 同步分类信息到其他系统
- 发送分类保存通知

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CategorySaveAfterObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $category = $data['category'] ?? null;
        $categoryId = $data['category_id'] ?? 0;
        
        if ($category && $categoryId) {
            // 更新分类缓存
            $this->updateCategoryCache($categoryId);
            
            // 更新分类索引
            $this->updateCategoryIndex($categoryId);
        }
    }
    
    private function updateCategoryCache(int $categoryId): void
    {
        // 更新缓存逻辑
    }
    
    private function updateCategoryIndex(int $categoryId): void
    {
        // 更新索引逻辑
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
    <event name="WeShop_Product::category_save_after">
        <observer name="Your_Module::category_save_after"
                  instance="Your\Module\Observer\CategorySaveAfterObserver"
                  disabled="false"
                  shared="true"
                  sort="0"/>
    </event>
</config>
```
