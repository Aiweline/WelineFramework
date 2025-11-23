# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_FrontendRestController::init_after` 事件及其使用方法。该事件在前端REST控制器初始化后触发，允许其他模块在初始化后执行操作。

## 事件列表

### 1. Weline_Framework_FrontendRestController::init_after - 前端REST控制器初始化后事件

#### 基本信息

- **事件名称**：`Weline_Framework_FrontendRestController::init_after`
- **事件类型**：控制器生命周期事件
- **触发时机**：在前端REST控制器初始化后
- **触发位置**：继承自 `AbstractRestController` 的构造函数
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_FrontendRestController::init_after` 事件在前端REST控制器初始化后触发，此时：
- 控制器已完全初始化
- 父类构造函数已调用
- `__init()` 方法已执行
- 可以执行初始化后的操作

该事件主要用于：
- 初始化后处理
- 设置控制器属性
- 执行初始化后逻辑
- 日志记录
- 统计记录

#### 触发时机

该事件在 `FrontendRestController` 继承的 `AbstractRestController` 构造函数中触发，在父类初始化之后。

#### 使用场景

- **初始化后处理**：执行控制器初始化后的处理逻辑
- **设置属性**：设置控制器属性
- **日志记录**：记录控制器初始化日志
- **统计记录**：记录API访问统计
- **缓存处理**：处理控制器缓存

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_FrontendRestController::init_after">
        <observer name="Your_Module::frontend_rest_controller_init_after"
                  instance="Your\Module\Observer\FrontendRestControllerInitAfterObserver"
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

class FrontendRestControllerInitAfterObserver implements ObserverInterface
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
        
        // 执行初始化后操作
        // 例如：日志记录、统计等
    }
}
```

#### 事件数据

`Weline_Framework_FrontendRestController::init_after` 事件传递的数据：

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
4. **异常处理**：如果观察者抛出异常，可能会影响控制器正常使用

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Controller/AbstractRestController.php`

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_FrontendRestController::init_after` 事件文档

