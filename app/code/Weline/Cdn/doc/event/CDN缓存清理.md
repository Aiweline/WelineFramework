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

`Weline_Cdn::clear` 是手工或兼容清理命令。`Clear` Observer 调用 `CachePurger`
并把结果写回事件的 `result`；参数验证或服务商调用失败会以
`result.success=false` 返回。它不是 Website 资源变更的生产者事件。

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
    'domain' => int|string,  // 数字 domain_id（多站点推荐）或域名
    'mode' => string,        // 清理模式：everything, urls, hosts, tags, cache_keys
    'data' => array,         // 附加数据
]
```

### 站点边界

`Weline_Cdn::clear` 本身没有 `site_id`；传域名字符串时是历史的非站点化查找。
在多站点路径中，调用方必须先在精确站点内解析 `domain_id` 再投递，不得依赖
“首个同名域名”。需要传站点时使用 `Weline_Cdn::request`：

- 显式 `site_id`/`website_id=0` 是合法的系统默认站点。
- 只有字段缺失才尝试当前运行时站点；显式 `null` 表示无站点上下文。
- 显式域名未在该站点命中时直接失败，不退回该站其他域名，更不跨站。

### 与 ResourceChange 的关系

Website 增删改的自动 CDN 处理订阅 `Weline_Framework::resource_changed`，不调用本事件。
CDN Observer 是 `delivery="async"` + `coalesce="latest"`；它在已提交的
`ResourceChange.website_id` 内精确查询域名，将新旧 URL 影响合并后定向清理。
`website_id=0` 同样是合法目标，无匹配域名时不会退回其他站点。

#### 相关文件

- **事件配置**：`app/code/Weline/Cdn/etc/event.xml`
- **事件定义**：`app/code/Weline/Cdn/event.php`

## 更新日志

- **2026-07-22**：明确手工清理与 ResourceChange 自动清理的边界、精确站点和 ID 0 语义
- **2024-12-19**：初始版本，添加 `Weline_Cdn::clear` 事件文档
