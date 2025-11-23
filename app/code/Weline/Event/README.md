# Weline Event 事件管理模块

## 模块概述

Weline Event 是 Weline Framework 的事件管理模块，用于在管理后台中查看和管理系统中所有事件的详细信息。该模块提供了完整的事件浏览、搜索、统计功能，帮助开发者快速了解系统中事件的定义、观察者关系等信息。

## 主要功能

### 1. 事件列表展示
- 显示系统中所有已注册的事件
- 展示事件的基本信息（事件名、显示名、描述、定义模块等）
- 显示事件的规约和文档状态
- 显示事件的观察者数量和来源模块
- 支持点击查看事件详情

### 2. 事件详情查看
- 显示事件的完整信息
- 显示事件被哪个模块定义
- 显示所有观察者信息，按模块分组
- 显示观察者的详细信息（名称、实例类、排序、状态、共享设置等）
- 显示事件的文档路径和规约状态

### 3. 事件搜索功能
- 支持按事件名、显示名搜索
- 支持按描述搜索
- 支持按模块搜索（定义模块或观察者模块）
- 支持按观察者类名搜索
- 支持多种搜索类型组合
- 显示匹配原因，方便快速定位

### 4. 事件统计信息
- 总事件数统计
- 有规约的事件数量
- 有文档的事件数量
- 有观察者的事件数量
- 无观察者的事件数量
- 总观察者数量
- 定义事件的模块统计
- 有观察者的模块统计

### 5. 注册表管理
- 支持手动刷新事件注册表
- 重新扫描所有模块的事件规约文件
- 更新 `generated/events.php` 文件

## 安装和使用

### 安装

模块已包含在 Weline Framework 中，无需额外安装。确保模块已正确注册：

```bash
php bin/w module:upgrade Weline_Event
```

### 访问

在管理后台中，导航到：
```
开发者工具 > 事件管理 > 事件列表
```

或直接访问：
```
/admin/event/backend/event/index
```

## 功能详解

### 事件列表页面

**路径**: `/admin/event/backend/event/index`

**功能**:
- 展示所有事件的列表
- 显示统计信息卡片
- 支持点击事件名查看详情
- 支持快速搜索和刷新注册表

**显示内容**:
- 事件名（代码格式）
- 显示名（中文名称）
- 定义模块
- 规约/文档状态（有/无）
- 观察者数量和来源模块数
- 操作按钮（查看详情）

### 事件详情页面

**路径**: `/admin/event/backend/event/detail/event/{eventName}`

**功能**:
- 显示事件的完整信息
- 按模块分组显示观察者
- 显示观察者的详细配置

**显示内容**:
- 事件基本信息（名称、描述、定义模块、文档路径）
- 规约和文档状态
- 观察者统计（总数、模块数）
- 观察者列表（按模块分组）
  - 观察者名称
  - 实例类名
  - 排序值
  - 启用/禁用状态
  - 共享设置

### 事件搜索页面

**路径**: `/admin/event/backend/event/search`

**功能**:
- 支持多种搜索类型
- 显示匹配原因
- 支持快速跳转到详情

**搜索类型**:
- **全部**: 在所有字段中搜索
- **事件名**: 仅在事件名和显示名中搜索
- **描述**: 仅在事件描述中搜索
- **模块**: 在定义模块和观察者模块中搜索
- **观察者**: 在观察者类名和名称中搜索

### 刷新注册表

**路径**: `/admin/event/backend/event/refresh`

**功能**:
- 重新扫描所有模块的 `event.php` 文件
- 更新 `generated/events.php` 注册表文件
- 刷新后自动跳转到事件列表

**使用场景**:
- 添加了新的事件定义后
- 修改了事件规约文件后
- 需要更新事件注册表时

## 数据结构

### 事件信息结构

```php
[
    'name' => '事件显示名',
    'description' => '事件描述',
    'doc' => '文档文件名',
    'doc_path' => '文档路径',
    'has_spec' => true,  // 是否有规约文件
    'has_doc' => true,   // 是否有文档文件
    'module' => 'Weline_Framework',  // 定义该事件的模块
    'modules' => [...],  // 提供该事件的模块列表
    'observers' => [...],  // 观察者列表
    'observers_by_module' => [...],  // 按模块分组的观察者
    'observer_count' => 5,  // 观察者总数
    'observer_modules' => ['Weline_Admin', 'Weline_Module']  // 观察者来源模块
]
```

### 观察者信息结构

```php
[
    'name' => '观察者名称',
    'instance' => 'Weline\Admin\Observer\SomeObserver',  // 观察者类名
    'disabled' => 'false',  // 是否禁用
    'shared' => 'true',     // 是否共享实例
    'sort' => 100           // 排序值
]
```

## API 使用

### EventDataService

该模块提供了 `EventDataService` 服务类，可以在其他模块中使用：

```php
use Weline\Event\Service\EventDataService;
use Weline\Framework\Manager\ObjectManager;

// 获取服务实例
/** @var EventDataService $service */
$service = ObjectManager::getInstance(EventDataService::class);

// 获取所有事件
$allEvents = $service->getAllEvents();

// 获取单个事件详情
$eventDetail = $service->getEventDetail('Weline_Admin::msg');

// 获取事件统计
$stats = $service->getEventStats();

// 搜索事件
$results = $service->searchEvents('msg', 'name');

// 按模块获取事件
$moduleEvents = $service->getEventsByModule('Weline_Admin');

// 获取模块统计
$moduleStats = $service->getModuleStats('Weline_Admin');
```

