# Weline Cdn 模块 - 事件文档

## 概述

本文档详细说明了 Weline Cdn 模块提供的 `Weline_Cdn::send_warmup` 事件及其使用方法。该事件在提交CDN预热URL时触发。

## 事件列表

### 1. Weline_Cdn::send_warmup - CDN预热URL投递事件

#### 基本信息

- **事件名称**：`Weline_Cdn::send_warmup`
- **事件类型**：CDN操作事件
- **触发时机**：在提交CDN预热URL时
- **触发位置**：`app/code/Weline/Cdn/Cron/Warmup.php`
- **配置文件**：`app/code/Weline/Cdn/etc/event.xml`

#### 功能说明

`Weline_Cdn::send_warmup` 事件在提交CDN预热URL时触发，允许其他模块监听并处理预热URL。事件数据包含模块名、提供者、URL列表等信息。

该事件主要用于：
- 收集预热URL
- 处理预热URL
- 记录预热日志
- 预热URL去重

#### 触发时机

```php
// app/code/Weline/Cdn/Cron/Warmup.php
$event = new Event('Weline_Cdn::send_warmup', [
    'module' => 'Weline_Cdn',
    'provider' => 'scanner',
    'site_id' => 0,
    'urls' => [
        'https://example.com/inherit-top-level-site',
        [
            'url' => 'https://example.com/exact-domain',
            'site_id' => 0,
            'domain_id' => 1,
        ],
    ],
    'dedupe' => true
]);
$this->eventsManager->dispatch('Weline_Cdn::send_warmup', $event);
```

#### 使用场景

- **URL收集**：从各个模块收集需要预热的URL
- **URL处理**：对收集到的URL进行处理和验证
- **日志记录**：记录预热URL的提交情况
- **去重处理**：对重复的URL进行去重

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Cdn::send_warmup">
        <observer name="Your_Module::send_warmup"
                  instance="Your\Module\Observer\SendWarmupObserver"
                  disabled="false"
                  shared="true"
                  sort="100"/>
    </event>
</config>
```

#### 事件数据

`Weline_Cdn::send_warmup` 事件传递的数据：

```php
[
    'module' => string,      // 模块名
    'provider' => string,    // 提供者名称
    'urls' => array,         // 字符串 URL 或 URL item 列表
    'dedupe' => bool,        // 是否去重
    'site_id' => int,        // 顶层默认站点（可选，0 合法）
]
```

URL item 的完整形式是：

```php
[
    'url' => 'https://example.com/page',
    'site_id' => 0,   // 可选；覆盖顶层默认站点
    'domain_id' => 1, // 可选；只在 item 内读取
]
```

#### 站点与域名解析规则

- `site_id=0` 是系统默认站点，必须保留。显式站点值必须是非负整数或全数字字符串。
- 顶层 `site_id` 缺失时才尝试当前运行时站点；显式 `null` 不会被改写为其他站点。
- 字符串 URL 继承顶层站点；数组 item 中的 `site_id` 覆盖它。任何有效 URL 缺少最终站点上下文时，返回失败。
- `domain_id` 只从 URL item 读取；顶层同名字段不生效。显式 ID 必须是正整数、已启用且属于该站点。
- 未给 `domain_id` 时，只在该站点的启用域名中按 URL host 选择最精确匹配；不会跨站 fallback。
- 去重键包含 `url + module + site_id`，同一 URL 在不同站点不会互相覆盖。

#### 相关文件

- **事件配置**：`app/code/Weline/Cdn/etc/event.xml`
- **事件定义**：`app/code/Weline/Cdn/event.php`
- **触发位置**：`app/code/Weline/Cdn/Cron/Warmup.php`

## 更新日志

- **2026-07-22**：明确 URL item 字段层级、ID 0、缺失/null 和精确站点域名解析
- **2024-12-19**：初始版本，添加 `Weline_Cdn::send_warmup` 事件文档
