# Weline Ai 模块 - 事件文档

## 概述

本文档详细说明了 Weline Ai 模块提供的 `Weline_Ai::ai_monitoring_alert` 事件及其使用方法。该事件在AI模型监控触发告警时触发。

## 事件列表

### 1. Weline_Ai::ai_monitoring_alert - AI监控告警事件

#### 基本信息

- **事件名称**：`Weline_Ai::ai_monitoring_alert`
- **事件类型**：AI监控事件
- **触发时机**：在AI模型监控触发告警时
- **触发位置**：`app/code/Weline/Ai/Service/MonitoringService.php` 第 257 行
- **配置文件**：`app/code/Weline/Ai/etc/event.xml`

#### 功能说明

`Weline_Ai::ai_monitoring_alert` 事件在AI模型监控触发告警时触发，允许其他模块监听并处理告警通知。事件数据包含告警信息、监控数据、模型代码、租户ID等。

该事件主要用于：
- 发送告警通知（短信、钉钉、飞书等）
- 记录告警日志
- 触发告警处理流程
- 告警数据分析

#### 触发时机

```php
// app/code/Weline/Ai/Service/MonitoringService.php
$eventData = [
    'alert' => $alert,
    'monitoring' => $monitoring,
    'model_code' => $monitoring->getData('model_code'),
    'tenant_id' => $monitoring->getData('tenant_id'),
];
$this->eventsManager->dispatch('Weline_Ai::ai_monitoring_alert', $eventData);
```

#### 使用场景

- **告警通知**：发送告警通知到短信、钉钉、飞书等渠道
- **日志记录**：记录告警日志到数据库或文件
- **告警处理**：触发告警处理流程，如自动修复、降级等
- **数据分析**：收集告警数据用于分析和统计

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Ai::ai_monitoring_alert">
        <observer name="Your_Module::Weline_Ai::ai_monitoring_alert"
                  instance="Your\Module\Observer\AiMonitoringAlertObserver"
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

class AiMonitoringAlertObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $alert = $data['alert'];
        $monitoring = $data['monitoring'];
        $modelCode = $data['model_code'];
        $tenantId = $data['tenant_id'];
        
        // 处理告警通知
        // 例如：发送短信、钉钉、飞书通知等
    }
}
```

#### 事件数据

`Weline_Ai::ai_monitoring_alert` 事件传递的数据：

```php
[
    'alert' => array,        // 告警信息数组
    'monitoring' => object,  // 监控数据对象
    'model_code' => string,  // 模型代码
    'tenant_id' => int,      // 租户ID
]
```

**数据说明**：
- `alert`：告警信息数组，包含告警级别、类型、消息等
- `monitoring`：监控数据对象，包含监控的详细信息
- `model_code`：AI模型代码
- `tenant_id`：租户ID

#### 注意事项

1. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
2. **性能考虑**：该事件在告警触发时都会触发，应避免执行耗时操作
3. **错误处理**：观察者应妥善处理异常，避免影响其他观察者的执行

#### 相关文件

- **事件配置**：`app/code/Weline/Ai/etc/event.xml`
- **事件定义**：`app/code/Weline/Ai/event.php`
- **触发位置**：`app/code/Weline/Ai/Service/MonitoringService.php`（第 257 行）

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Ai::ai_monitoring_alert` 事件文档

