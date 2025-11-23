# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Acl::dispatch` 事件及其使用方法。该事件在ACL权限检查时触发，允许其他模块自定义权限检查逻辑。

## 事件列表

### 1. Weline_Framework_Acl::dispatch - ACL分发事件

#### 基本信息

- **事件名称**：`Weline_Framework_Acl::dispatch`
- **事件类型**：ACL权限事件
- **触发时机**：在ACL权限检查时
- **触发位置**：`app/code/Weline/Framework/Acl/Acl.php` 第 196 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Acl::dispatch` 事件在ACL权限检查时触发，允许其他模块自定义权限检查逻辑。可以修改ACL对象来改变权限检查结果。

该事件主要用于：
- 自定义权限检查逻辑
- 权限验证
- 权限扩展
- 权限日志记录

#### 触发时机

```php
// app/code/Weline/Framework/Acl/Acl.php
$eventsManager->dispatch('Weline_Framework_Acl::dispatch', $this);
return $this->getResult();
```

#### 使用场景

- **权限检查**：执行自定义权限检查逻辑
- **权限验证**：验证用户权限
- **权限扩展**：扩展权限功能
- **权限日志**：记录权限检查日志

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Acl::dispatch">
        <observer name="Your_Module::custom_acl_check"
                  instance="Your\Module\Observer\CustomAclCheckObserver"
                  disabled="false"
                  shared="true"
                  sort="100"/>
    </event>
</config>
```

创建观察者类：

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CustomAclCheckObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $acl = $event->getData();
        
        // 自定义权限检查逻辑
        // ...
    }
}
```

#### 事件数据

`Weline_Framework_Acl::dispatch` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| acl | Acl | ACL对象，可以修改此对象来改变权限检查结果 |

**获取数据**：

```php
$acl = $event->getData();
```

#### 注意事项

1. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
2. **ACL对象**：ACL对象是引用传递，可以直接修改

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Acl/Acl.php`（第 196 行）

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Acl::dispatch` 事件文档

