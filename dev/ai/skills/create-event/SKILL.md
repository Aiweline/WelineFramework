---
name: create-event
description: |
  Creates events and observers in Weline Framework.
  
  MUST use when:
  - Creating events or observers
  - Using EventsManager::dispatch()
  - Registering event.xml observers
  - Inter-module communication
  
  Keywords: event, 事件, observer, 观察者, dispatch, 触发事件, event.php, event.xml, EventsManager, ObserverInterface, 事件命名规范, 事件规约, 创建事件, 监听事件, 事件通知
globs:
  - "**/event.php"
  - "**/Observer/**/*.php"
  - "**/etc/event.xml"
alwaysApply: false
---

# 事件系统技能

## 何时使用

- 创建新的事件
- 实现事件观察者
- 使用 `EventsManager::dispatch()` 触发事件
- 跨模块**通知型**通信（某事发生了、请响应这个动作）

## ⚠️ 事件 vs 查询器：优先使用查询器！

**模块间查询/获取数据时，禁止使用事件！必须使用 `QueryProviderInterface` 统一查询器！**

| 场景 | 正确做法 | 错误做法 |
|------|----------|----------|
| 从其他模块**读数据** | `FrameworkQueryService::execute(provider, operation)` | ❌ 创建事件 + 观察者 |
| 让其他模块**做 CRUD** | `FrameworkQueryService::execute(provider, operation)` | ❌ 创建事件 + 观察者 |
| 某事**发生后通知** | ✅ dispatch 事件 | - |
| 多模块需**协作响应** | ✅ dispatch 事件 + 多观察者 | - |

> 详见技能 `unified-query-provider`

---

## 1. 事件命名规范

### 标准格式

```
模块名::事件类型::事件名称
```

**事件类型**：
- `domain` - 领域事件（业务领域内）
- `integration` - 集成事件（跨模块/系统）
- `application` - 应用事件（应用层）

**示例**：
```
Weline_MediaManager::integration::supported_preview_formats
Weline_Seo::domain::subject_created
Weline_Admin::application::login_success
```

### 简化格式（兼容）

```
模块名::事件名称
```

**示例**：
```
Weline_Admin::msg
```

---

## 2. EventsManager::dispatch() 用法

### 方法签名

```php
public function dispatch(string $eventName, mixed &$data = []): static
```

### ⚠️ CRITICAL 规则

**1. dispatch 第二参数必须为变量（引用传递）**

```php
// ✅ 正确：使用变量
$eventData = ['data' => ['title' => '标题', 'content' => '内容']];
$eventsManager->dispatch('Weline_Admin::msg', $eventData);

// ❌ 错误：传递字面量数组
$eventsManager->dispatch('Weline_Admin::msg', ['data' => [...]]);
```

**2. 事件数据必须放在 `'data'` 键下**

```php
// ✅ 正确
$eventData = [
    'data' => [
        'title' => '标题',
        'content' => '内容',
    ]
];
$eventsManager->dispatch('Weline_Module::event_name', $eventData);

// Observer 中获取
public function execute(Event &$event): void
{
    $data = $event->getData('data');
    $title = $data['title'];
}
```

**3. 事件须在依赖它的代码之前触发**

```php
// ✅ 正确：先触发事件，再使用数据
$eventData = ['data' => ['formats' => []]];
$eventsManager->dispatch('Weline_MediaManager::integration::supported_preview_formats', $eventData);
$formats = $eventData['data']['formats'];  // Observer 可能已修改

// ❌ 错误：先使用数据，后触发事件
$formats = [];
$eventsManager->dispatch('...', $eventData);  // 太晚了
```

---

## 3. event.php 规约文件

**位置**：`app/code/Vendor/Module/event.php`

### 完整格式

```php
<?php
return [
    // 简单事件
    'Weline_Admin::msg' => [
        'name' => __('系统消息通知'),
        'description' => __('用于跨模块发送系统消息通知'),
        'doc' => '系统消息通知.md',  // 相对于 doc/event/ 目录
    ],
    
    // 完整事件定义
    'Weline_MediaManager::integration::supported_preview_formats' => [
        'name' => __('支持的预览格式'),
        'description' => __('允许其他模块注册可预览的文件格式'),
        'doc' => 'integration/supported_preview_formats.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'formats' => [
                'type' => 'array',
                'required' => true,
                'description' => 'MIME 类型数组（引用传递）',
            ],
        ],
    ],
    
    // 动态事件（使用 {} 占位符）
    'Framework_View::{position}' => [
        'name' => __('视图位置事件'),
        'description' => __('在指定位置触发的视图事件'),
    ],
];
```

