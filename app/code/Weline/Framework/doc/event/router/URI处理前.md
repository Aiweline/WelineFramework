# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Router::process_uri_before` 事件及其使用方法。该事件在处理URI之前触发，允许其他模块修改URI或执行预处理。

## 事件列表

### 1. Weline_Framework_Router::process_uri_before - URI处理前事件

#### 基本信息

- **事件名称**：`Weline_Framework_Router::process_uri_before`
- **事件类型**：路由处理事件
- **触发时机**：在处理URI之前
- **触发位置**：`app/code/Weline/Framework/Router/Core.php` 第 195 行和第 301 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Router::process_uri_before` 事件在处理URI之前触发，此时：
- URI已获取但尚未处理
- 可以修改URI内容
- 可以执行URI预处理
- 可以添加URI处理逻辑

该事件主要用于：
- URI重写
- URI规范化
- URI验证
- URI预处理
- 添加URI处理逻辑

#### 触发时机

```php
// app/code/Weline/Framework/Router/Core.php
$routerData = new DataObject([
    'uri' => $uri,
    'request' => $this->request,
]);
$eventManager->dispatch('Weline_Framework_Router::process_uri_before', $routerData);
$uri = $routerData->getData('uri');
```

#### 使用场景

- **URI重写**：重写URI格式
- **URI规范化**：规范化URI格式
- **URI验证**：验证URI有效性
- **URI预处理**：执行URI预处理
- **添加处理逻辑**：添加自定义URI处理逻辑

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Router::process_uri_before">
        <observer name="Your_Module::rewrite_uri"
                  instance="Your\Module\Observer\RewriteUriObserver"
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

class RewriteUriObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $uri = $event->getData('uri');
        
        // 修改URI
        $modifiedUri = $this->rewriteUri($uri);
        
        $event->setData('uri', $modifiedUri);
    }
    
    private function rewriteUri(string $uri): string
    {
        // URI重写逻辑
        return $uri;
    }
}
```

#### 事件数据

`Weline_Framework_Router::process_uri_before` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| uri | string | URI字符串，可以修改此值来改变URI |
| request | Request | 请求对象 |

**获取和修改数据**：

```php
// 获取URI
$uri = $event->getData('uri');

// 修改URI
$event->setData('uri', $modifiedUri);

// 获取请求对象
$request = $event->getData('request');
```

#### 使用示例

##### 示例 1：URI重写

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class RewriteUriObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $uri = $event->getData('uri');
        
        // 重写旧URL到新URL
        $rewriteMap = [
            '/old-page' => '/new-page',
            '/old-product' => '/new-product',
        ];
        
        if (isset($rewriteMap[$uri])) {
            $event->setData('uri', $rewriteMap[$uri]);
        }
    }
}
```

##### 示例 2：URI规范化

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class NormalizeUriObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $uri = $event->getData('uri');
        
        // 移除尾部斜杠（除了根路径）
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }
        
        // 转换为小写
        $uri = strtolower($uri);
        
        $event->setData('uri', $uri);
    }
}
```

#### 注意事项

1. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
2. **URI修改**：必须通过 `$event->setData('uri', $modifiedUri)` 来更新URI
3. **性能考虑**：该事件在每次请求时都会触发，应避免执行耗时操作
4. **URI格式**：确保修改后的URI格式正确

#### 系统行为说明

1. **默认观察者**：暂无默认观察者

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 后执行的观察者可以覆盖先执行的观察者的修改

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Router/Core.php`（第 195 行和第 301 行）
- **框架开发文档**：`docs/dev/开发文档.md`

## 扩展开发

如果需要扩展URI处理功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Router::process_uri_before` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

