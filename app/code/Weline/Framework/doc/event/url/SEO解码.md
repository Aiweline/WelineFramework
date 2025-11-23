# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Url::seo_decode` 事件及其使用方法。该事件在URL SEO解码时触发，允许其他模块自定义SEO解码逻辑。

## 事件列表

### 1. Weline_Framework_Url::seo_decode - SEO解码事件

#### 基本信息

- **事件名称**：`Weline_Framework_Url::seo_decode`
- **事件类型**：URL解析事件
- **触发时机**：在URL SEO解码时
- **触发位置**：`app/code/Weline/Framework/Http/Url.php` 第 798 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Url::seo_decode` 事件在URL SEO解码时触发，允许其他模块自定义SEO解码逻辑。可以将SEO友好的URL解码为系统可识别的URL格式。

该事件主要用于：
- 自定义SEO解码逻辑
- 将SEO URL转换为系统URL
- URL参数解析
- 多语言URL解码

#### 触发时机

```php
// app/code/Weline/Framework/Http/Url.php
$event->dispatch('Weline_Framework_Url::seo_decode', $url);
```

#### 使用场景

- **SEO URL解码**：将SEO友好的URL解码为系统URL
- **URL参数解析**：从SEO URL中解析参数
- **多语言URL**：解码多语言URL
- **自定义URL格式**：支持自定义URL格式

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Url::seo_decode">
        <observer name="Your_Module::seo_decode_url"
                  instance="Your\Module\Observer\SeoDecodeUrlObserver"
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

class SeoDecodeUrlObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $url = $event->getData();
        
        // SEO解码逻辑
        $this->decodeSeoUrl($url);
    }
    
    private function decodeSeoUrl($url): void
    {
        // SEO解码逻辑
    }
}
```

#### 事件数据

`Weline_Framework_Url::seo_decode` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| url | Url | URL对象，可以修改此对象来改变解码后的URL |

**获取和修改数据**：

```php
// 获取URL对象
$url = $event->getData();

// 修改URL对象
$url->setPath('/new/path');
$url->setParam('key', 'value');
```

#### 使用示例

##### 示例 1：SEO URL解码

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SeoDecodeUrlObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $url = $event->getData();
        $path = $url->getPath();
        
        // 将 /product/123.html 转换为 /product/view?id=123
        if (preg_match('#^/product/(\d+)\.html$#', $path, $matches)) {
            $id = $matches[1];
            $url->setPath('/product/view');
            $url->setParam('id', $id);
        }
    }
}
```

##### 示例 2：多语言URL解码

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class MultilanguageUrlDecodeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $url = $event->getData();
        $path = $url->getPath();
        
        // 移除语言前缀 /zh_Hans_CN/page -> /page
        // 注意：框架默认使用 zh_Hans_CN 作为中文语言代码
        if (preg_match('#^/([a-z]{2}_[A-Z]{2})/(.+)$#', $path, $matches)) {
            $lang = $matches[1];
            $actualPath = '/' . $matches[2];
            $url->setPath($actualPath);
            $url->setParam('lang', $lang);
        }
    }
}
```

#### 注意事项

1. **URL对象修改**：直接修改URL对象即可，不需要返回
2. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
3. **性能考虑**：该事件在每次URL解析时都可能触发，应避免执行耗时操作
4. **URL格式**：确保解码后的URL格式正确

#### 系统行为说明

1. **默认行为**：
   - 默认使用系统SEO解码规则
   - 观察者可以修改URL对象来改变解码后的URL

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 后执行的观察者可以覆盖先执行的观察者的修改

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Http/Url.php`（第 798 行）
- **框架开发文档**：`docs/dev/开发文档.md`

## 扩展开发

如果需要扩展SEO解码功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Url::seo_decode` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

