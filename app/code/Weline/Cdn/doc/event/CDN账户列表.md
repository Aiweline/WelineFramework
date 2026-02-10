# Weline Cdn 模块 - CDN账户列表事件

## 概述

本文档说明 `Weline_Cdn::account::list` 事件的用途与数据结构。该事件用于在获取 CDN 账户列表时，允许其他模块扩展或修改账户数据。

## 事件名称

- **事件名称**：`Weline_Cdn::account::list`
- **触发时机**：请求 CDN 账户列表时
- **事件类型**：integration
- **配置文件**：`app/code/Weline/Cdn/event.php`

## 数据结构

事件数据通过引用传递，可读写：

```php
[
    'adapter' => 'cloudflare', // 可为空
    'accounts' => [
        [
            'account_id' => 1,
            'adapter' => 'cloudflare',
            'name' => '主账户',
            'status' => 'active',
            'is_default' => 1,
        ],
        // ...
    ],
]
```

## 使用方法

### 监听事件并补充账户

在 `etc/event.xml` 中注册观察者：

```xml
<event name="Weline_Cdn::account::list">
    <observer name="Your_Module::cdn_account_list"
              instance="Your\Module\Observer\CdnAccountListObserver"
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
    $accounts = $data['accounts'] ?? [];
    $accounts[] = [
        'account_id' => 99,
        'adapter' => 'your_provider',
        'name' => '扩展账户',
        'status' => 'active',
        'is_default' => 0,
    ];
    $data['accounts'] = $accounts;
    $event->setData($data);
}
```

## 注意事项

- 事件数据为引用参数，必须通过变量传递
- 建议保持账户与适配器的一对多关系

## 更新日志

- 2026-02-09：新增 `Weline_Cdn::account::list` 事件文档