---

## 4. event.xml 观察者注册

**位置**：`app/code/Vendor/Module/etc/event.xml`

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    
    <event name="Weline_Admin::msg">
        <observer 
            name="Weline_Admin::system_notification" 
            instance="Weline\Admin\Observer\SystemNotificationObserver" 
            disabled="false" 
            shared="true" 
            sort="0"/>
    </event>
    
    <!-- 多个观察者监听同一事件 -->
    <event name="Weline_Framework_App::backend_controller_init_after">
        <observer name="Weline_Admin::backend_controller_init_after" 
                  instance="Weline\Admin\Observer\BackendControllerInitAfter" 
                  sort="0"/>
        <observer name="Weline_Admin::menu_access_log" 
                  instance="Weline\Admin\Observer\MenuAccessLogObserver" 
                  sort="10"/>
    </event>
</config>
```

### 属性说明

| 属性 | 说明 | 默认值 |
|------|------|--------|
| `name` | 观察者唯一名称（格式：`模块名::观察者名`） | **必需** |
| `instance` | 观察者类的完整类名 | **必需** |
| `disabled` | 是否禁用 | `false` |
| `shared` | 是否共享实例 | `true` |
| `sort` | 执行顺序（数字越小越优先） | `10000` |

---

## 5. Observer 实现

### 接口

```php
interface ObserverInterface
{
    public function execute(Event &$event): void;
}
```

### 实现示例

```php
<?php
declare(strict_types=1);

namespace Weline\Admin\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SystemNotificationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 获取事件数据（使用 'data' 键）
        $data = $event->getData('data');
        
        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';
        
        // 处理业务逻辑...
        
        // 可以修改事件数据（会回写到调用方）
        $event->setData('result', 'success');
    }
}
```

---

## 6. 完整开发流程

### Step 1: 定义事件规约 (event.php)

```php
<?php
// app/code/Vendor/Module/event.php
return [
    'Vendor_Module::domain::order_created' => [
        'name' => __('订单创建事件'),
        'description' => __('订单创建后触发'),
        'type' => 'domain',
        'doc' => 'domain/order_created.md',
    ],
];
```

### Step 2: 触发事件

```php
// 在 Service 或 Controller 中
$eventData = [
    'data' => [
        'order_id' => $order->getId(),
        'order' => $order,
    ]
];
$this->eventsManager->dispatch('Vendor_Module::domain::order_created', $eventData);
```

### Step 3: 创建观察者类

```php
<?php
// app/code/Vendor/Module/Observer/OrderCreatedObserver.php
namespace Vendor\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderCreatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        $orderId = $data['order_id'];
        
        // 处理订单创建后逻辑
    }
}
```

### Step 4: 注册观察者 (event.xml)

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Vendor_Module::domain::order_created">
        <observer 
            name="Vendor_Module::order_created_handler" 
            instance="Vendor\Module\Observer\OrderCreatedObserver"/>
    </event>
</config>
```

### Step 5: 刷新缓存

```bash
php bin/w cache:clear
```

---

## 7. 事件类型对比

| 类型 | 用途 | 示例 |
|------|------|------|
| **Domain** | 业务领域内事件 | `order_created`, `product_saved` |
| **Integration** | 跨模块集成事件 | `feed_collect`, `task_enqueued` |
| **Application** | 应用层事件 | `login_success`, `cache_cleared` |

---

## 8. 常见错误

### 错误 1：dispatch 第二参数不是变量

```php
// ❌ 错误
$eventsManager->dispatch('Event::name', ['data' => []]);

// ✅ 正确
$eventData = ['data' => []];
$eventsManager->dispatch('Event::name', $eventData);
```

### 错误 2：数据未放在 'data' 键下

```php
// ❌ 错误
$eventData = ['order_id' => 123];

// ✅ 正确
$eventData = ['data' => ['order_id' => 123]];
```

### 错误 3：event.xml 注册但未 dispatch

如果在 `event.xml` 中注册了 observer，必须在代码中某处 dispatch 该事件，否则 observer 永远不会执行。

---

## 9. 相关文件

| 文件 | 位置 |
|------|------|
| 事件规约 | `模块/event.php` |
| 观察者注册 | `模块/etc/event.xml` |
| 观察者类 | `模块/Observer/*.php` |
| 事件文档 | `模块/doc/event/*.md` |
| 生成的事件配置 | `generated/events.php` |

---

**最后更新**: 2026-02-25
**版本**: 2.0.0
