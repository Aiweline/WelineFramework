# Widget Slot 属性标记系统

## 设计原则

新的 slot 系统采用**基于属性标记**的方式，不再添加额外的 DOM 包裹元素：

1. **零侵入性** - slot 属性直接标记在现有元素上，不影响原有 CSS 布局
2. **灵活性** - 任何 HTML 元素都可以成为 slot 容器
3. **向后兼容** - 系统同时支持旧的 `widget-slot-area` 类方式

## 属性规范

### 必需属性

| 属性 | 说明 | 示例 |
|------|------|------|
| `data-wslot` | 插槽ID，唯一标识 | `data-wslot="logo"` |

### 可选属性

| 属性 | 说明 | 默认值 | 示例 |
|------|------|--------|------|
| `data-wslot-name` | 显示名称（编辑器用） | 等于 slot ID | `data-wslot-name="Logo区域"` |
| `data-wslot-accept` | 接受的部件代码列表（逗号分隔） | 无限制 | `data-wslot-accept="logo,mini-logo"` |
| `data-wslot-exclusive` | Widget 替换整个内容 | `false` | `data-wslot-exclusive="true"` |
| `data-wslot-append` | Widget 追加到内容后 | `false` | `data-wslot-append="true"` |
| `data-wslot-prepend` | Widget 插入到内容前 | `false` | `data-wslot-prepend="true"` |
| `data-wslot-multiple` | 允许放置多个 widget | `false` | `data-wslot-multiple="true"` |

## 使用示例

### 基本用法 - 在现有元素上添加 slot

```html
<!-- Logo 区域 - 独占模式，widget 替换整个内容 -->
<div class="header-logo" 
     data-wslot="logo" 
     data-wslot-name="Logo"
     data-wslot-accept="logo"
     data-wslot-exclusive="true">
    <a href="/">默认 Logo</a>
</div>
```

### 多 Widget 模式

```html
<!-- 产品推荐区域 - 可放置多个产品部件 -->
<section class="homepage-products" 
         data-wslot="products" 
         data-wslot-name="产品推荐"
         data-wslot-accept="featured-products,new-arrivals,bestsellers"
         data-wslot-multiple="true">
    <!-- Widget 内容会追加到这里 -->
</section>
```

### 追加/前置模式

```html
<!-- 侧边栏 - widget 追加到现有内容后 -->
<aside class="sidebar" 
       data-wslot="sidebar" 
       data-wslot-name="侧边栏"
       data-wslot-append="true">
    <div class="sidebar-default-content">默认侧边栏内容</div>
    <!-- Widget 会追加到这里 -->
</aside>
```

## 渲染行为

| 模式 | 属性 | 行为 |
|------|------|------|
| 独占 | `data-wslot-exclusive="true"` | 清空元素内容，插入 widget |
| 追加 | `data-wslot-append="true"` | 保留原内容，widget 追加到末尾 |
| 前置 | `data-wslot-prepend="true"` | 保留原内容，widget 插入到开头 |
| 默认 | 无特殊属性 | 移除占位符后追加 widget |

## 编辑器集成

编辑模式下，系统会：
1. 为所有带 `data-wslot` 的元素添加高亮边框（hover 时显示）
2. 显示 `data-wslot-name` 作为标签
3. 支持拖放部件到 slot
4. 根据 `data-wslot-accept` 过滤显示的部件（只显示接受的部件）
5. 动态渲染的子插槽（容器部件内部的 `data-wslot`）会自动初始化交互事件

## 迁移指南

### 旧方式（不推荐）

```html
<div class="widget-slot-area" 
     data-slot-id="logo"
     data-slot-name="Logo"
     data-slot-position="header">
    <div class="actual-content">...</div>
</div>
```

### 新方式（推荐）

```html
<div class="actual-content" 
     data-wslot="logo"
     data-wslot-name="Logo">
    ...
</div>
```

**优势**：
- 移除了额外的包裹 div
- 不影响 flex/grid 布局
- 代码更简洁

## 完整属性示例

### 无限制插槽

```html
<!-- 主内容区：接受所有部件 -->
<section class="main-content"
         data-wslot="widget-main"
         data-wslot-name="主内容区"
         data-wslot-accept="*"
         data-wslot-multiple="true">
</section>
```
