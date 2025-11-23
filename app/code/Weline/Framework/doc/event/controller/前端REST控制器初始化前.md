# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_FrontendRestController::init_before` 事件及其使用方法。该事件在前端REST控制器初始化前触发，允许其他模块在初始化前执行操作。

## 事件列表

### 1. Weline_Framework_FrontendRestController::init_before - 前端REST控制器初始化前事件

#### 基本信息

- **事件名称**：`Weline_Framework_FrontendRestController::init_before`
- **事件类型**：控制器生命周期事件
- **触发时机**：在前端REST控制器初始化前
- **触发位置**：继承自 `AbstractRestController` 的构造函数
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_FrontendRestController::init_before` 事件在前端REST控制器初始化前触发，此时：
- 控制器对象已创建
- 父类构造函数尚未调用
- 可以执行初始化前的操作

该事件主要用于：
- 初始化前检查
- 设置控制器属性
- 执行初始化前逻辑
- 权限预检查
- 双因素认证检查

#### 触发时机

该事件在 `FrontendRestController` 继承的 `AbstractRestController` 构造函数中触发，在父类初始化之前。

#### 使用场景

- **初始化前检查**：检查控制器初始化前置条件
- **设置属性**：设置控制器属性
- **权限预检查**：执行权限预检查
- **双因素认证**：在初始化前进行双因素认证检查
- **日志记录**：记录控制器初始化日志

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_FrontendRestController::init_before">
        <observer name="Your_Module::frontend_rest_controller_init_before"
                  instance="Your\Module\Observer\FrontendRestControllerInitBeforeObserver"
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

class FrontendRestControllerInitBeforeObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $controller = $event->getData();
        
        // 执行初始化前操作
        // 例如：权限检查、双因素认证等
    }
}
```

#### 事件数据

`Weline_Framework_FrontendRestController::init_before` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| controller | FrontendRestController | 前端REST控制器实例（引用传递，可以修改） |

**获取数据**：

```php
$controller = $event->getData();
```

#### 注意事项

1. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
2. **控制器对象**：控制器对象是引用传递，可以直接修改
3. **性能考虑**：该事件在每次创建前端REST控制器时都会触发，应避免执行耗时操作
4. **异常处理**：如果观察者抛出异常，可能会阻止控制器初始化

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Controller/AbstractRestController.php`
- **示例观察者**：`app/code/Weline/TwoFactorAuth/etc/event.xml`

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_FrontendRestController::init_before` 事件文档

