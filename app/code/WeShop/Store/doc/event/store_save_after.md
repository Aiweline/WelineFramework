# 店铺保存后事件 (store_save_after)

## 事件名称
`WeShop_Store::store_save_after`

## 触发时机
店铺数据保存（新增或更新）成功后触发。

## 事件参数

| 参数名 | 类型 | 说明 |
|--------|------|------|
| store | WeShop\Store\Model\Store | 店铺模型实例 |
| is_new | bool | 是否为新建店铺 |

## 使用场景

- 更新店铺相关缓存
- 发送通知消息
- 同步数据到其他系统
- 生成 SEO 相关数据

## 观察者示例

```php
<?php
namespace YourModule\Observer;

use Weline\Framework\Event\ObserverInterface;

class StoreSaveAfterObserver implements ObserverInterface
{
    public function execute(array $data): void
    {
        $store = $data['store'] ?? null;
        $isNew = $data['is_new'] ?? false;
        
        if ($store) {
            // 处理店铺保存后的逻辑
            if ($isNew) {
                // 新店铺逻辑
            } else {
                // 更新店铺逻辑
            }
        }
    }
}
```

## 注册观察者

在模块的 `etc/event.xml` 中注册：

```xml
<?xml version="1.0"?>
<events>
    <event name="WeShop_Store::store_save_after">
        <observer name="your_module_store_save_after" 
                  instance="YourModule\Observer\StoreSaveAfterObserver"/>
    </event>
</events>
```

