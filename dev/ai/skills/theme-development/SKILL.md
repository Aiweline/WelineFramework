---
name: theme-development
description: |
  Theme/Frontend/CSS/JS development for Weline Framework. CRITICAL - All CSS MUST use theme variables!
  
  MUST use when:
  - Writing CSS styles (background, color, border, shadow, etc.)
  - Dark/light mode adaptation (暗色模式, 亮色模式, dark mode, light mode)
  - Developing frontend/backend themes
  - Creating UI components (组件, component, widget)
  - Loading JS modules
  - Using Toast notifications
  
  Keywords: CSS, 样式, style, 颜色, color, background, 背景, border, 边框, shadow, 阴影, 
  主题, theme, 前端, frontend, 后台, backend, 组件, component, widget, 部件,
  暗色, dark, 亮色, light, 模式, mode, CSS变量, CSS variable, var(--,
  JavaScript, JS, 闭包, closure, IIFE, 作用域, scope, 污染, pollution,
  .phtml, .css, .js, 模板, template, 视图, view, 样式表, stylesheet,
  card, table, form, input, button, modal, offcanvas, toast, alert
globs:
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
  - "**/view/**/*.css"
  - "**/theme/**/*"
  - "**/colors/**/*.css"
  - "**/variables/**/*.css"
  - "**/statics/**/*.css"
  - "**/statics/**/*.js"
alwaysApply: false
---

# Weline 主题开发技能 (Theme Development Skill)

## 何时使用此技能

**必须参考此技能的场景：**
- ✅ 开发主题相关功能（前台/后台）
- ✅ **编写 CSS 样式（必须使用主题变量！）**
- ✅ **适配暗色模式（dark mode）和亮色模式（light mode）**
- ✅ **开发 UI 组件（组件必须独立作用域！）**
- ✅ **编写 JavaScript（必须使用闭包！）**
- ✅ 加载 JavaScript 模块
- ✅ 生成主题 URL
- ✅ 调用后端 API
- ✅ 使用 Toast 通知/确认对话框
- ✅ 管理主题配置
- ✅ 静态资源路径解析

**相互参照：**
- 生成URL → 配合 `weline-routing` 技能
- 用户提示 → 配合 `friendly-notifications` 技能
- 模块开发 → 配合 `module-development` 技能

---

## ⚠️ 硬性禁止（违反必须修正）

### 1. 禁止私自定义颜色值

```css
/* ❌ 绝对禁止 - 硬编码颜色 */
.my-element { background: #fff; color: #333; border: 1px solid #dee2e6; }
.my-element { background: white; color: black; }
.my-element { background: rgb(255,255,255); }
.my-element { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

/* ✅ 必须使用主题变量 */
.my-element { 
    background: var(--backend-color-card-bg, #fff);
    color: var(--backend-color-text-primary, #333);
    border: 1px solid var(--backend-color-border-default, #dee2e6);
    box-shadow: var(--backend-shadow-sm);
}
```

### 2. 禁止全局 JavaScript

```javascript
/* ❌ 绝对禁止 - 全局变量/函数污染 */
var myData = [];
function handleClick() { ... }
let config = {};

/* ✅ 必须使用 IIFE 闭包 */
(function() {
    'use strict';
    var myData = [];
    function handleClick() { ... }
    // 如需暴露到全局，显式挂载到 window
    window.MyModule = { handleClick };
})();
```

### 3. 禁止通用 CSS 类名

```css
/* ❌ 绝对禁止 - 通用类名会污染全局 */
.card { ... }
.header { ... }
.item { ... }
.active { ... }

/* ✅ 必须使用组件前缀/命名空间 */
.weline-media-card { ... }
.store-form-header { ... }
.product-list-item { ... }
.my-component--active { ... }
```

---

## 0. 主题 CSS 变量系统 ⭐⭐⭐ 【强制遵守】

### 0.0 变量命名规范 ⭐⭐⭐ 【核心规则】

**后端和前端使用不同的变量命名前缀，禁止混用！**

| 区域 | 变量前缀 | 示例 | 定义位置 |
|------|----------|------|----------|
| **后端 (Backend)** | `--backend-color-*`<br>`--backend-spacing-*`<br>`--backend-font-*`<br>`--backend-border-*`<br>`--backend-shadow-*` | `--backend-color-primary`<br>`--backend-color-card-bg`<br>`--backend-spacing-md` | `Weline_Theme::theme/backend/variables/*.css`<br>`Weline_Theme::theme/backend/colors/*.css` |
| **前端 (Frontend)** | `--color-*`<br>`--spacing-*`<br>`--font-*` | `--color-primary`<br>`--color-bg-primary`<br>`--spacing-md` | `Weline_Theme::theme/frontend/variables/*.css`<br>`Weline_Theme::theme/frontend/colors/*.css` |

**禁止的错误写法：**

```css
/* ❌ 后端代码使用短变量名（前端格式）— 这些变量在后端不存在！ */
.my-backend-card {
    background: var(--card-bg);        /* 错误！后端没有 --card-bg */
    color: var(--text-color);           /* 错误！后端没有 --text-color */
    border: 1px solid var(--border-color); /* 错误！后端没有 --border-color */
}

/* ✅ 后端代码使用正确的后端变量名 */
.my-backend-card {
    background: var(--backend-color-card-bg, #fff);
    color: var(--backend-color-text-primary, #212529);
    border: 1px solid var(--backend-color-border-default, #dee2e6);
}
```

**后端变量文件结构：**

```
app/code/Weline/Theme/view/theme/backend/
├── colors/
│   ├── _default.css    # 默认/亮色主题颜色
│   ├── _dark.css       # 暗色主题颜色（通过 [data-layout-mode="dark"] 选择器生效）
│   └── _light.css      # 亮色主题颜色
└── variables/
    ├── _colors.css     # 颜色变量（:root 定义）
    ├── _spacing.css    # 间距变量
    ├── _borders.css    # 边框变量
    ├── _shadows.css    # 阴影变量
    └── _typography.css # 字体变量
```

**需要新增变量时：**
- 禁止在模块 CSS 中私自定义新变量
- 必须先询问开发者是否可以新增变量
- 如确需新增，应添加到 `Weline_Theme::theme/backend/variables/` 下的对应文件中

### 0.1 核心原则

**所有前端开发都是开发主题！** 必须遵守主题 CSS 变量规范，以支持暗色/亮色模式自动切换。

❌ **禁止硬编码颜色值：**
```css
/* 错误 - 硬编码颜色 */
.my-card {
    background: #fff;
    color: #333;
    border: 1px solid #dee2e6;
}
```

✅ **必须使用 CSS 变量：**
```css
/* 正确 - 使用主题变量 */
.my-card {
    background: var(--backend-color-card-bg, #fff);
    color: var(--backend-color-text-primary, #333);
    border: 1px solid var(--backend-color-border-default, #dee2e6);
}
```

### 0.2 主题变量文件位置

后台主题变量文件结构：

```
app/code/Weline/Theme/view/theme/backend/
├── colors/                          # 颜色主题
│   ├── _default.css                 # 默认主题颜色
│   ├── _dark.css                    # 暗色主题颜色
│   └── _light.css                   # 亮色主题颜色
├── variables/                       # 通用变量
│   ├── _colors.css                  # 颜色变量
│   ├── _spacing.css                 # 间距变量
│   ├── _borders.css                 # 边框变量
│   ├── _shadows.css                 # 阴影变量
│   └── _typography.css              # 排版变量
└── assets/css/theme.css             # 主题样式
```

### 0.3 颜色变量参考表

#### 品牌色 / 主色

| 变量名 | 用途 | 亮色默认值 | 暗色默认值 |
|--------|------|------------|------------|
| `--backend-color-primary` | 主品牌色 | #556ee6 | #556ee6 |
| `--backend-color-primary-rgb` | RGB格式（用于透明度） | 85, 110, 230 | 85, 110, 230 |
| `--backend-color-primary-hover` | 悬停状态 | #4857d4 | #4857d4 |
| `--backend-color-primary-bg-subtle` | 浅色背景 | rgba(85, 110, 230, 0.1) | rgba(85, 110, 230, 0.15) |

#### 文本色

| 变量名 | 用途 | 亮色默认值 | 暗色默认值 |
|--------|------|------------|------------|
| `--backend-color-text-primary` | 主文本 | #212529 | #e9ecef |
| `--backend-color-text-secondary` | 次要文本 | #6c757d | #adb5bd |
| `--backend-color-text-tertiary` | 三级文本 | #adb5bd | #6c757d |
| `--backend-color-text-inverse` | 反色文本（用于深色背景） | #ffffff | #212529 |

#### 背景色

| 变量名 | 用途 | 亮色默认值 | 暗色默认值 |
|--------|------|------------|------------|
| `--backend-color-bg-primary` | 主背景 | #ffffff | #1a1a1a |
| `--backend-color-bg-secondary` | 次要背景 | #f8f9fa | #2d2d2d |
| `--backend-color-bg-tertiary` | 三级背景 | #e9ecef | #3a3a3a |
| `--backend-color-bg-surface` | 卡片表面 | #ffffff | #2d2d2d |

#### 边框色

| 变量名 | 用途 | 亮色默认值 | 暗色默认值 |
|--------|------|------------|------------|
| `--backend-color-border-default` | 默认边框 | #dee2e6 | #495057 |
| `--backend-color-border-light` | 浅边框 | #e9ecef | rgba(255, 255, 255, 0.1) |
| `--backend-color-border-emphasis` | 强调边框 | #adb5bd | #6c757d |

#### 功能色

| 变量名 | 用途 | 亮色/暗色 |
|--------|------|-----------|
| `--backend-color-success` | 成功 | #34c38f |
| `--backend-color-danger` | 危险/错误 | #f46a6a |
| `--backend-color-warning` | 警告 | #f1b44c |
| `--backend-color-info` | 信息 | #50a5f1 |
| `--backend-color-success-bg-subtle` | 成功浅背景 | rgba(52, 195, 143, 0.1) |
| `--backend-color-danger-bg-subtle` | 危险浅背景 | rgba(244, 106, 106, 0.1) |
| `--backend-color-warning-bg-subtle` | 警告浅背景 | rgba(241, 180, 76, 0.1) |
| `--backend-color-info-bg-subtle` | 信息浅背景 | rgba(80, 165, 241, 0.1) |

#### 组件专用色

| 变量名 | 用途 |
|--------|------|
| `--backend-color-card-bg` | 卡片背景 |
| `--backend-color-card-border` | 卡片边框 |
| `--backend-color-table-bg` | 表格背景 |
| `--backend-color-table-bg-hover` | 表格行悬停 |
| `--backend-color-table-border` | 表格边框 |
| `--backend-color-input-bg` | 输入框背景 |
| `--backend-color-input-border` | 输入框边框 |
| `--backend-color-sidebar-bg` | 侧边栏背景 |
| `--backend-color-header-bg` | 头部背景 |

### 0.4 间距变量

```css
/* 基础间距 */
--backend-spacing-xs: 0.25rem;    /* 4px */
--backend-spacing-sm: 0.5rem;     /* 8px */
--backend-spacing-md: 1rem;       /* 16px */
--backend-spacing-lg: 1.5rem;     /* 24px */
--backend-spacing-xl: 2rem;       /* 32px */

/* 组件内边距 */
--backend-padding-card: 1.5rem;          /* 24px */
--backend-padding-table-cell: 0.75rem;   /* 12px */
--backend-padding-button: 0.5rem 1rem;   /* 8px 16px */
--backend-padding-input: 0.5rem 0.75rem; /* 8px 12px */
```

### 0.5 边框变量

```css
/* 边框圆角 */
--backend-border-radius-sm: 0.25rem;    /* 4px */
--backend-border-radius: 0.375rem;      /* 6px */
--backend-border-radius-md: 0.5rem;     /* 8px */
--backend-border-radius-lg: 0.75rem;    /* 12px */
--backend-border-radius-xl: 1rem;       /* 16px */
--backend-border-radius-pill: 50rem;    /* 圆角药丸 */

/* 组件边框 */
--backend-border-card: 1px solid var(--backend-color-border-default);
--backend-border-input: 1px solid var(--backend-color-border-default);
```

### 0.6 阴影变量

```css
/* 基础阴影 */
--backend-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
--backend-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
--backend-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
--backend-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);

/* 组件阴影 */
--backend-card-shadow: 0 0 13px 0 rgba(74, 53, 107, 0.05);
--backend-dropdown-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.175);

/* 焦点阴影 */
--backend-focus-ring: 0 0 0 0.15rem rgba(85, 110, 230, 0.25);
```

### 0.7 完整组件样式示例

#### 手风琴/折叠面板

```css
/* 手风琴容器 */
.my-accordion {
    margin-bottom: var(--backend-spacing-md, 20px);
}

/* 手风琴项 */
.my-accordion-item {
    background: var(--backend-color-card-bg, #fff);
    border-radius: var(--backend-border-radius-md, 8px);
    box-shadow: var(--backend-card-shadow, 0 2px 8px rgba(0,0,0,0.06));
    margin-bottom: var(--backend-spacing-md, 15px);
    overflow: hidden;
    border: 1px solid var(--backend-color-border-light, transparent);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.my-accordion-item:hover {
    border-color: var(--backend-color-border-default, #dee2e6);
}

/* 手风琴头部 */
.my-accordion-header {
    padding: var(--backend-spacing-md, 18px) var(--backend-spacing-lg, 24px);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
    transition: all 0.3s ease;
    background: var(--backend-color-card-bg, transparent);
}

.my-accordion-header:hover {
    background: var(--backend-color-bg-tertiary, #e9ecef);
}

.my-accordion-header h5 {
    margin: 0;
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--backend-color-text-primary, #212529);
}

/* 手风琴内容 */
.my-accordion-body {
    padding: var(--backend-spacing-lg, 24px);
    background: var(--backend-color-bg-primary, #fff);
    border-top: 1px solid var(--backend-color-border-light, #e9ecef);
}
```

#### 卡片组件

```css
.my-card {
    background: var(--backend-color-card-bg, #fff);
    border: 1px solid var(--backend-color-border-default, #dee2e6);
    border-radius: var(--backend-border-radius-md, 8px);
    padding: var(--backend-padding-card, 24px);
    box-shadow: var(--backend-card-shadow);
    transition: all 0.3s ease;
}

.my-card:hover {
    border-color: var(--backend-color-primary, #556ee6);
    box-shadow: 0 4px 16px rgba(var(--backend-color-primary-rgb, 85, 110, 230), 0.15);
}

.my-card-header {
    padding-bottom: var(--backend-spacing-sm, 12px);
    margin-bottom: var(--backend-spacing-md, 16px);
    border-bottom: 1px solid var(--backend-color-border-light, #e9ecef);
}

.my-card-title {
    color: var(--backend-color-text-primary, #212529);
    font-weight: 600;
}

.my-card-body {
    color: var(--backend-color-text-secondary, #6c757d);
}
```

#### 表格样式

```css
.my-table {
    width: 100%;
    background: var(--backend-color-table-bg, #fff);
}

.my-table th {
    background: var(--backend-color-bg-secondary, #f8f9fa);
    color: var(--backend-color-text-primary, #333);
    font-weight: 600;
    padding: var(--backend-padding-table-cell, 12px);
    border-bottom: 2px solid var(--backend-color-border-default, #dee2e6);
}

.my-table td {
    padding: var(--backend-padding-table-cell, 12px);
    color: var(--backend-color-text-primary, #333);
    border-bottom: 1px solid var(--backend-color-border-light, #e9ecef);
}

.my-table tr:hover td {
    background: var(--backend-color-table-bg-hover, #f8f9fa);
}
```

#### 表单输入框

```css
.my-input {
    width: 100%;
    padding: var(--backend-padding-input, 8px 12px);
    border: 2px solid var(--backend-color-border-default, #dee2e6);
    border-radius: var(--backend-border-radius, 6px);
    background: var(--backend-color-input-bg, #fff);
    color: var(--backend-color-text-primary, #333);
    transition: all 0.2s ease;
}

.my-input:focus {
    outline: none;
    border-color: var(--backend-color-primary, #556ee6);
    box-shadow: var(--backend-focus-ring, 0 0 0 3px rgba(85, 110, 230, 0.1));
}

.my-input::placeholder {
    color: var(--backend-color-text-placeholder, #adb5bd);
}
```

#### 空状态 / 提示

```css
.my-empty-state {
    text-align: center;
    padding: var(--backend-spacing-xl, 40px) var(--backend-spacing-md, 20px);
    color: var(--backend-color-text-tertiary, #999);
}

.my-empty-state i {
    font-size: 3rem;
    opacity: 0.3;
    margin-bottom: var(--backend-spacing-md, 15px);
}

/* 警告提示 */
.my-warning-hint {
    background: var(--backend-color-warning-bg-subtle, rgba(241, 180, 76, 0.1));
    border: 2px dashed var(--backend-color-warning, #f1b44c);
    border-radius: var(--backend-border-radius-lg, 12px);
    padding: var(--backend-spacing-xl, 40px);
    text-align: center;
}

.my-warning-hint i {
    color: var(--backend-color-warning, #f1b44c);
}

.my-warning-hint p {
    color: var(--backend-color-text-secondary, #6c757d);
}
```

### 0.8 暗色模式适配检查清单

编写样式时，检查以下项目确保暗色模式兼容：

- [ ] **背景色**：使用 `--backend-color-bg-*` 或 `--backend-color-card-bg`
- [ ] **文本色**：使用 `--backend-color-text-*`
- [ ] **边框色**：使用 `--backend-color-border-*`
- [ ] **阴影**：使用 `--backend-shadow-*` 或 `--backend-card-shadow`
- [ ] **间距**：使用 `--backend-spacing-*` 或 `--backend-padding-*`
- [ ] **圆角**：使用 `--backend-border-radius-*`
- [ ] **状态色**：使用 `--backend-color-success/danger/warning/info`
- [ ] **悬停效果**：使用变量而非硬编码颜色
- [ ] **焦点状态**：使用 `--backend-focus-ring`

---

## 0.9 CSS 命名空间规范 ⭐⭐⭐ 【强制】

**所有组件的 CSS 必须使用唯一前缀，避免样式污染其他组件。**

### 命名规范

1. **组件前缀**：`<模块名>-<组件名>-<元素>`
2. **BEM 风格**：`块__元素--修饰符`
3. **唯一 ID**：`#<模块名>-<组件名>-<实例>`

```css
/* ✅ 正确 - 使用组件前缀 */
.weline-media-container { ... }
.weline-media-grid { ... }
.weline-media-item { ... }
.weline-media-item--selected { ... }
.weline-media-item__thumbnail { ... }
.weline-media-item__title { ... }

/* ✅ 正确 - 模块级前缀 */
.store-form-card { ... }
.store-form-input { ... }
.store-form-button { ... }

/* ❌ 错误 - 通用类名会污染全局 */
.container { ... }
.grid { ... }
.item { ... }
.selected { ... }
```

### 组件样式模板

```css
/* ============================================
   组件：Weline Media Manager
   模块：Weline_MediaManager
   前缀：weline-media-
   ============================================ */

/* 容器 */
.weline-media-container {
    background: var(--backend-color-card-bg, #fff);
    border: 1px solid var(--backend-color-border-default, #dee2e6);
    border-radius: var(--backend-border-radius-md, 8px);
}

/* 网格布局 */
.weline-media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: var(--backend-spacing-md, 16px);
}

/* 项目 */
.weline-media-item {
    background: var(--backend-color-bg-secondary, #f8f9fa);
    border: 2px solid transparent;
    border-radius: var(--backend-border-radius, 6px);
    cursor: pointer;
    transition: all 0.2s ease;
}

.weline-media-item:hover {
    border-color: var(--backend-color-primary, #556ee6);
}

.weline-media-item--selected {
    border-color: var(--backend-color-primary, #556ee6);
    background: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.1));
}

/* 子元素 */
.weline-media-item__thumbnail {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
}

.weline-media-item__title {
    color: var(--backend-color-text-primary, #212529);
    font-size: 0.875rem;
    padding: var(--backend-spacing-xs, 4px);
}
```

---

## 0.10 JavaScript 闭包规范 ⭐⭐⭐ 【强制】

**所有组件的 JavaScript 必须使用 IIFE 闭包，避免全局变量污染。**

### 基础闭包模板

```javascript
/**
 * 组件：Weline Media Manager
 * 模块：Weline_MediaManager
 */
(function() {
    'use strict';
    
    // ========== 私有变量（不会污染全局） ==========
    var selectedItems = [];
    var config = {
        maxSelect: 10,
        allowedTypes: ['image/png', 'image/jpeg']
    };
    
    // ========== 私有函数 ==========
    function handleItemClick(e) {
        var item = e.currentTarget;
        var itemId = item.dataset.id;
        toggleSelection(itemId);
    }
    
    function toggleSelection(itemId) {
        var index = selectedItems.indexOf(itemId);
        if (index === -1) {
            selectedItems.push(itemId);
        } else {
            selectedItems.splice(index, 1);
        }
        updateUI();
    }
    
    function updateUI() {
        document.querySelectorAll('.weline-media-item').forEach(function(item) {
            var isSelected = selectedItems.includes(item.dataset.id);
            item.classList.toggle('weline-media-item--selected', isSelected);
        });
    }
    
    // ========== 初始化 ==========
    function init() {
        document.querySelectorAll('.weline-media-item').forEach(function(item) {
            item.addEventListener('click', handleItemClick);
        });
    }
    
    // ========== 公开 API（仅在必要时暴露） ==========
    window.WelineMediaManager = {
        init: init,
        getSelected: function() { return selectedItems.slice(); },
        clearSelection: function() { 
            selectedItems = []; 
            updateUI(); 
        }
    };
    
    // ========== DOM Ready ==========
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
```

### jQuery 组件模板

```javascript
/**
 * 组件：Store Form
 * 模块：WeShop_Store
 */
(function($) {
    'use strict';
    
    // 私有变量
    var $form = null;
    var isSubmitting = false;
    
    // 私有函数
    function validateForm() {
        // 验证逻辑
        return true;
    }
    
    function handleSubmit(e) {
        if (isSubmitting) return false;
        if (!validateForm()) {
            e.preventDefault();
            return false;
        }
        isSubmitting = true;
        showLoading();
    }
    
    // 初始化
    function init() {
        $form = $('#storeForm');
        $form.on('submit', handleSubmit);
    }
    
    // DOM Ready
    $(document).ready(init);
    
})(jQuery);
```

### 模板内嵌 JavaScript（.phtml）

```html
<script>
(function() {
    'use strict';
    
    // 从 PHP 获取配置（仅在闭包内使用）
    var config = <?= json_encode([
        'apiUrl' => $this->getBackendUrl('*/api/save'),
        'itemId' => $item->getId()
    ]) ?>;
    
    // 私有函数
    function saveItem(data) {
        fetch(config.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                BackendToast.success('保存成功');
            } else {
                BackendToast.error(result.message || '保存失败');
            }
        })
        .catch(function(error) {
            BackendToast.error('网络错误');
        });
    }
    
    // 事件绑定（在闭包内）
    document.getElementById('saveBtn').addEventListener('click', function() {
        saveItem({ id: config.itemId, name: document.getElementById('name').value });
    });
    
})();
</script>
```

### 禁止的写法

```javascript
/* ❌ 绝对禁止 - 全局变量 */
var selectedItems = [];
let config = {};
const API_URL = '/api/save';

/* ❌ 绝对禁止 - 全局函数 */
function handleClick() { ... }
function saveData() { ... }

/* ❌ 绝对禁止 - 未使用闭包的事件绑定 */
document.getElementById('btn').onclick = function() {
    // 这里的 this 和变量可能被污染
};

/* ❌ 绝对禁止 - 直接在全局暴露对象 */
MyModule = { ... }; // 没有 var/let/const

/* ✅ 正确 - 显式挂载到 window */
window.MyModule = { ... };
```

---

## 0.5 模板 .phtml 中禁止定义全局函数 ⭐⭐⭐ 【强制】

**模板可能被多次包含（同一请求多块、WLS 常驻进程多请求），禁止在模板中定义全局函数，否则会导致 `Cannot redeclare function` 并引发 Worker 崩溃。**

❌ **禁止：**
- 在模板中写 `function renderXxx() { ... }`（全局函数）
- 用 `if (!function_exists('renderXxx')) { function renderXxx() { ... } }` 等方式“打补丁”

✅ **正确做法：使用闭包**
- 递归渲染：`$renderXxx = function (...) use (&$renderXxx) { ... };`，调用处用 `$renderXxx(...)`
- 非递归辅助：`$helper = function (...) { ... };`，调用处用 `$helper(...)`

示例（递归菜单）：
```php
$renderMenuItem = function ($item, $depth = 0, $maxDepth = 3) use (&$renderMenuItem) {
    // ... 输出 <li>，若有 children 则 $renderMenuItem($child, $depth + 1, $maxDepth);
};
foreach ($menuItems as $item) {
    $renderMenuItem($item, 0, $maxDepth);
}
```

---

## 1. 模板标签系统 ⭐⭐⭐ 【重要参考】

Weline 框架提供丰富的模板标签，用于简化前端开发。所有标签支持以下语法格式：

- **成对标签**: `<tag>content</tag>` 或 `<w:tag>content</w:tag>`
- **自闭合标签**: `<tag/>` 或 `<tag attr="value"/>`
- **内联语法**: `@tag(content)` 或 `@tag{content}`

### 1.1 资源标签（静态资源加载）

| 标签 | 用途 | 示例 |
|------|------|------|
| `@static()` | 获取静态资源 URL | `@static(Weline_Theme::css/style.css)` |
| `<js>` | 加载 JS 文件 | `<js>Weline_Admin::js/app.js</js>` |
| `<css>` | 加载 CSS 文件 | `<css>Weline_Theme::css/theme.css</css>` |
| `<theme:js>` | 加载主题 JS | `<theme:js>frontend/assets/js/main.js</theme:js>` |
| `<theme:css>` | 加载主题 CSS | `<theme:css>frontend/assets/css/style.css</theme:css>` |

**示例：**

```html
<!-- 静态资源 URL（推荐用于 src/href 属性） -->
<link rel="stylesheet" href="@static(Weline_Theme::css/editor-mode.css)">
<script src="@static(Weline_Theme::js/editor-mode.js)"></script>
<img src="@static(Weline_Theme::images/logo.png)" alt="Logo">

<!-- JS/CSS 标签（生成完整标签） -->
<js>Weline_Admin::js/app.js</js>
<!-- 输出: <script src="/static/Weline_Admin/js/app.js"></script> -->

<css>Weline_Theme::css/theme.css</css>
<!-- 输出: <link href="/static/Weline_Theme/css/theme.css" rel="stylesheet"> -->
```

### 1.2 URL 生成标签

| 标签 | 用途 | 示例 |
|------|------|------|
| `<url>` | 生成前台 URL | `<url>catalog/product/view</url>` |
| `<frontend-url>` | 生成前台 URL（显式） | `<frontend-url>checkout/cart</frontend-url>` |
| `<backend-url>` | 生成后台 URL | `<backend-url>theme/backend/editor</backend-url>` |
| `<api>` | 生成 API URL | `<api>products/list</api>` |
| `<backend-api>` | 生成后台 API URL | `<backend-api>widgets/save</backend-api>` |

**内联 @ 标签（属性/标签内优先使用）：**

在 Taglib 属性、`<script>` 等位置，`<?= $this->getBackendUrl(...) ?>` 可能不会被解析（属性值作为字面量传入）。应使用静态 @ 标签，由模板编译期展开：

| @ 标签 | 用途 |
|--------|------|
| `@url('path')` | 前台 URL |
| `@frontend-url('path')` | 前台 URL |
| `@backend-url('path')` | 后台 URL |
| `@api('path')` | API URL |
| `@backend-api('path')` | 后台 API URL |

```html
<!-- ❌ Taglib 属性中不要用 PHP，会原样输出 -->
<w:theme:sse-terminal url="<?= htmlspecialchars($this->getBackendUrl('...')) ?>"/>

<!-- ✅ 用 @backend-url 在编译期展开 -->
<w:theme:sse-terminal url="@backend-url('blog/backend/post/trigger-ai-publish-sse')"/>

<!-- href、action、src 等属性同理 -->
<a href="@backend-url('admin/dashboard')">仪表盘</a>
<form action="@backend-url('module/controller/save')">...</form>
<script>var api = '@backend-api('api/data')';</script>
```

**示例：**

```html
<!-- 前台 URL -->
<a href="<url>catalog/category/view?id=5</url>">分类页</a>

<!-- 后台 URL -->
<form action="<backend-url>theme/backend/theme-editor/save</backend-url>" method="POST">

<!-- API URL -->
<script>
    const apiUrl = "<backend-api>theme/widgets</backend-api>";
    fetch(apiUrl).then(res => res.json());
</script>
```

### 1.3 控制流标签

| 标签 | 用途 | 属性 |
|------|------|------|
| `<if>` | 条件判断 | `condition="$var > 0"` |
| `<elseif/>` | 否则如果 | `condition="$var == 0"` |
| `<else/>` | 否则 | - |
| `<foreach>` | 循环遍历 | `name="items" item="item" key="key"` |
| `<for>` | 计数循环 | `start="0" end="10" step="1" item="i"` |
| `<switch>` | 多条件分支 | `value="$type"` |
| `<case/>` | 分支选项 | `value="option1"` |
| `<while>` | 条件循环 | `condition="$i < 10"` |

**示例：**

```html
<!-- 条件判断 -->
<if condition="$user">
    <p>欢迎，<var>$user.name</var></p>
<elseif condition="$guest"/>
    <p>欢迎，访客</p>
<else/>
    <p>请登录</p>
</if>

<!-- 内联语法 -->
@if{$count > 0 => <span>有 <var>$count</var> 条数据</span>}

<!-- 循环遍历 -->
<foreach name="products" item="product" key="index">
    <div class="product-item">
        <h3><var>$product.name</var></h3>
        <p>价格: <var>$product.price</var></p>
    </div>
</foreach>

<!-- 内联循环 -->
@foreach{$items as $item|<li><var>$item.name</var></li>}
```

### 1.4 变量输出标签

| 标签 | 用途 | 示例 |
|------|------|------|
| `<var>` | 输出变量 | `<var>$name</var>` |
| `{{$var}}` | 简写语法 | `{{$user.name}}` |
| `@var()` | 内联语法 | `@var($name)` |
| `<pp>` | 打印变量（调试） | `<pp>$data</pp>` |
| `<dd>` | dump 变量（调试） | `<dd>$object</dd>` |

**示例：**

```html
<!-- 变量输出 -->
<h1><var>$title</var></h1>
<h1>{{$title}}</h1>

<!-- 嵌套访问 -->
<p>用户名: <var>$user.profile.name</var></p>
<p>邮箱: {{$user.email}}</p>

<!-- 调试 -->
<pp>$config</pp>  <!-- print_r -->
<dd>$object</dd>  <!-- var_dump -->
```

### 1.5 内容与布局标签

| 标签 | 用途 | 示例 |
|------|------|------|
| `<template>` | 包含模板 | `<template>Weline_Admin::header.phtml</template>` |
| `<block>` | 渲染块组件 | `<block class="Vendor\Block\Demo" template="..."/>` |
| `<hook>` | 钩子点 | `<hook>Weline_Theme::header::before</hook>` |
| `<w:slot>` | 插槽定义 | `<w:slot id="content" name="内容区">...</w:slot>` |
| `<lang>` | 翻译文本 | `<lang>Hello World</lang>` |
| `<csrf>` | CSRF 令牌 | `<csrf/>` |
| `<message>` | 消息提示 | `<message/>` |

**示例：**

```html
<!-- 包含模板 -->
<template>Weline_Theme::partials/header.phtml</template>

<!-- 块组件 -->
<block class="Weline\Theme\Block\Header" template="Weline_Theme::block/header.phtml"/>

<!-- 钩子点（可被观察者响应） -->
<hook>Weline_Theme::frontend::header::before</hook>
<header>...</header>
<hook>Weline_Theme::frontend::header::after</hook>

<!-- 钩子带默认内容 -->
<w:hook>WeShop_Product::product::price
    <else/>
    <span class="price">{{$product.price}}</span>
</w:hook>

<!-- 插槽（用于主题编辑器） -->
<w:slot id="hero" name="首页轮播" accept="banner,carousel" position="content">
    <div class="slot-placeholder">拖拽部件到此处</div>
</w:slot>

<!-- 翻译 -->
<button><lang>Submit</lang></button>
<p>@lang(Welcome to our store)</p>

<!-- CSRF 令牌 -->
<form method="POST">
    <csrf/>
    <input type="submit" value="提交">
</form>
```

### 1.6 条件检查标签

| 标签 | 用途 | 示例 |
|------|------|------|
| `<empty>` | 变量为空时 | `<empty>$list|<p>暂无数据</p></empty>` |
| `<notempty>` | 变量非空时 | `<notempty>$list|循环内容</notempty>` |
| `<has>` | 变量存在时 | `<has>$key=>显示内容</has>` |

**示例：**

```html
<!-- 空值检查 -->
@empty{$products|<div class="empty">暂无产品</div>}

<!-- 非空检查 -->
@notempty{$products|<div class="product-list">...</div>}

<!-- 成对标签 -->
<empty name="$cart">
    <p>购物车为空</p>
</empty>
<notempty name="$cart">
    <p>购物车有 {{count($cart)}} 件商品</p>
</notempty>
```

### 1.7 主题模块扩展标签

以下是 `Weline_Theme` 模块提供的自定义标签：

| 标签 | 用途 | 示例 |
|------|------|------|
| `<w:slot>` | 可编辑插槽 | 定义主题编辑器可配置区域 |
| `<w:color-picker>` | 颜色选择器 | 表单颜色输入 |
| `<w:icon-picker>` | 图标选择器 | 图标选择输入 |
| `<w:tree-select>` | 树形选择 | 分类/层级选择 |
| `<w:search-select>` | 搜索选择 | 带搜索的下拉选择 |
| `<w:cascader>` | 级联选择 | 多级联动选择 |
| `<w:date-range-picker>` | 日期范围 | 日期区间选择 |
| `<w:tag-input>` | 标签输入 | 多标签输入 |

**插槽示例：**

```html
<!-- 定义可编辑插槽 -->
<w:slot 
    id="header-logo" 
    name="网站 Logo" 
    accept="logo" 
    exclusive="true"
    position="header">
    <!-- 默认内容（编辑器中显示） -->
    <div class="slot-placeholder">
        <i class="ri-image-line"></i>
        <span>Logo 区域</span>
    </div>
</w:slot>

<!-- 插槽属性说明：
    id         - 插槽唯一标识
    name       - 显示名称
    accept     - 接受的部件类型（逗号分隔）
    exclusive  - 是否排他（true=只能放一个部件）
    multiple   - 是否多选
    position   - 所属区域（header/content/footer/sidebar）
    append     - 新部件追加到末尾
    prepend    - 新部件插入到开头
-->
```

### 1.8 语法速查表

```html
<!-- ============ 变量输出 ============ -->
<var>$name</var>                    <!-- 标签语法 -->
{{$name}}                           <!-- 简写语法 -->
@var($name)                         <!-- 内联语法 -->

<!-- ============ 静态资源 ============ -->
@static(Module::path/file.css)      <!-- 获取 URL -->
<js>Module::path/file.js</js>       <!-- 加载 JS -->
<css>Module::path/file.css</css>    <!-- 加载 CSS -->

<!-- ============ URL 生成 ============ -->
<url>route/path</url>               <!-- 前台 URL -->
<backend-url>admin/path</backend-url>  <!-- 后台 URL -->
<api>api/endpoint</api>             <!-- API URL -->

<!-- ============ 条件判断 ============ -->
<if condition="$a > $b">A大</if>
@if{$a > $b => A大于B}
<if condition="$ok"><else/>失败</if>

<!-- ============ 循环遍历 ============ -->
<foreach name="items" item="item">...</foreach>
@foreach{$items as $item|<li>{{$item}}</li>}

<!-- ============ 模板包含 ============ -->
<template>Module::path/template.phtml</template>
<block class="Block\Class" template="Module::block.phtml"/>

<!-- ============ 翻译 ============ -->
<lang>需要翻译的文字</lang>
@lang(需要翻译的文字)

<!-- ============ 钩子 ============ -->
<hook>Module::hook::name</hook>
<w:hook>Module::hook<else/>默认内容</w:hook>

<!-- ============ 表单 ============ -->
<csrf/>                             <!-- CSRF 令牌 -->
<message/>                          <!-- 消息提示 -->
```

---

## 2. Weline 主题 JS 架构

### 1.1 核心对象：`window.Weline`

Weline 框架提供全局 `Weline` 对象，**前台和后台共享同一架构**，包含以下功能：

```javascript
// 全局对象结构（前后台通用）
window.Weline = {
    config: {},           // 运行时配置
    Api: {},             // API 模块代理
    Account: {},         // 账户模块
    i18n: {},            // 国际化
    Locale: {},          // 语言/货币切换
    Sidebar: {},         // 侧边栏管理（仅后台）
    
    // 方法
    load(),              // 加载模块
    declare(),           // 声明模块
    use(),               // 使用模块
    staticResourceResolver: StaticResourceResolver
};
```

### 1.2 前后台架构差异 ⚠️

虽然前后台共享同一 theme.js 架构，但在某些功能上有**重要区别**：

| 功能 | 前台 (Frontend) | 后台 (Backend) |
|------|----------------|----------------|
| **theme.js 路径** | `Weline_Theme::frontend/assets/js/theme.js` | `Weline_Theme::backend/assets/js/theme.js` |
| **URL 模块** | `url-frontend` (仅生成前台URL) | `url-backend` (可生成前台/后台/REST URL) |
| **URL 权限** | ❌ 禁止生成后台 URL | ✅ 可生成所有类型 URL |
| **跨区域访问** | ✅ 登录后可加载 `url-backend` | ❌ 后台不需要加载前台模块 |
| **侧边栏** | ❌ 无 `Weline.Sidebar` | ✅ 有 `Weline.Sidebar` |
| **Toast/Confirm** | ❌ 需自定义实现 | ✅ 内置 `BackendToast`、`BackendConfirm`（别名 `AdminToast`、`AdminConfirm`） |

**关键原则：**
- ✅ **前台 → 前台 URL**：允许，使用 `url-frontend` 模块
- ❌ **前台 → 后台 URL**：禁止，除非用户已登录并加载 `url-backend` 模块
- ✅ **后台 → 前台 URL**：允许，使用 `url-backend` 模块的 `getUrl()`
- ✅ **后台 → 后台 URL**：允许，使用 `url-backend` 模块的 `getBackendUrl()`
- ✅ **后台 → REST URL**：允许，使用 `url-backend` 模块的 `getBackendApiUrl()`

### 1.3 主题配置初始化

#### 后台配置（Backend）

```php
<!-- 在 backend head.phtml 中 -->
<script>
window.__WelineThemeConfig = <?= json_encode([
    'env' => [
        'WELINE_ENV' => \Weline\Framework\App\Env::getInstance()->getEnv(),
        'DEV' => DEV,
        'PROD' => PROD
    ],
    'baseUrl' => $this->getBaseUrl(),
    'currentLang' => $this->getCurrentLang(),
    'api' => [
        // 后台可以生成所有类型的 URL
        'saveWidget' => $this->getBackendUrl('theme/backend/theme-editor/save-widget'),
        'deleteWidget' => $this->getBackendUrl('theme/backend/theme-editor/remove-widget'),
        'frontendPreview' => $this->getUrl('catalog/product/view') // 前台URL
    ],
    'theme' => [
        'area' => 'backend',
        'themeId' => $themeId
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<!-- 加载后台 theme.js -->
<script src="<?= $this->getStaticUrl('Weline_Theme::backend/assets/js/theme.js') ?>"></script>
```

#### 前台配置（Frontend）

```php
<!-- 在 frontend head.phtml 中 -->
<script>
window.__WelineThemeConfig = <?= json_encode([
    'env' => [
        'WELINE_ENV' => \Weline\Framework\App\Env::getInstance()->getEnv(),
        'DEV' => DEV,
        'PROD' => PROD
    ],
    'baseUrl' => $this->getBaseUrl(),
    'currentLang' => $this->getCurrentLang(),
    'api' => [
        // 前台只能生成前台 URL
        'addToCart' => $this->getUrl('checkout/cart/add'),
        'productList' => $this->getUrl('catalog/product/list')
        // ❌ 禁止：'adminUrl' => $this->getBackendUrl('...')
    ],
    'theme' => [
        'area' => 'frontend',
        'isLoggedIn' => $this->isUserLoggedIn() // 是否登录
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<!-- 加载前台 theme.js -->
<script src="<?= $this->getStaticUrl('Weline_Theme::frontend/assets/js/theme.js') ?>"></script>
```

---

## 2. JavaScript 模块加载

### 2.1 静态资源路径解析

**模块路径格式：** `Vendor_Module::path/to/file.js`

**开发模式（DEV）：**
```javascript
// 输入
'Weline_Admin::js/app.js'

// 输出
'/Weline/Admin/view/statics/js/app.js'
```

**生产模式（PROD）：**
```javascript
// 输入
'Weline_Admin::js/app.js'

// 输出
'/static/Weline/Admin/js/app.js'
```

### 2.2 声明并加载模块

#### 方式 1：声明模块（推荐，PHP可解析翻译词）

```javascript
// 声明模块，按需加载（首次访问时才加载）
Weline.declare('api');
Weline.declare('account');

// 声明并立即加载
Weline.declare('api', true);

// 声明自定义路径的模块
Weline.declare('my-module', 'Weline_MyModule::js/custom.js');

// 声明并立即加载自定义模块
Weline.declare('my-module', true, 'Weline_MyModule::js/custom.js');
```

#### 方式 2：直接加载模块

```javascript
// 立即加载模块
Weline.load('api').then(() => {
    console.log('API 模块已加载');
});

// 加载多个模块
Weline.load(['api', 'account'], () => {
    console.log('所有模块已加载');
});

// 加载自定义路径
Weline.load('my-module', 'Weline_MyModule::js/custom.js');
```

#### 方式 3：HTML 属性自动加载

```html
<!-- 页面加载时自动加载模块 -->
<div data-weline-load="api,account">
    <!-- 当模块加载完成，会触发 weline-modules-loaded 事件 -->
</div>

<script>
document.querySelector('[data-weline-load]').addEventListener('weline-modules-loaded', (e) => {
    console.log('模块加载完成:', e.detail.modules);
});
</script>
```

### 2.3 使用已加载的模块

```javascript
// 使用 API 模块（自动按需加载）
Weline.Api.request('/backend/theme-editor/save-widget', {
    method: 'POST',
    body: JSON.stringify({ theme_id: 5 })
}).then(data => {
    console.log('保存成功:', data);
});

// 使用 Account 模块
const user = Weline.Account.getFrontendUser();
console.log('当前用户:', user);
```

---

## 3. URL 生成（与 weline-routing 技能配合）

### 3.1 在 PHP 中生成 URL

```php
// Controller 中
$saveUrl = $this->getUrl('theme/backend/theme-editor/save-widget');
$deleteUrl = $this->getBackendUrl('theme/backend/theme-editor/remove-widget');

// Observer / Service 中（注入 Url 服务）
use Weline\Framework\Http\Url;

public function __construct(Url $url) {
    $this->url = $url;
}

$apiUrl = $this->url->getBackendUrl('theme/backend/theme-editor/save-widget');
```

### 3.2 在 PHP 模板中传递给 JavaScript

**推荐方式：通过配置对象**

```php
<script>
window.__WelineThemeConfig = <?= json_encode([
    'api' => [
        'saveWidget' => $this->getBackendUrl('theme/backend/theme-editor/save-widget'),
        'updateConfig' => $this->getBackendUrl('theme/backend/theme-editor/update-config'),
        'deleteWidget' => $this->getBackendUrl('theme/backend/theme-editor/remove-widget')
    ]
]) ?>;
</script>
```

**在 JavaScript 中使用：**

```javascript
const apiUrl = Weline.config.api.saveWidget;

fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ theme_id: 5 })
});
```

### 3.3 动态生成 URL（前端）⚠️

#### 后台：可生成所有类型 URL

```javascript
// 后台 theme.js 自动加载 url-backend 模块
Weline.declare('url-backend', true, 'Weline_Framework::js/url-backend.js');

// 生成后台 URL
const backendUrl = window.getBackendUrl('theme/backend/theme-editor/save-widget', { theme_id: 5 });
// 输出: /backend/theme-editor/save-widget?theme_id=5

// 生成前台 URL
const frontUrl = window.getUrl('catalog/product/view', { id: 123 });
// 输出: /catalog/product/view?id=123

// 生成 REST API URL
const apiUrl = window.getBackendApiUrl('products', { limit: 10 });
// 输出: /api/backend/products?limit=10
```

#### 前台：仅生成前台 URL ❌

```javascript
// ❌ 前台禁止生成后台 URL（默认不加载 url-backend）

// ✅ 如果需要生成后台 URL，必须满足以下条件：
// 1. 用户已登录
// 2. 手动加载 url-backend 模块

if (Weline.config.theme.isLoggedIn) {
    // 手动加载后台 URL 模块
    Weline.declare('url-backend', true, 'Weline_Framework::js/url-backend.js');
    
    Weline.use('url-backend').then(() => {
        // 现在可以生成后台 URL
        const adminUrl = window.getBackendUrl('admin/dashboard');
        console.log('后台URL:', adminUrl);
    });
} else {
    console.error('❌ 禁止：前台未登录用户不能生成后台 URL');
}

// ✅ 前台正常使用：生成前台 URL（使用 url-frontend 模块）
// 通常通过 PHP 传递 URL，不需要前端动态生成
const productUrl = Weline.config.api.productList;
```

**前台 URL 生成最佳实践：**

```php
<!-- PHP 中生成所有需要的 URL -->
<script>
window.__WelineThemeConfig = {
    api: {
        // ✅ 推荐：PHP 生成，传递给 JS
        addToCart: "<?= $this->getUrl('checkout/cart/add') ?>",
        productList: "<?= $this->getUrl('catalog/product/list') ?>",
        wishlist: "<?= $this->getUrl('wishlist/index/add') ?>"
    }
};
</script>
```

```javascript
// JavaScript 中必须使用 Weline.Api
Weline.Api.request(Weline.config.api.addToCart, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ product_id: 123 })
}).then(function (response) {
    if (response.ok && response.data) {
        BackendToast.success('已加入购物车');
    } else {
        BackendToast.error(response.data?.msg || '操作失败');
    }
}).catch(function (err) {
    if (!err.maintenance) BackendToast.error(err.message || '请求失败');
});
```

---

## 4. API 请求规范

### 4.1 必须使用 Weline.Api 模块

**禁止**在业务代码中使用原生 `fetch()` 或 `$.ajax()`。所有 Ajax 请求必须通过 `Weline.Api.request`，以便：

- 维护模式自动感知并弹窗提示
- 404/5xx 友好错误提示
- 统一错误处理：请求级 `onError`/`onHttpError`（返回 `true` 即接管）、全局 `onHttpError`、默认 Toast；DEV 下完整错误暴露

详见：`Weline_Frontend::doc/Weline.Api使用指南.md`

```javascript
// 加载 API 模块（后台 head 通常已加载）
Weline.declare('api');

// POST 请求
Weline.Api.request('/backend/theme-editor/save-widget', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ theme_id: 5, widget_code: 'header-logo', config: { width: 200 } })
}).then(function (response) {
    var data = response.data;
    if (!response.ok) {
        BackendToast.error(data?.msg || '保存失败');
        return;
    }
    BackendToast.success(data?.msg || '保存成功');
}).catch(function (error) {
    if (!error.maintenance) BackendToast.error(error.message || '请求失败');
});

// GET 请求
Weline.Api.request('/backend/theme-editor/widgets?theme_id=5', { method: 'GET' })
    .then(function (response) {
        if (response.ok) {
            var list = response.data;
            // 使用 list
        }
    });
```

### 4.2 禁止使用原生 fetch/$.ajax

业务代码中**禁止**直接使用 `fetch()` 或 `$.ajax()`。两者无法感知维护模式与 404/5xx，也无法统一错误处理。若需静默请求（不自动 Toast），可使用 `Weline.Api.request(url, { silent: true })`。

---

## 5. 友好通知与确认对话框（与 friendly-notifications 技能配合）

### 5.1 后台 Toast 通知（BackendToast）⭐

**仅在后台可用**，前台需要自定义实现。

**文件位置：** `Weline_Theme::theme/backend/assets/js/backend-components.js`（已在 head.phtml 统一引入）

```javascript
// 成功提示（3秒自动消失）
BackendToast.success('保存成功');

// 警告提示
BackendToast.warning('请先选择主题');

// 错误提示
BackendToast.error('保存失败，请重试');

// 信息提示
BackendToast.info('数据加载中...');

// 自定义持续时间（5秒）
BackendToast.success('操作完成', 5000);

// 永不自动消失（duration = 0）
BackendToast.info('请等待处理完成...', 0);
```

> **向后兼容**：`AdminToast` 仍可使用（是 `BackendToast` 的别名），但**新代码推荐使用 `BackendToast`**。

### 5.2 后台确认对话框（BackendConfirm）⭐

**仅在后台可用**，前台需要自定义实现。

```javascript
// 基础确认对话框（返回 Promise）
BackendConfirm.show('确定要删除这个部件吗？').then(confirmed => {
    if (confirmed) {
        // 用户点击"确定"
        deleteWidget();
    } else {
        // 用户点击"取消"
        console.log('用户取消操作');
    }
});

// 自定义选项
BackendConfirm.show('此操作不可恢复，确定继续吗？', {
    title: '危险操作',
    confirmText: '删除',
    cancelText: '放弃',
    type: 'danger'  // warning, danger, info, success
}).then(confirmed => {
    if (confirmed) {
        performDangerousAction();
    }
});
```

> **向后兼容**：`AdminConfirm` 仍可使用（是 `BackendConfirm` 的别名），但**新代码推荐使用 `BackendConfirm`**。

### 5.3 前台通知实现

前台**没有内置** `BackendToast` 和 `BackendConfirm`，需要自定义实现：

```javascript
// 前台自定义 Toast
const FrontendToast = {
    container: null,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 80px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
            `;
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 3000) {
        this.init();
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            padding: 12px 18px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        `;
        this.container.appendChild(toast);
        
        if (duration > 0) {
            setTimeout(() => toast.remove(), duration);
        }
    },
    
    success(msg) { this.show(msg, 'success'); },
    error(msg) { this.show(msg, 'error'); }
};

// 使用
FrontendToast.success('添加到购物车成功');
```

### 5.4 禁止使用原生弹窗 ⚠️

**前后台都禁止使用原生弹窗：**

```javascript
// ❌ 禁止使用
alert('操作成功');
confirm('确定删除吗？');
prompt('请输入名称');

// ✅ 后台使用（推荐新名称）
BackendToast.success('操作成功');
BackendConfirm.show('确定删除吗？');

// ✅ 后台使用（兼容旧名称）
AdminToast.success('操作成功');
AdminConfirm.show('确定删除吗？');

// ✅ 前台使用（自定义实现）
FrontendToast.success('操作成功');
FrontendConfirm.show('确定删除吗？');
```

---

## 6. 完整示例

### 6.1 后台主题编辑器示例

**PHP 模板（head.phtml）：**

```php
<script>
window.__WelineThemeConfig = <?= json_encode([
    'env' => ['DEV' => DEV, 'PROD' => PROD],
    'api' => [
        'saveWidget' => $this->getBackendUrl('theme/backend/theme-editor/save-widget'),
        'deleteWidget' => $this->getBackendUrl('theme/backend/theme-editor/remove-widget'),
        'getWidgets' => $this->getBackendUrl('theme/backend/theme-editor/widgets')
    ],
    'theme' => [
        'themeId' => $themeId ?? 1,
        'area' => 'backend'
    ]
]) ?>;
</script>
<script src="<?= $this->getStaticUrl('Weline_Theme::backend/assets/js/theme.js') ?>"></script>
```

**JavaScript（theme-editor.js）：**

```javascript
(function() {
    'use strict';
    
    // 1. 声明需要的模块
    Weline.declare('api');
    
    // 2. 获取配置
    const config = Weline.config;
    const apiUrls = config.api;
    const themeId = config.theme.themeId;
    
    // 3. 保存部件
    function saveWidget(widgetCode, widgetConfig) {
        Weline.Api.request(apiUrls.saveWidget, {
            method: 'POST',
            body: JSON.stringify({
                theme_id: themeId,
                widget_code: widgetCode,
                config: widgetConfig
            })
        }).then(data => {
            if (data.success) {
                BackendToast.success('部件保存成功');
            } else {
                BackendToast.error('保存失败: ' + data.message);
            }
        }).catch(error => {
            BackendToast.error('请求失败，请检查网络');
        });
    }
    
    // 4. 删除部件（带确认）
    function deleteWidget(widgetId) {
        BackendConfirm.show('确定要删除此部件吗？此操作不可恢复。', {
            title: '确认删除',
            confirmText: '删除',
            cancelText: '取消'
        }).then(confirmed => {
            if (!confirmed) return;
            
            Weline.Api.request(apiUrls.deleteWidget, {
                method: 'POST',
                body: JSON.stringify({ layout_id: widgetId })
            }).then(data => {
                if (data.success) {
                    BackendToast.success('删除成功');
                    // 刷新页面或更新UI
                    location.reload();
                } else {
                    BackendToast.error('删除失败: ' + data.message);
                }
            });
        });
    }
    
    // 5. 加载部件列表
    function loadWidgets() {
        BackendToast.info('加载中...', 1000);

        Weline.Api.request(`${apiUrls.getWidgets}?theme_id=${themeId}`, {
            method: 'GET'
        }).then(data => {
            if (data.success) {
                renderWidgets(data.widgets);
            } else {
                BackendToast.error('加载失败');
            }
        });
    }
    
    // 6. 事件绑定
    document.addEventListener('DOMContentLoaded', () => {
        // 保存按钮
        document.querySelectorAll('[data-save-widget]').forEach(btn => {
            btn.addEventListener('click', function() {
                const widgetCode = this.dataset.widgetCode;
                const config = getWidgetConfig(widgetCode);
                saveWidget(widgetCode, config);
            });
        });
        
        // 删除按钮
        document.querySelectorAll('[data-delete-widget]').forEach(btn => {
            btn.addEventListener('click', function() {
                const widgetId = this.dataset.widgetId;
                deleteWidget(widgetId);
            });
        });
        
        // 初始加载
        loadWidgets();
    });
    
})();
```

### 6.2 Observer 中注入动态 JavaScript

**PHP Observer：**

```php
<?php

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;

class InjectThemeScript implements ObserverInterface
{
    private Url $url;
    
    public function __construct(Url $url)
    {
        $this->url = $url;
    }
    
    public function execute(Event &$event): void
    {
        $html = (string)$event->getData('content');
        
        // 生成正确的 URL
        $deleteOrphanUrl = htmlspecialchars(
            $this->url->getBackendUrl('theme/backend/theme-editor/remove-orphan-widgets')
        );
        
        $script = <<<JS
<script>
(function() {
    // 使用从 PHP 传递的 URL
    const deleteUrl = '{$deleteOrphanUrl}';
    
    window.removeOrphanWidgets = function(themeId, slotIds) {
        fetch(deleteUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme_id: themeId, slot_ids: slotIds })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                BackendToast.success('删除成功，页面即将刷新...');
                setTimeout(() => location.reload(), 1000);
            } else {
                BackendToast.error('删除失败: ' + data.message);
            }
        })
        .catch(() => {
            BackendToast.error('请求失败，请检查网络');
        });
    };
})();
</script>
JS;
        
        $html = str_replace('</body>', $script . '</body>', $html);
        $event->setData('content', $html);
    }
}
```

---

## 7. 最佳实践

### 7.1 区分前后台开发

✅ **正确做法：**
```javascript
// 后台代码（backend）
if (Weline.config.theme.area === 'backend') {
    // 可以使用 BackendToast、BackendConfirm（或兼容的 AdminToast、AdminConfirm）
    BackendToast.success('保存成功');
    
    // 可以生成所有类型 URL
    const adminUrl = window.getBackendUrl('admin/dashboard');
    const frontUrl = window.getUrl('catalog/product/view');
}

// 前台代码（frontend）
if (Weline.config.theme.area === 'frontend') {
    // 使用自定义 Toast
    FrontendToast.success('添加成功');
    
    // 只使用 PHP 传递的 URL
    const cartUrl = Weline.config.api.addToCart;
}
```

❌ **错误做法：**
```javascript
// 前台使用后台专属功能
BackendToast.success('成功'); // ❌ 前台没有 BackendToast/AdminToast

// 前台生成后台 URL
const adminUrl = window.getBackendUrl('admin/...'); // ❌ 未登录禁止
```

### 7.2 URL 生成

✅ **正确做法：**
```php
// PHP 中生成，传递给 JS
$apiUrl = $this->url->getBackendUrl('theme/backend/theme-editor/save-widget');

window.__WelineThemeConfig = {
    api: { saveWidget: "<?= $apiUrl ?>" }
};
```

❌ **错误做法：**
```javascript
// JS 中硬编码
const apiUrl = '/backend/theme-editor/save-widget'; // 错误！
```

### 7.2 模块声明

✅ **正确做法：**
```javascript
// 必须声明，方便 PHP 解析翻译词
Weline.declare('api');
Weline.declare('account');
```

❌ **错误做法：**
```javascript
// 直接使用未声明的模块
Weline.Api.request(...); // 可能工作，但不推荐
```

### 7.3 通知提示

✅ **正确做法：**
```javascript
// 推荐新名称
BackendToast.success('保存成功');
BackendConfirm.show('确定删除吗？').then(confirmed => { ... });

// 兼容旧名称（仍可使用）
AdminToast.success('保存成功');
AdminConfirm.show('确定删除吗？').then(confirmed => { ... });
```

❌ **错误做法：**
```javascript
alert('保存成功'); // 禁止使用原生弹窗
confirm('确定删除吗？'); // 禁止使用
```

### 7.4 错误处理

✅ **正确做法：**
```javascript
Weline.Api.request(url, options)
    .then(data => {
        if (data.success) {
            BackendToast.success(data.message);
        } else {
            BackendToast.error('操作失败: ' + data.message);
        }
    })
    .catch(error => {
        BackendToast.error('网络错误，请重试');
        console.error('API Error:', error);
    });
```

❌ **错误做法：**
```javascript
fetch(url).then(data => {
    // 没有错误处理
    updateUI(data);
});
```

---

### 7.5 前台生成后台 URL（特殊场景）

**仅在用户已登录的情况下允许：**

```php
<!-- PHP 判断用户是否登录 -->
<script>
window.__WelineThemeConfig = {
    theme: {
        area: 'frontend',
        isLoggedIn: <?= $this->isUserLoggedIn() ? 'true' : 'false' ?>
    }
};
</script>
```

```javascript
// JavaScript 中判断
if (Weline.config.theme.isLoggedIn) {
    // 手动加载后台 URL 模块
    Weline.declare('url-backend', true, 'Weline_Framework::js/url-backend.js');
    
    Weline.use('url-backend').then(() => {
        // 现在可以生成后台 URL（仅用于已登录用户的管理功能）
        const myAccountUrl = window.getBackendUrl('customer/account/index');
        console.log('我的账户URL:', myAccountUrl);
    });
} else {
    // 未登录用户：禁止生成后台 URL
    console.warn('❌ 用户未登录，无法生成后台 URL');
}
```

## 8. 常见问题

### Q1: 前台可以使用 BackendToast 吗？

**不可以。** `BackendToast` 和 `BackendConfirm`（及其别名 `AdminToast`、`AdminConfirm`）是后台专属工具，前台需要自定义实现。

参考 `friendly-notifications` 技能创建前台友好通知组件。

### Q2: 如何在自定义模块中使用 Weline.Api？

```javascript
// 1. 先声明模块
Weline.declare('api');

// 2. 等待模块加载
Weline.use('api').then((ApiModule) => {
    // 使用模块
    ApiModule.request('/my-api', { method: 'GET' });
});

// 或使用代理（自动加载）
Weline.Api.request('/my-api', { method: 'GET' });
```

### Q3: 前台为什么不能直接生成后台 URL？

**安全考虑：**

1. **权限隔离**：前台用户不应访问后台功能
2. **URL 泄露**：防止暴露后台管理路径
3. **架构清晰**：前后台职责分离

**解决方案：**
- ✅ 前台只使用 PHP 传递的 URL
- ✅ 登录用户可手动加载 `url-backend` 模块
- ❌ 不要在前台硬编码后台 URL

### Q4: 静态资源路径在开发和生产环境不一致？

使用 `StaticResourceResolver` 自动解析：

```javascript
const path = 'Weline_Admin::js/app.js';
const resolvedPath = Weline.staticResourceResolver.resolve(path);
// DEV: /Weline/Admin/view/statics/js/app.js
// PROD: /static/Weline/Admin/js/app.js
```

**Windows 注意事项（2026-02-13）**：
- 在 Windows 上做路径前缀匹配时，不能假设大小写一致（`E:/` vs `e:/`）
- 若使用大小写敏感匹配，`@static` 可能退化输出为裸 `/statics/...`
- 修复方式：路径比较在 Windows 下使用大小写不敏感匹配（见 `TraitTemplate::getUrlPath()`）

### Q5: 如何判断当前是前台还是后台？

```javascript
// 方式1：从配置获取
const area = Weline.config.theme.area;
if (area === 'backend') {
    // 后台逻辑
} else if (area === 'frontend') {
    // 前台逻辑
}

// 方式2：从 URL 路径判断
const isBackend = window.location.pathname.startsWith('/backend') || 
                  window.location.pathname.startsWith('/admin');
```

### Q6: 如何调试模块加载问题？

```javascript
// 开启调试模式
window.DEV = true;

// 检查模块是否已加载
console.log(Weline.loader.isModuleLoaded('api'));

// 查看配置
console.log(Weline.config);
```

### Q7: 主题预览报 `htmlspecialchars(): ... array given`？

**错误信息：**
```
TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, array given
```

**原因：**
部件/组件配置值可能是数组，模板直接调用 `htmlspecialchars()` 会触发类型错误。

**解决方案：**
在模板中对配置值做类型归一化，非 string/number 直接回退默认值：

```php
$normalize = static function ($value, string $default = ''): string {
    if (is_string($value) || is_numeric($value)) {
        return (string)$value;
    }
    return $default;
};

$title = $normalize($this->getData('title'), __('推荐产品'));
$columns = $normalize($this->getData('columns'), '4');
$layout = $normalize($this->getData('layout'), 'grid');
```

**实际案例（2026-02-08）：**
ThemeEditor 预览 `featured-products` 组件时触发 TypeError，已按上述方式修复。

### Q8: 组件预览报 `Value of type null is not callable`？

**错误信息：**
```
Value of type null is not callable
```

**原因：**
模板使用 `$getConfig(...)` 读取配置，但渲染上下文未注入 `$getConfig`。

**解决方案：**
在渲染上下文注入 `$getConfig` 闭包，或直接使用 `$component_config` 读取配置。

**实际案例（2026-02-08）：**
PageBuilder 组件预览时 `$getConfig` 未注入导致失败，已在渲染服务统一注入。

### Q9: 首次安装报 `父主题：default 不存在！`？

**错误信息：**
```
父主题：default 不存在！
```

**原因：**
主题扩展（`type=theme`）在升级流程中属于延后安装项。若子主题先执行，`parent` 对应父主题尚未写入数据库，就会报错。

**解决方案：**
在主题安装器内按 `parent` 做依赖排序并分批安装：

```php
// 1) register() 仅入队
self::$themeInstallQueue[$module_name] = [$type, $module_name, $param, $version, $description];

// 2) 队列内按 parent 拓扑排序
$sorted = $this->sortThemeQueueByParent(array_values(self::$themeInstallQueue));

// 3) 只安装“父主题已存在”的主题
if ($this->canInstallThemeNow($args)) {
    $this->installTheme(...$args);
}
```

并兼容默认主题别名：`default` ↔ `Default 默认主题`。

**实际案例（2026-02-13）：**
`app/design/Weline/test/register.php` 配置 `parent=default`，首次安装时因父主题未先装导致异常，已通过安装器队列+依赖排序修复。

### Q10: AI 组件工坊点击“开始生成”没反应？

**常见原因：**

1. **步骤1字段未完整校验**：名称/描述为空但没有可见反馈。  
2. **按钮缺少加载态**：请求已发起但用户体感“无响应”。  
3. **弹层层级冲突**：提示或交互控件被更高层遮挡（modal/fullscreen/backdrop 混用）。

**推荐修复：**

```javascript
// 1) 提交前统一收集 + 校验
const params = collectStep1Params();
if (!params.ok) {
    BackendToast.warning(params.message);
    params.firstInvalid?.focus();
    return;
}

// 2) 提交时设置加载态，结束后恢复
setStartButtonLoading(true);
try {
    startGenerate(params.data);
} finally {
    setStartButtonLoading(false);
}
```

```css
/* 3) 统一弹层 z-index 号段，避免互相遮挡 */
.ai-workshop-modal { z-index: 200010 !important; }
body:has(.ai-workshop-modal.show) .modal-backdrop { z-index: 200009 !important; }
.ai-fullscreen-preview-overlay { z-index: 200050; }
```

**实际案例（2026-03-08）：**
`GuoLaiRen_PageBuilder` 的 `component_panel.phtml` 已按上述方式修复“开始生成无响应 + 层级错乱”问题。

---

## 9. 相关技能

- **error-learning** - **自动学习**：遇到主题开发错误时自动调用
- **weline-routing** - URL 路由规范和 URL 生成
- **friendly-notifications** - 友好通知UI（替代 alert/confirm）
- **module-development** - 模块开发工作流
- **code-generation-standards** - 代码生成标准
- **error-tracking** - 错误跟踪和记录

---

## 10. 参考文件

- 核心 theme.js: `app/code/Weline/Theme/view/theme/backend/assets/js/theme.js`（Weline 对象、模块加载器）
- **后台全局组件**: `app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js`（BackendToast、BackendConfirm）
- 后台 head 引入: `app/code/Weline/Admin/view/templates/common/head.phtml`（统一加载全局 JS）
- 主题编辑器: `app/code/Weline/Theme/view/statics/js/theme-editor.js`
