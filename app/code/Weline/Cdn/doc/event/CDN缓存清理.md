# Weline Cdn 模块 - 事件文档

## 概述

本文档详细说明了 Weline Cdn 模块提供的 `Weline_Cdn::clear` 事件及其使用方法。该事件在清理CDN缓存时触发。

## 事件列表

### 1. Weline_Cdn::clear - CDN缓存清理事件

#### 基本信息

- **事件名称**：`Weline_Cdn::clear`
- **事件类型**：CDN操作事件
- **触发时机**：在清理CDN缓存时
- **配置文件**：`app/code/Weline/Cdn/etc/event.xml`

#### 功能说明

`Weline_Cdn::clear` 事件在清理CDN缓存时触发，允许其他模块监听并处理缓存清理操作。事件数据包含域名、清理模式等信息。

该事件主要用于：
- 执行缓存清理
- 记录清理日志
- 清理后处理
- 清理结果通知

#### 使用场景

- **缓存清理**：执行CDN缓存清理操作
- **日志记录**：记录缓存清理的详细信息
- **清理后处理**：在清理完成后执行后续操作
- **结果通知**：通知管理员清理结果

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Cdn::clear">
        <observer name="Your_Module::clear"
                  instance="Your\Module\Observer\ClearObserver"
                  disabled="false"
                  shared="true"
                  sort="100"/>
    </event>
</config>
```

#### 事件数据

`Weline_Cdn::clear` 事件传递的数据：

```php
[
    'domain' => string,      // 域名
    'mode' => string,        // 清理模式：everything, urls, hosts, tags, cache_keys
    'data' => array,         // 附加数据
]
```

#### 相关文件

- **事件配置**：`app/code/Weline/Cdn/etc/event.xml`
- **事件定义**：`app/code/Weline/Cdn/event.php`

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Cdn::clear` 事件文档

