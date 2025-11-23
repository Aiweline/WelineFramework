# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Router::backend_no_login_redirect_url` 事件及其使用方法。该事件在后端控制器初始化时触发，允许其他模块添加未登录时的重定向URL。

## 事件列表

### 1. Weline_Framework_Router::backend_no_login_redirect_url - 后端未登录重定向URL事件

#### 基本信息

- **事件名称**：`Weline_Framework_Router::backend_no_login_redirect_url`
- **事件类型**：路由配置事件
- **触发时机**：在后端控制器初始化时
- **触发位置**：`app/code/Weline/Framework/App/Controller/BackendController.php` 第 57 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Router::backend_no_login_redirect_url` 事件在后端控制器初始化时触发，用于配置未登录用户访问后端时的重定向URL。

该事件主要用于：
- 配置未登录重定向URL
- 自定义登录跳转逻辑
- 处理未登录访问

#### 触发时机

```php
// app/code/Weline/Framework/App/Controller/BackendController.php
$noLoginRedirectUrl = new DataObject(['no_login_redirect_url' => []]);
$evenManager->dispatch('Weline_Framework_Router::backend_no_login_redirect_url', $noLoginRedirectUrl);
$no_login_redirect_url = $noLoginRedirectUrl->getData('no_login_redirect_url');
```

#### 使用场景

- **登录页面**：配置登录页面URL
- **自定义登录**：配置自定义登录页面
- **多语言登录**：根据语言配置不同的登录页面

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Router::backend_no_login_redirect_url">
        <observer name="Your_Module::set_redirect_url"
                  instance="Your\Module\Observer\SetRedirectUrlObserver"
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

class SetRedirectUrlObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        // 设置重定向URL
        $event->setData('no_login_redirect_url', '/admin/login');
    }
}
```

#### 事件数据

`Weline_Framework_Router::backend_no_login_redirect_url` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| no_login_redirect_url | string | 未登录时的重定向URL |

**获取和修改数据**：

```php
// 获取重定向URL
$redirectUrl = $event->getData('no_login_redirect_url');

// 设置重定向URL
$event->setData('no_login_redirect_url', '/admin/login');
```

#### 使用示例

##### 示例 1：设置登录页面URL

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SetLoginRedirectUrlObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 设置登录页面URL
        $event->setData('no_login_redirect_url', '/admin/login');
    }
}
```

##### 示例 2：根据语言设置不同的登录页面

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;

class SetLocalizedLoginRedirectUrlObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 框架默认使用 zh_Hans_CN 作为中文语言代码
        $lang = Cookie::getLangLocal() ?: 'zh_Hans_CN';
        
        // 根据语言设置不同的登录页面
        $loginUrls = [
            'zh_Hans_CN' => '/admin/login',
            'en_US' => '/admin/login/en',
        ];
        
        $loginUrl = $loginUrls[$lang] ?? '/admin/login';
        $event->setData('no_login_redirect_url', $loginUrl);
    }
}
```

#### 注意事项

1. **URL格式**：URL应该是完整的路径，例如 `/admin/login`
2. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
3. **覆盖行为**：后执行的观察者可以覆盖先执行的观察者的设置
4. **缓存**：重定向URL会被缓存，修改后需要清除缓存

#### 系统行为说明

1. **默认行为**：
   - 默认重定向URL为空
   - 如果未设置，系统会使用默认的登录页面

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 后执行的观察者可以覆盖先执行的观察者的设置

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/App/Controller/BackendController.php`（第 57 行）
- **框架开发文档**：`docs/dev/开发文档.md`

## 扩展开发

如果需要扩展后端未登录重定向URL功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Router::backend_no_login_redirect_url` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

