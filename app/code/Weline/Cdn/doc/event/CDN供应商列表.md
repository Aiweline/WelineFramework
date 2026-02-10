# Weline Cdn 模块 - CDN供应商列表事件

## 概述

本文档说明 `Weline_Cdn::provider::list` 事件的用途与数据结构。该事件用于在获取 CDN 供应商列表时，允许其他模块扩展或修改供应商数据。

## 事件名称

- **事件名称**：`Weline_Cdn::provider::list`
- **触发时机**：请求 CDN 供应商列表时
- **事件类型**：integration
- **配置文件**：`app/code/Weline/Cdn/event.php`

## 数据结构

事件数据通过引用传递，可读写：

```php
[
    'providers' => [
        [
            'code' => 'cloudflare',
            'name' => 'Cloudflare',
            'description' => '...',
            'version' => '1.0.0',
        ],
        // ...
    ],
]
```

## 使用方法

### 监听事件并补充供应商

在 `etc/event.xml` 中注册观察者：

```xml
<event name="Weline_Cdn::provider::list">
    <observer name="Your_Module::cdn_provider_list"
              instance="Your\Module\Observer\CdnProviderListObserver"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

观察者示例：

```php
public function execute(Event &$event): void
{
    $data = $event->getData();
    $providers = $data['providers'] ?? [];
    $providers[] = [
        'code' => 'your_provider',
        'name' => 'Your Provider',
        'description' => '...',
        'version' => '1.0.0',
    ];
    $data['providers'] = $providers;
    $event->setData($data);
}
```

## 注意事项

- 事件数据为引用参数，必须通过变量传递
- 建议避免重复 code

## 更新日志

- 2026-02-09：新增 `Weline_Cdn::provider::list` 事件文档
