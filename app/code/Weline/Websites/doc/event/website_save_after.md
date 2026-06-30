# 网站保存后事件

## 事件名称

`Weline_Websites::website_save_after`

## 触发时机

网站保存后触发，可用于更新缓存、通知等相关操作。

## 事件目的

允许其他模块在网站保存后执行相关操作，例如：
- 清理相关缓存
- 更新关联数据
- 发送通知

## 事件数据结构

事件以数组形式传参：

```php
[
    'website_id' => 1,  // 网站ID（必填，整数）
    'website' => $website,  // 网站对象（可选）
    // 其他相关数据...
]
```

## 观察者实现规范

### 1. 观察者类

- 必须实现 `Weline\Framework\Event\ObserverInterface`。
- 从事件中读取网站数据并执行相应操作：

```php
public function execute(\Weline\Framework\Event\Event $event): void
{
    $data = $event->getData();
    $websiteId = $data['website_id'] ?? 0;
    
    if (!$websiteId) {
        return;
    }
    
    // 执行相关操作，例如清理缓存
    // ...
}
```

### 2. 事件配置

在模块 `etc/event.xml` 中注册观察者，例如：

```xml
<event name="Weline_Websites::website_save_after">
    <observer name="WeShop_Store::website_save_after"
              instance="WeShop\Store\Observer\WebsiteSaveAfter"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

## 注意事项

- 不建议在观察者中执行耗时操作，建议使用队列异步处理
- 确保观察者逻辑不会影响主流程性能

## Extension payload convention

Website edit form extension modules should write fields under `extensions[{module_code}]`.
`Weline_Websites` passes the raw post data through `post_data` and does not parse SEO/GEO fields itself.

Example:

```php
[
    'website_id' => 1,
    'website' => $websiteData,
    'post_data' => [
        'extensions' => [
            'seo' => ['robots_enabled' => '1'],
            'geo' => ['llms_enabled' => '1'],
        ],
    ],
    'address_list' => [],
    'action' => 'add|edit|quick_save',
]
```
