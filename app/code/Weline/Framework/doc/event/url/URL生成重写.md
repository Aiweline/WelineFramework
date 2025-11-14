# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Framework_Url::url_generate_rewrite` 事件及其使用方法。该事件在生成URL重写规则时触发，允许其他模块自定义URL重写规则。

## 事件列表

### 1. Framework_Url::url_generate_rewrite - URL生成重写事件

#### 基本信息

- **事件名称**：`Framework_Url::url_generate_rewrite`
- **事件类型**：URL生成事件
- **触发时机**：在生成URL重写规则时
- **触发位置**：`app/code/Weline/Framework/Http/Url.php` 第 364 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Framework_Url::url_generate_rewrite` 事件在生成URL重写规则时触发，允许其他模块自定义URL重写规则。可以修改URL对象来改变生成的URL。

该事件主要用于：
- 自定义URL重写规则
- 修改URL格式
- 添加URL参数
- SEO友好的URL生成

#### 触发时机

```php
// app/code/Weline/Framework/Http/Url.php
$eventManager->dispatch('Framework_Url::url_generate_rewrite', $url);
```

#### 使用场景

- **URL重写**：重写URL格式
- **SEO优化**：生成SEO友好的URL
- **URL参数**：添加或修改URL参数
- **多语言URL**：生成多语言URL

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Framework_Url::url_generate_rewrite">
        <observer name="Your_Module::rewrite_url"
                  instance="Your\Module\Observer\RewriteUrlObserver"
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

class RewriteUrlObserver implements ObserverInterface
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
        
        // 修改URL
        $this->rewriteUrl($url);
    }
    
    private function rewriteUrl($url): void
    {
        // URL重写逻辑
    }
}
```

#### 事件数据

`Framework_Url::url_generate_rewrite` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| url | Url | URL对象，可以修改此对象来改变生成的URL |

**获取和修改数据**：

```php
// 获取URL对象
$url = $event->getData();

// 修改URL对象
$url->setPath('/new/path');
$url->setParam('key', 'value');
```

#### 使用示例

##### 示例 1：SEO友好的URL

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SeoFriendlyUrlObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $url = $event->getData();
        $path = $url->getPath();
        
        // 将 /product/view?id=123 转换为 /product/123.html
        if (preg_match('#^/product/view#', $path)) {
            $id = $url->getParam('id');
            if ($id) {
                $url->setPath("/product/{$id}.html");
                $url->removeParam('id');
            }
        }
    }
}
```

##### 示例 2：添加语言前缀

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;

class AddLanguagePrefixObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $url = $event->getData();
        $path = $url->getPath();
        // 框架默认使用 zh_Hans_CN 作为中文语言代码
        $lang = Cookie::getLangLocal() ?: 'zh_Hans_CN';
        
        // 添加语言前缀
        if (!str_starts_with($path, "/{$lang}/")) {
            $url->setPath("/{$lang}{$path}");
        }
    }
}
```

#### 注意事项

1. **URL对象修改**：直接修改URL对象即可，不需要返回
2. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
3. **性能考虑**：该事件在每次生成URL时都会触发，应避免执行耗时操作
4. **URL格式**：确保修改后的URL格式正确

#### 系统行为说明

1. **默认行为**：
   - 默认使用系统URL生成规则
   - 观察者可以修改URL对象来改变生成的URL

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 后执行的观察者可以覆盖先执行的观察者的修改

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Http/Url.php`（第 364 行）
- **框架开发文档**：`docs/dev/开发文档.md`

## 扩展开发

如果需要扩展URL生成重写功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理

## 更新日志

- **2024-12-19**：初始版本，添加 `Framework_Url::url_generate_rewrite` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

