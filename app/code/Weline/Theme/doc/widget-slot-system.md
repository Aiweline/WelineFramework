# 主题部件插槽系统

## 概述

主题部件插槽系统是一个完整的可视化页面构建解决方案，允许用户通过后台拖拽部件来构建页面布局。

## 核心设计理念

**插槽（Slot）是容器元素的属性**，而不是独立的标签或包裹元素：

1. **属性标记方式** - 使用 `data-wslot` 系列属性标记在现有 HTML 元素上，不添加额外的 DOM 元素
2. **零侵入性** - 不影响原有的 CSS 布局（flex、grid 等）
3. **编译时提取** - 后端在编译模板时提取所有带 `data-wslot` 属性的位置
4. **渲染时插入** - 真正渲染时将配置的部件插入到对应的插槽位置
5. **数据不丢失** - 如果旧的部件找不到对应的插槽，配置数据保留，只是提示用户无法生效

## 系统架构

```
┌─────────────────────────────────────────────────────────────────┐
│                        前端访问流程                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. 用户访问页面（如首页）                                        │
│           │                                                      │
│           ▼                                                      │
│  2. 框架加载布局模板（homepage/default.phtml）                   │
│           │                                                      │
│           ▼                                                      │
│  3. 模板编译 & 渲染（包含 hook、block 等）                       │
│           │                                                      │
│           ▼                                                      │
│  4. 触发 after_render 事件                                       │
│           │                                                      │
│           ▼                                                      │
│  5. LayoutSlotRenderer Observer 处理                             │
│      ├── 检测 data-wslot 属性的元素                              │
│      ├── 从数据库获取部件配置                                    │
│      ├── 渲染部件 HTML                                           │
│      ├── 填充到对应插槽                                          │
│      └── 检测孤儿部件（找不到插槽的部件）                        │
│           │                                                      │
│           ▼                                                      │
│  6. 返回最终 HTML                                                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                        后台编辑流程                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. 进入主题编辑器                                               │
│           │                                                      │
│           ▼                                                      │
│  2. 选择布局类型（首页/分类页/产品页等）                         │
│           │                                                      │
│           ▼                                                      │
│  3. iframe 加载编译后的布局（带编辑模式）                        │
│           │                                                      │
│           ▼                                                      │
│  4. 提取并显示所有插槽位置（data-wslot 元素）                    │
│           │                                                      │
│           ▼                                                      │
│  5. 用户从部件库拖拽部件到插槽                                   │
│           │                                                      │
│           ▼                                                      │
│  6. 保存部件配置到数据库（包含 slot_id）                         │
│           │                                                      │
│           ▼                                                      │
│  7. 刷新预览                                                     │
│           │                                                      │
│           ▼                                                      │
│  8. 发布主题（可选：生成静态缓存）                               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## 核心组件

### 1. Observer - LayoutSlotRenderer

**文件**: `app/code/Weline/Theme/Observer/LayoutSlotRenderer.php`

**作用**: 监听模板渲染后事件，处理插槽替换

**事件**: `Weline_Framework_Template::after_render`

```php
// 执行流程
public function execute(Event $event): void
{
    $html = $event->getData('html');
    
    // 检测是否包含插槽（基于属性标记）
    if (strpos($html, 'data-wslot') === false) {
        return;
    }
    
    // 处理插槽替换
    $processedHtml = $this->slotRenderer->processSlots($html, $themeId, $pageType);
    
    // 检查是否有孤儿部件（找不到插槽的部件）
    if ($this->slotRenderer->hasOrphanWidgets()) {
        // 记录或显示警告
        $orphans = $this->slotRenderer->getOrphanWidgets();
        // 这些部件的配置仍然保留在数据库中
    }
    
    // 更新 HTML
    $event->setData('html', $processedHtml);
}
```

### 2. Service - SlotRendererService

**文件**: `app/code/Weline/Theme/Service/SlotRendererService.php`

**作用**: 处理实际的插槽渲染逻辑

```php
// 核心方法
public function processSlots(string $html, int $themeId, string $pageType): string
{
    // 1. 获取布局配置
    $layoutData = $this->getLayoutData($themeId, $pageType);
    
    // 2. 按插槽组织部件
    $slotWidgets = $this->organizeWidgetsBySlot($layoutData);
    
    // 3. 使用 DOM 解析处理插槽
    // 4. 检测孤儿部件（配置了但找不到对应slot的部件）
    $html = $this->processSlotsWithDom($html, $slotWidgets);
    
    return $html;
}

// 提取模板中的所有可用插槽
public function extractSlots(string $html): array
{
    // 返回所有 data-wslot 元素的信息
}

// 获取孤儿部件
public function getOrphanWidgets(): array
{
    // 返回找不到对应slot的部件列表
}
```

### 3. 布局模板中的插槽定义（属性标记方式）

**示例**: `app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml`

插槽是容器元素的属性，不需要额外的包裹元素：

```html
<!-- Logo 区域 - 使用 data-wslot 属性标记 -->
<div class="header-logo" 
     data-wslot="logo" 
     data-wslot-name="Logo"
     data-wslot-accept="logo"
     data-wslot-exclusive="true">
    <a href="/" title="首页">
        <img src="/img/logo.png" alt="Logo">
    </a>
</div>

<!-- 用户区域 - 支持多个部件 -->
<div class="header-actions"
     data-wslot="user-area"
     data-wslot-name="用户区域"
     data-wslot-accept="account,mini-cart-icon,language-switcher,currency-switcher"
     data-wslot-multiple="true">
    <!-- 默认内容 -->
    <a href="/account">账户</a>
    <a href="/cart">购物车</a>
