# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Router::backend_whitelist_url` 事件及其使用方法。该事件在后端控制器初始化时触发，允许其他模块添加后端白名单URL。

## 事件列表

### 1. Weline_Framework_Router::backend_whitelist_url - 后端白名单URL事件

#### 基本信息

- **事件名称**：`Weline_Framework_Router::backend_whitelist_url`
- **事件类型**：路由配置事件
- **触发时机**：在后端控制器初始化时
- **触发位置**：`app/code/Weline/Framework/App/Controller/BackendController.php` 第 46 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Router::backend_whitelist_url` 事件在后端控制器初始化时触发，用于收集后端白名单URL列表。白名单URL是指不需要登录即可访问的后端URL。

该事件主要用于：
- 添加后端白名单URL
- 配置不需要登录的后端页面
- 自定义后端访问控制

#### 触发时机

```php
// app/code/Weline/Framework/App/Controller/BackendController.php
$whitelistUrlData = new DataObject(['whitelist_url' => []]);
$evenManager->dispatch('Weline_Framework_Router::backend_whitelist_url', $whitelistUrlData);
$whitelist_url = $whitelistUrlData->getData('whitelist_url');
```

#### 使用场景

- **登录页面**：将登录页面添加到白名单
- **公开API**：将公开API添加到白名单
- **静态资源**：将静态资源添加到白名单
- **公开页面**：将不需要登录的公开页面添加到白名单

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Router::backend_whitelist_url">
        <observer name="Your_Module::add_whitelist_url"
                  instance="Your\Module\Observer\AddWhitelistUrlObserver"
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

class AddWhitelistUrlObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $whitelistUrl = $event->getData('whitelist_url') ?: [];
        
        // 添加白名单URL
        $whitelistUrl[] = '/admin/login';
        $whitelistUrl[] = '/admin/api/public';
        
        $event->setData('whitelist_url', $whitelistUrl);
    }
}
```

#### 事件数据

`Weline_Framework_Router::backend_whitelist_url` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| whitelist_url | array | 白名单URL数组，可以添加URL到此数组 |

**获取和修改数据**：

```php
// 获取白名单URL数组
$whitelistUrl = $event->getData('whitelist_url') ?: [];

// 添加URL到白名单
$whitelistUrl[] = '/admin/login';

// 更新白名单
$event->setData('whitelist_url', $whitelistUrl);
```

#### 使用示例

##### 示例 1：添加登录页面到白名单

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class AddLoginToWhitelistObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $whitelistUrl = $event->getData('whitelist_url') ?: [];
        
        // 添加登录相关页面到白名单
        $whitelistUrl[] = '/admin/login';
        $whitelistUrl[] = '/admin/login/index';
        $whitelistUrl[] = '/admin/login/check';
        
        $event->setData('whitelist_url', $whitelistUrl);
    }
}
```

##### 示例 2：添加公开API到白名单

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class AddPublicApiToWhitelistObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $whitelistUrl = $event->getData('whitelist_url') ?: [];
        
        // 添加公开API到白名单
        $whitelistUrl[] = '/admin/api/public';
        $whitelistUrl[] = '/admin/api/status';
        
        $event->setData('whitelist_url', $whitelistUrl);
    }
}
```

#### 注意事项

1. **URL格式**：URL应该是相对于后端根路径的路径，例如 `/admin/login`
2. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
3. **数组合并**：多个观察者可以添加不同的URL，最终会合并所有URL
4. **缓存**：白名单URL会被缓存，修改后需要清除缓存

#### 系统行为说明

1. **默认行为**：
   - 默认白名单为空数组
   - 所有观察者添加的URL会被合并

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 后执行的观察者可以添加更多URL

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/App/Controller/BackendController.php`（第 46 行）
- **框架开发文档**：`docs/dev/开发文档.md`

## 扩展开发

如果需要扩展后端白名单URL功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Router::backend_whitelist_url` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