### 主要方法

#### getAllEvents()
获取所有事件信息（包含观察者信息）

**返回**: `array` 事件数组，键为事件名，值为事件信息

#### getEventDetail(string $eventName)
获取单个事件的详细信息

**参数**:
- `$eventName` (string): 事件名

**返回**: `array|null` 事件信息数组，如果事件不存在返回 null

#### getEventStats()
获取事件统计信息

**返回**: `array` 统计信息数组

#### searchEvents(string $searchTerm, string $searchType = 'all')
搜索事件

**参数**:
- `$searchTerm` (string): 搜索关键词
- `$searchType` (string): 搜索类型（all|name|description|module|observer）

**返回**: `array` 匹配的事件数组

#### getEventsByModule(string $moduleName)
按模块筛选事件

**参数**:
- `$moduleName` (string): 模块名

**返回**: `array` 该模块定义或观察的事件数组

#### getModuleStats(string $moduleName)
获取模块统计信息

**参数**:
- `$moduleName` (string): 模块名

**返回**: `array` 模块统计信息

## 菜单配置

模块在管理后台的菜单配置位于：
```
app/code/Weline/Event/etc/backend/menu.xml
```

菜单结构：
- **事件管理** (父菜单)
  - **事件列表** - 显示所有事件
  - **事件搜索** - 搜索事件
  - **刷新注册表** - 刷新事件注册表

## 文件结构

```
app/code/Weline/Event/
├── Controller/
│   └── Backend/
│       └── Event.php          # 后端控制器
├── Service/
│   └── EventDataService.php   # 事件数据服务
├── etc/
│   └── backend/
│       └── menu.xml           # 菜单配置
├── view/
│   └── templates/
│       └── Backend/
│           └── Event/
│               ├── index.phtml    # 事件列表页面
│               ├── detail-content.phtml   # 事件详情内容（用于 offcanvas）
│               └── search.phtml   # 事件搜索页面
├── register.php                # 模块注册文件
└── README.md                   # 本文档
```

## 技术实现

### 数据来源

模块从以下位置读取事件信息：

1. **事件注册表**: `generated/events.php`
   - 包含所有事件的规约信息
   - 包含事件的定义模块信息
   - 包含事件的文档路径信息

2. **事件观察者配置**: 通过 `EventsManager` 读取
   - 从各模块的 `etc/event.xml` 文件中读取
   - 包含观察者的配置信息

### 核心依赖

- `Weline\Framework\Event\EventRegistry` - 事件注册表管理
- `Weline\Framework\Event\EventsManager` - 事件管理器
- `Weline\Framework\App\Controller\BackendController` - 后端控制器基类

## 使用场景

### 1. 开发调试
- 查看系统中所有可用的事件
- 了解事件的观察者关系
- 检查事件是否有规约和文档

### 2. 模块开发
- 查找需要监听的事件
- 了解事件的触发时机
- 查看事件的观察者实现

### 3. 系统维护
- 检查事件定义的完整性
- 统计事件使用情况
- 查找未使用的观察者

### 4. 文档编写
- 生成事件列表文档
- 查看事件的文档路径
- 检查文档完整性

## 注意事项

1. **注册表更新**: 添加或修改事件后，需要运行 `php bin/w event:rebuild` 或通过管理后台刷新注册表

2. **性能考虑**: 事件列表页面会加载所有事件信息，如果事件数量很大，可能需要优化

3. **权限控制**: 确保只有有权限的用户才能访问事件管理功能

4. **缓存问题**: 如果修改了事件配置，可能需要清除缓存才能看到最新结果

## 故障排查

### 问题：事件列表为空

**可能原因**:
1. 事件注册表文件不存在或为空
2. 没有定义任何事件

**解决方法**:
1. 运行 `php bin/w event:rebuild` 重建事件注册表
2. 检查是否有模块定义了事件

### 问题：观察者信息不显示

**可能原因**:
1. 事件配置文件格式错误
2. 观察者类不存在

**解决方法**:
1. 检查 `etc/event.xml` 文件格式
2. 确认观察者类路径正确

### 问题：搜索功能不工作

**可能原因**:
1. 搜索关键词为空
2. 搜索类型不匹配

**解决方法**:
1. 输入搜索关键词
2. 尝试使用"全部"搜索类型

## 更新日志

### v1.0.0 (2025-01-XX)
- 初始版本发布
- 实现事件列表展示
- 实现事件详情查看
- 实现事件搜索功能
- 实现事件统计功能
- 实现注册表刷新功能

## 相关文档

- [Weline Framework 事件系统文档](../../Framework/doc/event.md)
- [事件开发指南](../../Framework/doc/event-development.md)
- [Weline Extends 模块文档](../Extends/README.md) - 类似的扩展管理模块

## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个模块。

## 许可证

本模块遵循 Weline Framework 的许可证。

## 联系方式

- 作者: 秋枫雁飞
- 邮箱: aiweline@qq.com
- 网址: aiweline.com
- 论坛: https://bbs.aiweline.com

