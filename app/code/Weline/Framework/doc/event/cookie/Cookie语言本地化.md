# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework_Cookie::lang_local` 事件及其使用方法。该事件在设置Cookie语言本地化时触发，允许其他模块自定义语言本地化逻辑。

## 事件列表

### 1. Weline_Framework_Cookie::lang_local - Cookie语言本地化事件

#### 基本信息

- **事件名称**：`Weline_Framework_Cookie::lang_local`
- **事件类型**：Cookie事件
- **触发时机**：在设置Cookie语言本地化时
- **触发位置**：`app/code/Weline/Framework/Http/Cookie.php` 第 90 行
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework_Cookie::lang_local` 事件在设置Cookie语言本地化时触发，允许其他模块自定义语言本地化逻辑。可以修改 `lang_local` 字段来改变语言本地化值。

该事件主要用于：
- 自定义语言本地化逻辑
- 语言代码映射
- 语言验证
- 多语言支持

#### 触发时机

```php
// app/code/Weline/Framework/Http/Cookie.php
$data = new DataObject();
$data->setData('lang', self::getLang());
$data->setData('currency', self::getCurrency());
$data->setData('lang_local', self::getLang());
ObjectManager::getInstance(EventsManager::class)->dispatch('Weline_Framework_Cookie::lang_local', $data);
return $data->getData('lang_local');
```

#### 使用场景

- **语言映射**：将语言代码映射到本地化代码
- **语言验证**：验证语言代码是否有效
- **多语言支持**：支持多语言本地化

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework_Cookie::lang_local">
        <observer name="Your_Module::custom_lang_local"
                  instance="Your\Module\Observer\CustomLangLocalObserver"
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

class CustomLangLocalObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $lang = $event->getData('lang');
        $langLocal = $event->getData('lang_local');
        
        // 自定义语言本地化逻辑
        $customLangLocal = $this->getCustomLangLocal($lang);
        if ($customLangLocal) {
            $event->setData('lang_local', $customLangLocal);
        }
    }
    
    private function getCustomLangLocal(string $lang): ?string
    {
        // 语言本地化逻辑
        return null;
    }
}
```

#### 事件数据

`Weline_Framework_Cookie::lang_local` 事件传递的数据：

| 字段 | 类型 | 说明 |
|------|------|------|
| lang | string | 语言代码 |
| currency | string | 货币代码 |
| lang_local | string | 语言本地化代码，可以修改此值 |

**获取和修改数据**：

```php
$lang = $event->getData('lang');
$currency = $event->getData('currency');
$langLocal = $event->getData('lang_local');

// 修改语言本地化
$event->setData('lang_local', $customLangLocal);
```

#### 注意事项

1. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
2. **覆盖行为**：后执行的观察者可以覆盖先执行的观察者的设置

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/Http/Cookie.php`（第 90 行）

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework_Cookie::lang_local` 事件文档

