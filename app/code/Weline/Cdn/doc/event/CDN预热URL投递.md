# Weline Cdn 模块 - 事件文档

## 概述

本文档详细说明了 Weline Cdn 模块提供的 `Weline_Cdn::send_warmup` 事件及其使用方法。该事件在提交CDN预热URL时触发。

## 事件列表

### 1. Weline_Cdn::send_warmup - CDN预热URL投递事件

#### 基本信息

- **事件名称**：`Weline_Cdn::send_warmup`
- **事件类型**：CDN操作事件
- **触发时机**：在提交CDN预热URL时
- **触发位置**：`app/code/Weline/Cdn/Cron/Warmup.php` 第 61 行
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
    'urls' => $urls,
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
    'urls' => array,         // URL列表
    'dedupe' => bool,        // 是否去重
    'site_id' => int,        // 网站ID（可选）
    'domain_id' => int,      // 域名ID（可选）
]
```

#### 相关文件

- **事件配置**：`app/code/Weline/Cdn/etc/event.xml`
- **事件定义**：`app/code/Weline/Cdn/event.php`
- **触发位置**：`app/code/Weline/Cdn/Cron/Warmup.php`（第 61 行）

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Cdn::send_warmup` 事件文档