</div>
```

### 4. 插槽属性说明

| 属性 | 必需 | 说明 | 示例 |
|------|------|------|------|
| `data-wslot` | 是 | 插槽唯一标识 | `data-wslot="logo"` |
| `data-wslot-name` | 否 | 显示名称（编辑器用） | `data-wslot-name="Logo区域"` |
| `data-wslot-accept` | 否 | 接受的部件代码列表（逗号分隔） | `data-wslot-accept="logo,mini-logo"` |
| `data-wslot-exclusive` | 否 | Widget 替换整个内容 | `data-wslot-exclusive="true"` |
| `data-wslot-append` | 否 | Widget 追加到内容后 | `data-wslot-append="true"` |
| `data-wslot-prepend` | 否 | Widget 插入到内容前 | `data-wslot-prepend="true"` |
| `data-wslot-multiple` | 否 | 允许放置多个 widget | `data-wslot-multiple="true"` |

### 5. 渲染行为

| 模式 | 属性 | 行为 |
|------|------|------|
| 独占 | `data-wslot-exclusive="true"` | 清空元素内容，插入 widget |
| 追加 | `data-wslot-append="true"` | 保留原内容，widget 追加到末尾 |
| 前置 | `data-wslot-prepend="true"` | 保留原内容，widget 插入到开头 |
| 默认 | 无特殊属性 | 移除占位符后追加 widget |

### 6. 孤儿部件处理

当布局模板发生变化（如更换主题或修改模板），可能导致某些已配置的部件找不到对应的插槽：

```php
// 渲染后检查孤儿部件
$orphans = $slotRenderer->getOrphanWidgets();
foreach ($orphans as $orphan) {
    // $orphan 包含:
    // - slot_id: 配置的插槽ID
    // - widget_code: 部件代码
    // - widget_module: 部件模块
    // - message: 警告信息
}
```

**重要**：孤儿部件的配置数据**不会被删除**，只是无法在当前布局中显示。用户可以：
1. 在后台看到警告提示
2. 重新配置部件到新的插槽
3. 或者恢复包含该插槽的布局模板

## 数据库结构

### theme_layout 表

| 字段 | 类型 | 说明 |
|------|------|------|
| layout_id | int | 主键 |
| theme_id | int | 主题ID |
| page_type | varchar | 页面类型 |
| area | varchar | 区域 |
| slot_id | varchar | 插槽ID（新增） |
| widget_module | varchar | 部件模块 |
| widget_code | varchar | 部件代码 |
| widget_config | text | 部件配置(JSON) |
| sort_order | int | 排序 |

## 部件定义

### 部件属性

```php
[
    'name'        => 'Header Logo',
    'type'        => 'header',
    'code'        => 'logo',
    'slot'        => 'logo',      // 指定放入的插槽
    'position'    => ['header'],  // 允许放置的区域
    'template'    => 'Weline_Theme::...',
    'params'      => [...],       // 可配置参数
]
```

### 容器型部件

容器型部件可以包含其他部件，支持嵌套渲染（迭代式 DOM 处理）：

```php
[
    'name'         => 'Header 容器',
    'type'         => 'container',
    'code'         => 'header-container',
    'is_container' => true,
    'slots'        => [           // 定义内部插槽
        'logo' => [
            'name'   => 'Logo 插槽',
            'accept' => ['logo'],
            'max'    => 1,
        ],
        'search' => [...],
        'user-area' => [...],
    ],
]
```

**嵌套渲染**: 后端 `SlotRendererService` 使用迭代式多遍扫描处理嵌套插槽。容器部件渲染后生成的子插槽会在下一轮被发现和填充，最多支持 10 层嵌套深度。

## API 端点

| 端点 | 方法 | 说明 |
|------|------|------|
| `/theme/backend/theme-editor/layout-preview` | GET | 获取布局预览（iframe） |
| `/theme/backend/theme-editor/compile-layout` | GET | 编译布局获取插槽信息 |
| `/theme/backend/theme-editor/save-widget` | POST | 保存部件到插槽 |
| `/theme/backend/theme-editor/render-widget` | POST | 渲染单个部件预览 |
| `/theme/backend/theme-editor/publish` | POST | 发布主题 |

## 使用流程

### 1. 定义插槽

在布局模板中添加 `widget-slot-area` 元素。

### 2. 注册部件

在 `widget.php` 中定义部件，指定 `slot` 属性。

### 3. 后台配置

进入主题编辑器，拖拽部件到对应插槽。

### 4. 发布主题

保存配置，可选生成静态缓存。

### 5. 前端访问

访问页面时，Observer 自动处理插槽替换。

## 性能优化

### 开发模式

- Observer 实时处理
- 每次访问都从数据库读取配置

### 生产模式

- 发布时生成静态缓存页面
- 直接使用缓存，跳过 Observer 处理
- 配合全页缓存使用

## 缓存策略

```php
// 发布主题时生成缓存
$cacheGenerator->saveCompiledLayout($themeId, 'homepage', 'default', $html);

// 前端访问时优先使用缓存
if ($cacheGenerator->hasCompiledLayout($themeId, $layoutType)) {
    return $cacheGenerator->getCompiledLayout($themeId, $layoutType);
}
```

## 扩展开发

### 添加新的布局类型

1. 创建布局模板文件
2. 在模板中定义插槽
3. 更新后台编辑器的布局类型选项

### 添加新的部件

1. 在 `widget.php` 中添加部件定义
2. 创建部件模板文件
3. 指定 `slot` 属性
